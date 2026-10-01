<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\ModelInfo;

/**
 * One provider API (spec.md §5 *AI adapters*). Built per connection by
 * AdapterFactory, with its URL, secrets, timeout and TLS already applied.
 */
interface ProviderAdapter
{
    /**
     * @throws ProviderError
     */
    public function chat(ChatRequest $request): ChatResult;

    /**
     * @return list<ModelInfo>
     * @throws ProviderError
     */
    public function listModels(): array;
}
