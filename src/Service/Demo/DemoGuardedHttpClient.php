<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The app's one HTTP client, with the demo's guarantee in front of it: in
 * an active demo no outbound request is made (spec.md §7.36). Whatever asks
 * (an update check, an AI provider, a webhook, single sign-on, a fuel price
 * feed) gets the same transport failure it would get from a network that is
 * down, and the demo sends nothing.
 */
final readonly class DemoGuardedHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private DemoMode $mode,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->mode->blocks(DemoRestriction::Outbound)) {
            throw new TransportException('Outbound requests are switched off in the demo.');
        }

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->mode);
    }
}
