<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\ModelInfo;

/**
 * Ollama (spec.md §5 *AI adapters*): chat through its OpenAI-compatible
 * `/v1` endpoint (which cannot force a tool), models from its native
 * `/api/tags` (what is pulled) with `/api/show`'s capabilities. The base
 * URL is the server's root, e.g. `http://localhost:11434`.
 */
final readonly class OllamaAdapter implements ProviderAdapter
{
    /** Models asked about their capabilities on a refresh; the rest are ticked by hand. */
    private const int SHOW_LIMIT = 50;

    private OpenAiCompatibleAdapter $chat;

    public function __construct(
        private HttpTransport $transport,
        private Target $target,
    ) {
        $this->chat = new OpenAiCompatibleAdapter($transport, $target->withBaseUrl(self::root($target->baseUrl) . '/v1'));
    }

    public function chat(ChatRequest $request): ChatResult
    {
        return $this->chat->chat($request);
    }

    public function listModels(): array
    {
        $response = $this->transport->get($this->target->withBaseUrl(self::root($this->target->baseUrl)), 'api/tags');
        $list = $response['models'] ?? null;
        if (!is_array($list)) {
            throw new ProviderError(ErrorCode::BadResponse, 'The model list has no "models".');
        }

        $models = [];
        foreach ($list as $item) {
            $name = is_array($item) ? ($item['name'] ?? $item['model'] ?? null) : null;
            if (is_string($name)) {
                $models[] = new ModelInfo($name, null, count($models) < self::SHOW_LIMIT ? $this->capabilities($name) : []);
            }
        }

        return $models;
    }

    /**
     * What `/api/show` reports for a model (`tools`, `vision`); nothing
     * when it fails, since the list itself worked.
     *
     * @return list<Capability>
     */
    private function capabilities(string $name): array
    {
        try {
            $root = $this->target->withBaseUrl(self::root($this->target->baseUrl));
            $show = $this->transport->post($root, 'api/show', ['model' => $name]);
        } catch (ProviderError) {
            return [];
        }
        $reported = is_array($show['capabilities'] ?? null) ? $show['capabilities'] : [];

        return array_values(array_filter([
            in_array('tools', $reported, true) ? Capability::Tools : null,
            in_array('vision', $reported, true) ? Capability::Images : null,
        ]));
    }

    /**
     * The server's root: a base URL typed with `/v1` or `/api` still works.
     */
    private static function root(string $baseUrl): string
    {
        return preg_replace('~/(v1|api)/?$~', '', rtrim($baseUrl, '/')) ?? rtrim($baseUrl, '/');
    }
}
