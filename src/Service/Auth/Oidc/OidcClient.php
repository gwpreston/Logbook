<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Support\Config\OidcConfig;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The authorization code flow's two halves at the provider (spec.md §7.9):
 * the authorization URL (PKCE S256, `state`, `nonce`) and the token
 * exchange with the client secret, plus the userinfo request and the
 * sign-out URL.
 */
final readonly class OidcClient
{
    public function __construct(
        private OidcConfig $config,
        private HttpClientInterface $http,
    ) {
    }

    public function authorizationUrl(
        ProviderMetadata $metadata,
        string $redirectUri,
        string $state,
        string $nonce,
        string $verifier,
    ): string {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->config->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $metadata->authorizationEndpoint . (str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * RFC 7636 §4.2: BASE64URL(SHA256(verifier)).
     */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @throws OidcFailure
     */
    public function exchange(ProviderMetadata $metadata, string $code, string $redirectUri, string $verifier): TokenResponse
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ];
        $headers = ['Accept' => 'application/json'];
        if ($metadata->usesPostAuth()) {
            $body['client_id'] = $this->config->clientId;
            $body['client_secret'] = $this->config->clientSecret;
        } else {
            // RFC 6749 §2.3.1: each part form-urlencoded before Basic.
            $headers['Authorization'] = 'Basic ' . base64_encode(
                rawurlencode($this->config->clientId) . ':' . rawurlencode($this->config->clientSecret),
            );
        }

        $data = $this->post($metadata->tokenEndpoint, $body, $headers);
        $idToken = $data['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new OidcFailure(
                'The token endpoint answered without an id_token (is the "openid" scope allowed for this client?).',
            );
        }
        $access = $data['access_token'] ?? null;

        return new TokenResponse($idToken, is_string($access) && $access !== '' ? $access : null);
    }

    /**
     * Claims from the userinfo endpoint; its `sub` must be the ID token's
     * (OpenID Connect Core §5.3.2).
     *
     * @return array<string, mixed>
     * @throws OidcFailure
     */
    public function userinfo(ProviderMetadata $metadata, string $accessToken, string $subject): array
    {
        if ($metadata->userinfoEndpoint === null) {
            return [];
        }
        try {
            $response = $this->http->request('GET', $metadata->userinfoEndpoint, [
                'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $accessToken],
                'timeout' => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new OidcFailure('The userinfo request failed: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode($content, true);
        if ($status !== 200 || !is_array($data)) {
            throw new OidcFailure(sprintf('The userinfo endpoint answered HTTP %d without JSON claims.', $status));
        }
        if (($data['sub'] ?? null) !== $subject) {
            throw new OidcFailure('The userinfo answer is for another subject than the ID token.');
        }
        $claims = [];
        foreach ($data as $name => $value) {
            if (is_string($name)) {
                $claims[$name] = $value;
            }
        }

        return $claims;
    }

    /**
     * RP-initiated logout (OpenID Connect RP-Initiated Logout 1.0 §2).
     */
    public function logoutUrl(ProviderMetadata $metadata, string $idToken, string $postLogoutRedirect): ?string
    {
        if ($metadata->endSessionEndpoint === null) {
            return null;
        }
        $query = http_build_query([
            'id_token_hint' => $idToken,
            'client_id' => $this->config->clientId,
            'post_logout_redirect_uri' => $postLogoutRedirect,
        ], '', '&', PHP_QUERY_RFC3986);
        $endpoint = $metadata->endSessionEndpoint;

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function post(string $url, array $body, array $headers): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new OidcFailure('The token request failed: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode($content, true);
        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'no JSON';
            throw new OidcFailure(sprintf('The token endpoint answered HTTP %d (%s).', $status, $error));
        }

        return $data;
    }
}
