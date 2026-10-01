<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

/**
 * What discovery says about the provider (OpenID Connect Discovery 1.0 §3),
 * the parts Logbook uses.
 */
final readonly class ProviderMetadata
{
    /**
     * @param list<string> $tokenAuthMethods
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint = null,
        public ?string $endSessionEndpoint = null,
        public array $tokenAuthMethods = ['client_secret_basic'],
    ) {
    }

    /**
     * @param array<mixed> $document
     * @throws OidcFailure when a required endpoint is missing
     */
    public static function fromDocument(array $document): self
    {
        $url = static function (string $name, bool $required) use ($document): ?string {
            $value = $document[$name] ?? null;
            if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
                return $value;
            }
            if ($required) {
                throw new OidcFailure(sprintf('The discovery document has no usable "%s".', $name));
            }

            return null;
        };
        $issuer = $document['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new OidcFailure('The discovery document has no "issuer".');
        }
        $methods = $document['token_endpoint_auth_methods_supported'] ?? null;
        $methods = is_array($methods) ? array_values(array_filter($methods, is_string(...))) : [];

        return new self(
            issuer: $issuer,
            authorizationEndpoint: (string) $url('authorization_endpoint', true),
            tokenEndpoint: (string) $url('token_endpoint', true),
            jwksUri: (string) $url('jwks_uri', true),
            userinfoEndpoint: $url('userinfo_endpoint', false),
            endSessionEndpoint: $url('end_session_endpoint', false),
            // Discovery 1.0: when omitted, the default is client_secret_basic.
            tokenAuthMethods: $methods === [] ? ['client_secret_basic'] : $methods,
        );
    }

    /**
     * client_secret_basic, unless the provider offers only client_secret_post.
     */
    public function usesPostAuth(): bool
    {
        return !in_array('client_secret_basic', $this->tokenAuthMethods, true)
            && in_array('client_secret_post', $this->tokenAuthMethods, true);
    }
}
