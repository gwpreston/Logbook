<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Closure;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An OpenID Connect provider in the test process (spec.md §7.9): discovery,
 * a key set, an authorization step and a token endpoint that checks the
 * client secret and the PKCE verifier, and signs ID tokens with test keys
 * (RS256, PS256, ES256, EdDSA). The suite needs no network.
 *
 *   $idp = new FakeIdentityProvider();
 *   $container->set(HttpClientInterface::class, $idp->client);
 *   $callback = $idp->authorize($browser->get('/auth/oidc/start'), ['sub' => 'u-1']);
 *   $browser->get($callback);
 */
final class FakeIdentityProvider
{
    public const string ISSUER = 'https://idp.example.test/realms/home';
    public const string CLIENT_ID = 'logbook';
    public const string CLIENT_SECRET = 'a secret with spaces & symbols';

    /** @var array<string, array{private: OpenSSLAsymmetricKey|string, public: array<string, string>}> */
    private static array $keys = [];

    public readonly MockHttpClient $client;

    /** The algorithm and key id the next ID tokens are signed with. */
    public string $alg = 'RS256';
    public ?string $kid = 'rsa-1';
    /** Key ids served in the key set (a rotation test changes this between fetches). */
    /** @var list<string>|null */
    public ?array $publishedKids = null;
    /** Overrides for discovery (e.g. a different issuer, no end_session_endpoint). */
    /** @var array<string, mixed> */
    public array $discoveryOverrides = [];
    /** Claims the userinfo endpoint answers with (beyond sub). */
    /** @var array<string, mixed> */
    public array $userinfo = [];
    /** Change the ID token's claims after they are built (wrong aud, expired, …). */
    public ?Closure $tamperClaims = null;
    /** Replace the signed token (alg none, HS256, a bad signature). */
    public ?Closure $tamperToken = null;
    public int $now;

    /** @var list<array{method: string, url: string, body: string, headers: array<string, list<string>>}> */
    public array $requests = [];

    /** @var array<string, array{nonce: string, challenge: string, redirect: string, claims: array<string, mixed>}> */
    private array $codes = [];

    public function __construct(public readonly string $issuer = self::ISSUER)
    {
        $this->now = time();
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $body = $options['body'] ?? '';
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_string($body) ? $body : '',
                'headers' => self::headers($options['normalized_headers'] ?? []),
            ];

            $headers = $options['normalized_headers'] ?? [];

            return $this->answer($method, $url, is_string($body) ? $body : '', is_array($headers) ? $headers : []);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $base = rtrim($this->issuer, '/');

        return $this->discoveryOverrides + [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $base . '/protocol/openid-connect/auth',
            'token_endpoint' => $base . '/protocol/openid-connect/token',
            'jwks_uri' => $base . '/protocol/openid-connect/certs',
            'userinfo_endpoint' => $base . '/protocol/openid-connect/userinfo',
            'end_session_endpoint' => $base . '/protocol/openid-connect/logout',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'id_token_signing_alg_values_supported' => ['RS256', 'PS256', 'ES256', 'EdDSA'],
        ];
    }

    /**
     * What the provider does when the browser arrives with the redirect from
     * /auth/oidc/start: remember the request, issue a code, and return the
     * local callback path the browser is sent back to.
     *
     * @param string $authorizationUrl the Location header of the start response
     * @param array<string, mixed> $claims the account (sub, preferred_username, groups, …)
     * @param array<string, string> $callbackOverrides e.g. a different state
     */
    public function authorize(string $authorizationUrl, array $claims, array $callbackOverrides = []): string
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $required = [
            'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method',
        ];
        foreach ($required as $name) {
            if (!is_string($query[$name] ?? null)) {
                throw new RuntimeException('The authorization request has no ' . $name);
            }
        }
        if ($query['response_type'] !== 'code' || $query['code_challenge_method'] !== 'S256') {
            throw new RuntimeException('Only the code flow with S256 is supported');
        }
        $code = bin2hex(random_bytes(8));
        $this->codes[$code] = [
            'nonce' => (string) $query['nonce'],
            'challenge' => (string) $query['code_challenge'],
            'redirect' => (string) $query['redirect_uri'],
            'claims' => $claims,
        ];
        $redirect = (string) $query['redirect_uri'];
        $path = (string) parse_url($redirect, PHP_URL_PATH);

        return $path . '?' . http_build_query($callbackOverrides + ['code' => $code, 'state' => (string) $query['state']]);
    }

    /**
     * The query parameters of an authorization URL.
     *
     * @return array<string, string>
     */
    public static function query(string $authorizationUrl): array
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $strings = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }

    /**
     * A signed ID token (for validator tests that skip the flow).
     *
     * @param array<string, mixed> $claims
     */
    public function idToken(array $claims, string $nonce = 'n-1'): string
    {
        $claims += [
            'iss' => $this->issuer,
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-1',
            'iat' => $this->now,
            'exp' => $this->now + 300,
            'nonce' => $nonce,
        ];
        if ($this->tamperClaims !== null) {
            $tampered = ($this->tamperClaims)($claims);
            assert(is_array($tampered));
            $claims = $tampered;
        }
        $key = self::key($this->alg);
        $token = JWT::encode($claims, $key['private'], $this->alg, $this->kid);
        $token = $this->tamperToken !== null ? ($this->tamperToken)($token) : $token;
        assert(is_string($token));

        return $token;
    }

    /**
     * The public key set.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        $keys = [];
        foreach (['RS256', 'ES256', 'EdDSA'] as $alg) {
            $public = self::key($alg)['public'];
            if ($this->publishedKids === null || in_array($public['kid'], $this->publishedKids, true)) {
                $keys[] = $public;
            }
        }

        return ['keys' => $keys];
    }

    /**
     * @return list<array{method: string, url: string, body: string, headers: array<string, list<string>>}>
     */
    public function requestsTo(string $suffix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], $suffix)));
    }

    /**
     * Test keys, made once per run. PS256 signs with the RSA key (one RSA
     * key in the set, no `alg`, as Keycloak may publish it).
     *
     * @return array{private: OpenSSLAsymmetricKey|string, public: array<string, string>}
     */
    public static function key(string $alg): array
    {
        $type = match ($alg) {
            'RS256', 'PS256', 'HS256' => 'RSA',
            'ES256' => 'EC',
            'EdDSA' => 'OKP',
            default => throw new RuntimeException('No test key for ' . $alg),
        };
        if (!isset(self::$keys[$type])) {
            self::$keys[$type] = match ($type) {
                'RSA' => self::rsa(),
                'EC' => self::ec(),
                'OKP' => self::ed25519(),
            };
        }

        return self::$keys[$type];
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function answer(string $method, string $url, string $body, array $headers): MockResponse
    {
        $base = rtrim($this->issuer, '/');
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($method === 'GET' && $url === $base . '/.well-known/openid-configuration') {
            return self::json($this->discovery());
        }
        if ($method === 'GET' && str_ends_with($path, '/certs')) {
            return self::json($this->jwks());
        }
        if ($method === 'POST' && str_ends_with($path, '/token')) {
            return $this->token($body, self::headers($headers));
        }
        if ($method === 'GET' && str_ends_with($path, '/userinfo')) {
            $auth = self::headers($headers)['authorization'][0] ?? '';
            $code = str_starts_with($auth, 'Bearer access-') ? substr($auth, strlen('Bearer access-')) : '';
            $claims = $this->issued[$code] ?? null;
            if ($claims === null) {
                return self::json(['error' => 'invalid_token'], 401);
            }

            return self::json($this->userinfo + ['sub' => $claims['sub'] ?? '']);
        }

        return self::json(['error' => 'not_found'], 404);
    }

    /** @var array<string, array<string, mixed>> code => claims, for userinfo */
    private array $issued = [];

    /**
     * @param array<string, list<string>> $headers
     */
    private function token(string $body, array $headers): MockResponse
    {
        parse_str($body, $form);
        $code = is_string($form['code'] ?? null) ? $form['code'] : '';
        $grant = $this->codes[$code] ?? null;
        unset($this->codes[$code]);
        if ($grant === null || ($form['grant_type'] ?? null) !== 'authorization_code') {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        // Client authentication: Basic (each part form-urlencoded, RFC 6749 §2.3.1) or post.
        $auth = $headers['authorization'][0] ?? '';
        if (str_starts_with($auth, 'Basic ')) {
            $pair = explode(':', (string) base64_decode(substr($auth, 6), true), 2) + ['', ''];
            [$id, $secret] = array_map(rawurldecode(...), $pair);
        } else {
            $id = is_string($form['client_id'] ?? null) ? $form['client_id'] : '';
            $secret = is_string($form['client_secret'] ?? null) ? $form['client_secret'] : '';
        }
        if ($id !== self::CLIENT_ID || $secret !== self::CLIENT_SECRET) {
            return self::json(['error' => 'invalid_client'], 401);
        }
        if (($form['redirect_uri'] ?? null) !== $grant['redirect']) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'redirect_uri'], 400);
        }
        $verifier = is_string($form['code_verifier'] ?? null) ? $form['code_verifier'] : '';
        if (self::b64(hash('sha256', $verifier, true)) !== $grant['challenge']) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'PKCE'], 400);
        }

        $this->issued[$code] = $grant['claims'] + ['sub' => 'subject-1'];

        return self::json([
            'access_token' => 'access-' . $code,
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => $this->idToken($grant['claims'], $grant['nonce']),
        ]);
    }

    /**
     * @return array{private: OpenSSLAsymmetricKey, public: array<string, string>}
     */
    private static function rsa(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('openssl could not make an RSA key');
        }
        $details = openssl_pkey_get_details($key);
        assert(is_array($details) && is_array($details['rsa'] ?? null));

        return ['private' => $key, 'public' => [
            'kty' => 'RSA',
            'kid' => 'rsa-1',
            'use' => 'sig',
            'n' => self::b64(self::bytes($details['rsa']['n'] ?? null)),
            'e' => self::b64(self::bytes($details['rsa']['e'] ?? null)),
        ]];
    }

    /**
     * @return array{private: OpenSSLAsymmetricKey, public: array<string, string>}
     */
    private static function ec(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('openssl could not make an EC key');
        }
        $details = openssl_pkey_get_details($key);
        assert(is_array($details) && is_array($details['ec'] ?? null));

        return ['private' => $key, 'public' => [
            'kty' => 'EC',
            'kid' => 'ec-1',
            'use' => 'sig',
            'alg' => 'ES256',
            'crv' => 'P-256',
            'x' => self::b64(str_pad(self::bytes($details['ec']['x'] ?? null), 32, "\0", STR_PAD_LEFT)),
            'y' => self::b64(str_pad(self::bytes($details['ec']['y'] ?? null), 32, "\0", STR_PAD_LEFT)),
        ]];
    }

    /**
     * @return array{private: string, public: array<string, string>}
     */
    private static function ed25519(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['private' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => [
            'kty' => 'OKP',
            'kid' => 'ed-1',
            'use' => 'sig',
            'alg' => 'EdDSA',
            'crv' => 'Ed25519',
            'x' => self::b64(sodium_crypto_sign_publickey($pair)),
        ]];
    }

    private static function bytes(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * {"authorization": ["Authorization: Basic …"]} → {"authorization": ["Basic …"]}
     *
     * @return array<string, list<string>>
     */
    private static function headers(mixed $normalized): array
    {
        $headers = [];
        foreach (is_array($normalized) ? $normalized : [] as $name => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                if (is_string($name) && is_string($line)) {
                    $headers[$name][] = trim((string) substr($line, strpos($line, ':') + 1));
                }
            }
        }

        return $headers;
    }
}
