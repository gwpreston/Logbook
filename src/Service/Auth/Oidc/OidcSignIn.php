<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\AbsoluteUrl;
use Logbook\Support\Security\SafeRedirect;
use Logbook\Support\Session\Session;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The authorization code flow (spec.md §7.9 *Flow*): starting it stores
 * `state`, `nonce`, the PKCE verifier and where to return in the session;
 * the callback takes that back once (10 minutes), exchanges the code,
 * validates the ID token and hands the claims to OidcUsers. Failures are
 * logged with their reason and answered with a generic outcome.
 */
final readonly class OidcSignIn
{
    public const int STATE_TTL_SECONDS = 600;
    /** Pending flows kept per session (several tabs); the oldest go first. */
    private const int MAX_PENDING = 5;
    private const string SESSION_KEY = '_oidc_flows';

    public function __construct(
        private AppSettings $settings,
        private Discovery $discovery,
        private OidcClient $client,
        private TokenValidator $validator,
        private OidcUsers $users,
        private AbsoluteUrl $urls,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->oidc->isConfigured();
    }

    public function redirectUri(): string
    {
        return $this->urls->route('oidc.callback');
    }

    /**
     * The provider's authorization URL, or null when discovery fails (logged).
     */
    public function begin(Session $session, OidcPurpose $purpose, ?string $next, ?int $userId = null): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $metadata = $this->discovery->metadata();
        } catch (OidcFailure $e) {
            $this->logger->warning('Single sign-on is unavailable: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }

        $state = self::random();
        $nonce = self::random();
        $verifier = self::random();
        $flows = $this->pending($session);
        $flows[$state] = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'next' => SafeRedirect::localPath($next, $this->settings->basePath),
            'purpose' => $purpose->value,
            'user_id' => $userId,
            'created_at' => $this->clock->now()->getTimestamp(),
        ];
        $session->set(self::SESSION_KEY, array_slice($flows, -self::MAX_PENDING, null, true));

        return $this->client->authorizationUrl($metadata, $this->redirectUri(), $state, $nonce, $verifier);
    }

    /**
     * The purpose of the flow this callback answers, without using it up
     * (the callback page needs it to know where failures go).
     *
     * @param array<array-key, mixed> $query
     */
    public function purposeOf(Session $session, array $query): OidcPurpose
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $flow = $this->pending($session)[$state] ?? null;

        return OidcPurpose::tryFrom(is_array($flow) && is_string($flow['purpose'] ?? null) ? $flow['purpose'] : '')
            ?? OidcPurpose::SignIn;
    }

    /**
     * @param array<array-key, mixed> $query the callback's query parameters
     */
    public function complete(Session $session, array $query): OidcResult
    {
        try {
            return $this->finish($session, $query);
        } catch (OidcFailure $e) {
            $this->logger->notice('Single sign-on failed: {reason}', ['reason' => $e->getMessage()]);

            return OidcResult::failed();
        }
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function finish(Session $session, array $query): OidcResult
    {
        if (!$this->isConfigured()) {
            throw new OidcFailure('A callback arrived but OIDC_ISSUER is not set.');
        }
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $flows = $this->pending($session);
        $flow = $flows[$state] ?? null;
        if ($state === '' || !is_array($flow)) {
            throw new OidcFailure('The callback\'s state is unknown or already used.');
        }
        // Single use, whatever happens next.
        unset($flows[$state]);
        $flows === [] ? $session->remove(self::SESSION_KEY) : $session->set(self::SESSION_KEY, $flows);

        $created = is_int($flow['created_at'] ?? null) ? $flow['created_at'] : 0;
        if ($this->clock->now()->getTimestamp() - $created > self::STATE_TTL_SECONDS) {
            throw new OidcFailure('The sign-in took longer than 10 minutes (state expired).');
        }
        if (isset($query['error'])) {
            throw new OidcFailure(sprintf(
                'The provider answered "%s": %s',
                is_string($query['error']) ? $query['error'] : '?',
                is_string($query['error_description'] ?? null) ? $query['error_description'] : '',
            ));
        }
        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OidcFailure('The callback has no authorization code.');
        }
        $nonce = is_string($flow['nonce'] ?? null) ? $flow['nonce'] : '';
        $verifier = is_string($flow['verifier'] ?? null) ? $flow['verifier'] : '';
        $next = is_string($flow['next'] ?? null) ? $flow['next'] : null;
        $purpose = OidcPurpose::tryFrom(is_string($flow['purpose'] ?? null) ? $flow['purpose'] : '') ?? OidcPurpose::SignIn;

        $metadata = $this->discovery->metadata();
        $tokens = $this->client->exchange($metadata, $code, $this->redirectUri(), $verifier);
        $idToken = $this->validator->validate($tokens->idToken, $metadata, $nonce);
        $claims = $this->claims($idToken, $tokens, $metadata);

        if ($purpose === OidcPurpose::Link) {
            $userId = is_int($flow['user_id'] ?? null) ? $flow['user_id'] : null;
            if ($userId === null || $session->userId() !== $userId) {
                throw new OidcFailure('A link callback arrived in a session that is not the user who started it.');
            }

            return $this->users->link($userId, $idToken->issuer, $idToken->subject, $claims);
        }

        $result = $this->users->resolve($idToken->issuer, $idToken->subject, $claims);

        return new OidcResult($result->outcome, $result->user, $next, $idToken->raw);
    }

    /**
     * The ID token's claims, completed from userinfo when the username, or
     * groups that a groups variable needs, are missing (Authelia leaves
     * them out of the ID token by default).
     *
     * @return array<string, mixed>
     */
    private function claims(IdToken $idToken, TokenResponse $tokens, ProviderMetadata $metadata): array
    {
        $config = $this->settings->oidc;
        $claims = $idToken->claims;
        $missing = !array_key_exists($config->usernameClaim, $claims)
            || ($config->usesGroups() && !array_key_exists($config->groupsClaim, $claims));
        if ($missing && $tokens->accessToken !== null && $metadata->userinfoEndpoint !== null) {
            // The ID token's own claims (iss, aud, nonce, …) always win.
            $claims += $this->client->userinfo($metadata, $tokens->accessToken, $idToken->subject);
        }

        return $claims;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pending(Session $session): array
    {
        $stored = $session->get(self::SESSION_KEY);
        $flows = [];
        foreach (is_array($stored) ? $stored : [] as $state => $flow) {
            if (is_string($state) && is_array($flow)) {
                $clean = [];
                foreach ($flow as $name => $value) {
                    if (is_string($name)) {
                        $clean[$name] = $value;
                    }
                }
                $flows[$state] = $clean;
            }
        }

        return $flows;
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
