<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

use Logbook\Domain\Auth\ProxyAuthMode;
use Logbook\Service\Auth\External\ExternalAccount;
use Logbook\Support\Config\ProxyAuthConfig;
use Logbook\Support\Log\LogThrottle;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the sign-in proxy's headers from a request (spec.md §7.9 *Trust
 * check*, *Signed JWT*). Only from a trusted connecting address
 * (`REMOTE_ADDR`; forwarding headers are never consulted), and only the
 * HTTP header, never the CGI `REMOTE_USER` variable. A refused header is
 * logged at most once per address per hour and reads as no header.
 *
 * The headers are read from the server's `HTTP_*` variables, not from
 * PSR-7's header list: slim/psr7 builds that from getallheaders() and folds
 * `_` into `-`, so a client's `Remote_User` would arrive as `Remote-User`
 * past a proxy that overwrites only the dash spelling. Apache 2.4 and
 * nginx's FastCGI (with its default `underscores_in_headers off`) never
 * put a name with an underscore into the `HTTP_*` variables.
 */
final readonly class ProxyHeaders
{
    private const int MAX_LENGTH = 255;

    public function __construct(
        private ProxyAuthConfig $config,
        private ProxyJwtValidator $jwt,
        private LogThrottle $throttle,
        private LoggerInterface $logger,
    ) {
    }

    public function read(ServerRequestInterface $request): ProxyRead
    {
        if (!$this->config->isEnabled()) {
            return new ProxyRead();
        }
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $address = is_string($address) ? $address : '';
        $value = self::header($request, $this->config->header);
        $sent = $value !== null && trim($value) !== '';

        if (!$this->config->trusts($address)) {
            if ($sent) {
                $this->warn('untrusted', $address, sprintf(
                    'Header %s from %s ignored: not a trusted proxy',
                    $this->config->header,
                    $address !== '' ? $address : 'an unknown address',
                ));
            }

            return new ProxyRead();
        }
        $listed = $this->config->isListedProxy($address);
        if (!$sent) {
            return new ProxyRead(null, $listed);
        }

        try {
            // Sent twice (a proxy that appends rather than overwrites) arrives joined with
            // ", ", which is neither one username nor one token: trust neither.
            $account = $this->config->mode === ProxyAuthMode::Jwt
                ? $this->fromJwt($value)
                : $this->fromHeaders($request, $value);
        } catch (ProxyAuthFailure $e) {
            $this->warn('refused', $address, sprintf(
                'Header %s from %s refused: %s',
                $this->config->header,
                $address !== '' ? $address : 'an unknown address',
                $e->getMessage(),
            ));

            return new ProxyRead(null, $listed);
        }

        return new ProxyRead($account, $listed);
    }

    private function fromHeaders(ServerRequestInterface $request, string $value): ExternalAccount
    {
        $username = mb_strtolower(trim($value));
        self::checkText($username);
        $optional = function (string $header) use ($request): ?string {
            if ($header === '') {
                return null;
            }
            $text = trim(self::header($request, $header) ?? '');

            return $text === '' || !mb_check_encoding($text, 'UTF-8') ? null : $text;
        };
        $groups = $optional($this->config->groupsHeader);

        return new ExternalAccount(
            $this->config->headerIssuer(),
            $username,
            username: $username,
            displayName: $optional($this->config->nameHeader),
            email: $optional($this->config->emailHeader),
            groups: $groups === null ? [] : self::list($groups),
        );
    }

    private function fromJwt(string $token): ExternalAccount
    {
        $claims = $this->jwt->validate($token);
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $subject = $claims['sub'];
        assert(is_string($subject));
        $issuer = $claims['iss'];
        assert(is_string($issuer));

        return new ExternalAccount(
            $issuer,
            $subject,
            username: $text($claims['preferred_username'] ?? null),
            displayName: $text($claims['name'] ?? null),
            email: $text($claims['email'] ?? null),
            locale: $text($claims['locale'] ?? null),
            groups: ExternalAccount::groupList($claims['groups'] ?? []),
        );
    }

    /**
     * The header as the server passed it to PHP (`HTTP_REMOTE_USER` for
     * Remote-User), or null when it wasn't sent.
     */
    private static function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getServerParams()['HTTP_' . strtoupper(strtr($name, '-', '_'))] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function list(string $value): array
    {
        // Authelia separates groups with commas, Authentik's outpost with "|".
        $items = preg_split('/\s*[,|]\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $items === false ? [] : $items;
    }

    private static function checkText(string $value): void
    {
        if ($value === '') {
            throw new ProxyAuthFailure('the value is empty.');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw new ProxyAuthFailure('the value is longer than 255 bytes.');
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f,]/', $value) === 1) {
            throw new ProxyAuthFailure('the value is not one username (control characters, a comma or not UTF-8).');
        }
    }

    private function warn(string $kind, string $address, string $message): void
    {
        if ($this->throttle->allow('proxy-' . $kind . '|' . $this->config->header . '|' . $address)) {
            $this->logger->warning($message);
        }
    }
}
