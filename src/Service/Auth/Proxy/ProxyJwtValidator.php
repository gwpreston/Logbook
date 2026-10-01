<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Logbook\Support\Config\ProxyAuthConfig;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Checks Authentik's `X-authentik-jwt` (spec.md §7.9 *Signed JWT*): the ID
 * token the outpost's proxy provider issued, which a proxy provider can
 * only sign with HS256 and its client secret. HS256 is the only algorithm
 * accepted, unlike the OIDC TokenValidator, which never accepts it; then
 * `iss`, `aud`, `sub`, `exp` and `iat` (60 seconds of leeway).
 */
final readonly class ProxyJwtValidator
{
    public const int LEEWAY_SECONDS = 60;
    private const string ALGORITHM = 'HS256';

    public function __construct(
        private ProxyAuthConfig $config,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed> the claims, sub a non-empty string of at most 255
     * @throws ProxyAuthFailure naming the check that failed
     */
    public function validate(#[SensitiveParameter] string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new ProxyAuthFailure('the JWT is not a signed token (three parts).');
        }
        $header = self::json($parts[0]);
        $alg = $header['alg'] ?? null;
        if ($alg !== self::ALGORITHM) {
            throw new ProxyAuthFailure(sprintf(
                'the JWT uses the algorithm "%s"; only HS256 is accepted.',
                is_scalar($alg) ? (string) $alg : '?',
            ));
        }

        $claims = $this->decode(trim($token));
        $now = $this->clock->now()->getTimestamp();
        if (($claims['iss'] ?? null) !== $this->config->jwtIssuer) {
            throw new ProxyAuthFailure(sprintf(
                'the JWT\'s issuer "%s" is not AUTH_PROXY_JWT_ISSUER.',
                is_scalar($claims['iss'] ?? null) ? (string) $claims['iss'] : '?',
            ));
        }
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($this->config->jwtAudience, $audiences, true)) {
            throw new ProxyAuthFailure('the JWT is not for AUTH_PROXY_JWT_AUDIENCE (aud).');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new ProxyAuthFailure('the JWT has no usable subject (sub).');
        }
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            throw new ProxyAuthFailure('the JWT has no expiry (exp).');
        }
        if ($exp + self::LEEWAY_SECONDS <= $now) {
            throw new ProxyAuthFailure('the JWT has expired.');
        }
        $iat = $claims['iat'] ?? null;
        if (!is_int($iat) && !is_float($iat)) {
            throw new ProxyAuthFailure('the JWT has no issue time (iat).');
        }
        if ($iat > $now + self::LEEWAY_SECONDS) {
            throw new ProxyAuthFailure('the JWT was issued in the future (iat).');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(#[SensitiveParameter] string $token): array
    {
        $timestamp = JWT::$timestamp;
        $leeway = JWT::$leeway;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $payload = JWT::decode($token, new Key($this->config->jwtSecret, self::ALGORITHM));
        } catch (Throwable $e) {
            throw new ProxyAuthFailure('the JWT was refused: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$timestamp = $timestamp;
            JWT::$leeway = $leeway;
        }

        return self::toArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $segment): array
    {
        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }
        if (!is_array($decoded)) {
            throw new ProxyAuthFailure('the JWT\'s header is not JSON.');
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
}
