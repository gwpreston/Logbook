<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use Logbook\Domain\Ai\Outcome;
use Logbook\Domain\Ai\RequestRecord;
use Logbook\Kernel;
use Logbook\Repository\AiRequestRepository;
use Logbook\Service\Api\KeyHolder;
use Psr\Clock\ClockInterface;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Answers one MCP call (spec.md §7.28) as the key's user, who is already
 * applied (ApiKeyAuthenticator::as). Both eras share every method but
 * their handshake: `server/discover` for the stateless `2026-07-28`, and
 * `initialize` and `ping` for the legacy versions. Modern results carry
 * `resultType`, the server's identity and, where the revision asks for
 * them, caching hints (`ttlMs: 0`, `cacheScope: "private"`: every list
 * depends on the key).
 *
 * Each `tools/call`, `resources/read` and `prompts/get` is in the usage log
 * as task `mcp`, with the key's name for the model and never any content.
 */
final readonly class McpServer
{
    public const string TASK = 'mcp';
    public const string NAME = 'logbook';
    private const array CACHEABLE = [
        'server/discover',
        'tools/list',
        'prompts/list',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];
    private const array LOGGED = ['tools/call', 'resources/read', 'prompts/get'];

    public function __construct(
        private McpToolbox $tools,
        private McpResources $resources,
        private McpPrompts $prompts,
        private AiRequestRepository $requests,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The result of a request (null for a notification, which has none).
     *
     * @return array<string, mixed>|null
     * @throws McpError
     */
    public function handle(McpCall $call, KeyHolder $holder): ?array
    {
        if ($call->notification) {
            return null;
        }
        if (!in_array($call->method, self::LOGGED, true)) {
            return $this->finish($call, $this->dispatch($call, $holder));
        }

        $started = hrtime(true);
        try {
            $result = $this->dispatch($call, $holder);
        } catch (McpError $e) {
            $this->log($holder, $started, Outcome::Refused, 'rpc_' . abs($e->rpcCode));

            throw $e;
        } catch (Throwable $e) {
            $this->log($holder, $started, Outcome::Error, 'internal');

            throw $e;
        }
        $failed = ($result['isError'] ?? false) === true;
        $this->log($holder, $started, $failed ? Outcome::Error : Outcome::Ok, $failed ? 'tool_error' : null);

        return $this->finish($call, $result);
    }

    /**
     * @return array<string, mixed>
     * @throws McpError
     */
    private function dispatch(McpCall $call, KeyHolder $holder): array
    {
        return match ($call->method) {
            'server/discover' => [
                'supportedVersions' => McpVersion::supported(),
                'capabilities' => self::capabilities(),
                'instructions' => $this->translator->trans('mcp.instructions'),
            ],
            'initialize' => $call->modern ? throw self::unknown($call) : [
                'protocolVersion' => $call->version,
                'capabilities' => self::capabilities(),
                'serverInfo' => self::serverInfo(),
                'instructions' => $this->translator->trans('mcp.instructions'),
            ],
            'ping' => $call->modern ? throw self::unknown($call) : [],
            'tools/list' => ['tools' => $this->tools->definitions($holder)],
            'tools/call' => $this->tools->call($holder, self::name($call, 'name'), $call->object('arguments')),
            'resources/list' => ['resources' => $this->resources->list()],
            'resources/templates/list' => ['resourceTemplates' => $this->resources->templates()],
            'resources/read' => ['contents' => $this->resources->read($holder, self::name($call, 'uri'), $call->modern)],
            'prompts/list' => ['prompts' => $this->prompts->list()],
            'prompts/get' => $this->prompts->get($holder->user, self::name($call, 'name'), $call->object('arguments')),
            default => throw self::unknown($call),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finish(McpCall $call, array $result): array
    {
        if (!$call->modern) {
            return $result;
        }
        $result = ['resultType' => 'complete', ...$result];
        if (in_array($call->method, self::CACHEABLE, true)) {
            $result += ['ttlMs' => 0, 'cacheScope' => 'private'];
        }
        $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => self::serverInfo()];

        return $result;
    }

    /**
     * Tools, resources and prompts, without list-changed notifications or
     * subscriptions (spec.md §7.28 *Not in scope*).
     *
     * @return array<string, object>
     */
    private static function capabilities(): array
    {
        return ['tools' => new stdClass(), 'resources' => new stdClass(), 'prompts' => new stdClass()];
    }

    /**
     * @return array{name: string, title: string, version: string}
     */
    private static function serverInfo(): array
    {
        return ['name' => self::NAME, 'title' => 'Logbook', 'version' => Kernel::version()];
    }

    /**
     * @throws McpError
     */
    private static function name(McpCall $call, string $field): string
    {
        $value = $call->string($field);
        if ($value === null || $value === '') {
            throw McpError::invalidParams(sprintf('"%s" is required.', $field));
        }

        return $value;
    }

    private static function unknown(McpCall $call): McpError
    {
        return new McpError(
            McpError::METHOD_NOT_FOUND,
            sprintf('Method not found: %s', mb_substr($call->method, 0, 100)),
            $call->modern ? 404 : 200,
        );
    }

    private function log(KeyHolder $holder, int|float $started, Outcome $outcome, ?string $code): void
    {
        $this->requests->log(new RequestRecord(
            $holder->user->id,
            self::TASK,
            null,
            $holder->key->name,
            null,
            null,
            (int) round((hrtime(true) - $started) / 1e6),
            $outcome,
            $code,
            null,
            $this->clock->now(),
        ));
    }
}
