<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Support\Config\OidcConfig;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The provider's discovery document and key set (spec.md §7.9), fetched on
 * first use and cached for 24 hours. The discovered issuer must equal
 * `OIDC_ISSUER` exactly. These, the token exchange and userinfo are the
 * only requests single sign-on makes.
 */
final readonly class Discovery
{
    private const string WELL_KNOWN = '/.well-known/openid-configuration';

    public function __construct(
        private OidcConfig $config,
        private HttpClientInterface $http,
        private OidcCache $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OidcFailure
     */
    public function metadata(): ProviderMetadata
    {
        $url = rtrim($this->config->issuer, '/') . self::WELL_KNOWN;
        $now = $this->clock->now()->getTimestamp();
        $document = $this->cache->get($url, $now);
        $fresh = $document === null;
        $document ??= $this->fetchJson($url);

        $metadata = ProviderMetadata::fromDocument($document);
        if ($metadata->issuer !== $this->config->issuer) {
            throw new OidcFailure(sprintf(
                'The provider says its issuer is "%s", but OIDC_ISSUER is "%s". They must be equal, character for character.',
                $metadata->issuer,
                $this->config->issuer,
            ));
        }
        if ($fresh) {
            $this->cache->put($url, $document, $now);
        }

        return $metadata;
    }

    /**
     * The JSON Web Key Set; $refresh skips the cache (a token named a key
     * we do not have: the provider may have rotated its keys).
     *
     * @return list<array<string, mixed>>
     * @throws OidcFailure
     */
    public function keys(ProviderMetadata $metadata, bool $refresh = false): array
    {
        $now = $this->clock->now()->getTimestamp();
        $set = $refresh ? null : $this->cache->get($metadata->jwksUri, $now);
        if ($set === null) {
            $set = $this->fetchJson($metadata->jwksUri);
            if (!is_array($set['keys'] ?? null)) {
                throw new OidcFailure('The provider\'s key set has no "keys".');
            }
            $this->cache->put($metadata->jwksUri, $set, $now);
        }

        $keys = [];
        foreach (is_array($set['keys'] ?? null) ? $set['keys'] : [] as $key) {
            if (is_array($key)) {
                $clean = [];
                foreach ($key as $name => $value) {
                    if (is_string($name)) {
                        $clean[$name] = $value;
                    }
                }
                $keys[] = $clean;
            }
        }

        return $keys;
    }

    /**
     * @return array<mixed>
     * @throws OidcFailure
     */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new OidcFailure(sprintf('Could not fetch %s: %s', $url, $e->getMessage()), 0, $e);
        }
        if ($status !== 200) {
            throw new OidcFailure(sprintf('%s answered HTTP %d.', $url, $status));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new OidcFailure(sprintf('%s did not answer with a JSON object.', $url));
        }

        return $data;
    }
}
