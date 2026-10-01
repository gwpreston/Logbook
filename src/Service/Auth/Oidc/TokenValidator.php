<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Logbook\Support\Config\OidcConfig;
use Psr\Clock\ClockInterface;
use stdClass;
use Throwable;

/**
 * Validates an ID token in full (spec.md §7.9; OpenID Connect Core §3.1.3.7):
 * the signature with a key from the provider's set, using RS256, PS256,
 * ES256 or EdDSA only; `iss`, `aud`, `azp`, `exp`, `iat` (60 seconds of
 * leeway) and `nonce`. Every check is made here; firebase/php-jwt only
 * verifies the signature (and its own time checks, with our clock).
 */
final readonly class TokenValidator
{
    public const int LEEWAY_SECONDS = 60;

    /** Algorithm => the key type (and curve) it needs. `none` and HS* are never accepted. */
    private const array ALGORITHMS = [
        'RS256' => ['RSA', null],
        'PS256' => ['RSA', null],
        'ES256' => ['EC', 'P-256'],
        'EdDSA' => ['OKP', 'Ed25519'],
    ];

    public function __construct(
        private OidcConfig $config,
        private Discovery $discovery,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OidcFailure naming the check that failed
     */
    public function validate(string $token, ProviderMetadata $metadata, string $nonce): IdToken
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new OidcFailure('The ID token is not a signed JWT (three parts).');
        }
        $header = self::json($parts[0], 'header');
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !isset(self::ALGORITHMS[$alg])) {
            throw new OidcFailure(sprintf(
                'The ID token uses the algorithm "%s"; only RS256, PS256, ES256 and EdDSA are accepted.',
                is_string($alg) ? $alg : '?',
            ));
        }
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;

        $key = $this->key($metadata, $alg, $kid, false)
            ?? $this->key($metadata, $alg, $kid, true)
            ?? throw new OidcFailure(sprintf(
                'No key in the provider\'s key set matches the ID token (kid "%s", %s), even after fetching it again.',
                $kid ?? '',
                $alg,
            ));

        $claims = $this->decode($token, $key);
        $this->check($claims, $nonce);
        $subject = $claims['sub'];
        assert(is_string($subject));

        return new IdToken($token, $this->config->issuer, $subject, $claims);
    }

    /**
     * The key for this token: by `kid`, or the only key of the right type
     * when the token names none.
     */
    private function key(ProviderMetadata $metadata, string $alg, ?string $kid, bool $refresh): ?Key
    {
        [$kty, $crv] = self::ALGORITHMS[$alg];
        $candidates = [];
        foreach ($this->discovery->keys($metadata, $refresh) as $jwk) {
            if (($jwk['kty'] ?? null) !== $kty || ($crv !== null && ($jwk['crv'] ?? null) !== $crv)) {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                continue;
            }
            if ($kid !== null ? ($jwk['kid'] ?? null) === $kid : true) {
                $candidates[] = $jwk;
            }
        }
        if (count($candidates) !== 1) {
            return null;
        }

        try {
            // The algorithm is the token's, already on the list above.
            return JWK::parseKey(['alg' => $alg] + $candidates[0], $alg);
        } catch (Throwable $e) {
            throw new OidcFailure('The provider\'s key could not be read: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token, Key $key): array
    {
        $timestamp = JWT::$timestamp;
        $leeway = JWT::$leeway;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $payload = JWT::decode($token, $key);
        } catch (Throwable $e) {
            throw new OidcFailure('The ID token was refused: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$timestamp = $timestamp;
            JWT::$leeway = $leeway;
        }

        return self::toArray($payload);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function check(array $claims, string $nonce): void
    {
        $now = $this->clock->now()->getTimestamp();

        if (($claims['iss'] ?? null) !== $this->config->issuer) {
            throw new OidcFailure(sprintf('The ID token\'s issuer "%s" is not OIDC_ISSUER.', self::text($claims['iss'] ?? null)));
        }
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($this->config->clientId, $audiences, true)) {
            throw new OidcFailure('The ID token is not for this client (aud).');
        }
        if (array_key_exists('azp', $claims) && $claims['azp'] !== $this->config->clientId) {
            throw new OidcFailure('The ID token was issued to another client (azp).');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new OidcFailure('The ID token has no usable subject (sub).');
        }
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            throw new OidcFailure('The ID token has no expiry (exp).');
        }
        if ($exp + self::LEEWAY_SECONDS <= $now) {
            throw new OidcFailure('The ID token has expired.');
        }
        $iat = $claims['iat'] ?? null;
        if (!is_int($iat) && !is_float($iat)) {
            throw new OidcFailure('The ID token has no issue time (iat).');
        }
        if ($iat > $now + self::LEEWAY_SECONDS) {
            throw new OidcFailure('The ID token was issued in the future (iat).');
        }
        $tokenNonce = $claims['nonce'] ?? null;
        if (!is_string($tokenNonce) || $nonce === '' || !hash_equals($nonce, $tokenNonce)) {
            throw new OidcFailure('The ID token\'s nonce does not match this sign-in.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $segment, string $what): array
    {
        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }
        if (!is_array($decoded)) {
            throw new OidcFailure(sprintf('The ID token\'s %s is not JSON.', $what));
        }

        return self::toArray((object) $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(stdClass $object): array
    {
        $array = json_decode((string) json_encode($object), true);
        $clean = [];
        foreach (is_array($array) ? $array : [] as $name => $value) {
            if (is_string($name)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }
}
