<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use Doctrine\DBAL\Connection;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Draft\DraftProposal;
use Logbook\Domain\Ai\Draft\DraftSource;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\Tool\Draft\DraftTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Draft\DraftInvalid;
use Logbook\Service\Ai\Draft\DraftRefused;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Ai\Draft\DraftWritten;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Api\KeyHolder;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The tools an MCP client sees (spec.md §7.28 *Tools*), by key:
 *
 * - every key: the Ask read tools (§7.26), run through the registry exactly
 *   as Ask runs them, as the key's user, in a transaction always rolled back;
 * - `read_write` keys: `log_fill_up` and `add_reading`, the Ask draft tools'
 *   resolution followed by the API's write path for real (decided
 *   2026-10-01, #88), and the other five draft tools, whose validated entry
 *   is kept as a draft from MCP for its user to add in Logbook.
 *
 * The AI modules don't apply (#91): each tool needs only its own module.
 * Descriptions are written for an MCP client's model, in the user's language
 * (`mcp.tool.*`). Links are absolute.
 */
final readonly class McpToolbox
{
    /** The direct writes, by MCP name. */
    private const array WRITES = ['log_fill_up' => DraftKind::Fuel, 'add_reading' => DraftKind::Odometer];
    /** The drafts, by MCP name, in the order offered. */
    private const array DRAFTS = [
        'draft_service_record' => DraftKind::Maintenance,
        'draft_document' => DraftKind::Document,
        'draft_expense' => DraftKind::Expense,
        'draft_tyre_check' => DraftKind::TyreCheck,
        'draft_reminder' => DraftKind::Reminder,
    ];

    /** @var array<string, DraftTool> by kind value */
    private array $draftTools;

    /**
     * @param iterable<DraftTool> $draftTools
     */
    public function __construct(
        private ToolRegistry $registry,
        iterable $draftTools,
        private DraftWriter $writer,
        private DraftStore $drafts,
        private Transaction $transaction,
        private Connection $connection,
        private TranslatorInterface $translator,
        private AbsoluteUrl $urls,
        private AppSettings $settings,
        private LoggerInterface $logger,
    ) {
        $byKind = [];
        foreach ($draftTools as $tool) {
            $byKind[$tool->kind()->value] = $tool;
        }
        $this->draftTools = $byKind;
    }

    /**
     * The key's tools, as `tools/list` lists them.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(KeyHolder $holder): array
    {
        $tools = [];
        foreach ($this->readTools($holder->user) as $tool) {
            $tools[] = $this->definition($tool->name(), $tool->definition()->parameters, [
                'readOnlyHint' => true,
                'openWorldHint' => false,
            ]);
        }
        foreach ($this->writeTools($holder) as $name => $tool) {
            $tools[] = $this->definition($name, $tool->definition()->parameters, [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                // A retry finds the entry already logged and writes nothing.
                'idempotentHint' => isset(self::WRITES[$name]),
                'openWorldHint' => false,
            ]);
        }

        return $tools;
    }

    /**
     * Run a call as the key's user: a `CallToolResult`. A tool the key
     * can't use is unknown (a protocol error); a tool's own refusal is a
     * result with `isError`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws McpError
     */
    public function call(KeyHolder $holder, string $name, array $arguments): array
    {
        $user = $holder->user;
        foreach ($this->readTools($user) as $tool) {
            if ($tool->name() === $name) {
                $run = $this->registry->run($user, new ToolCall('mcp', $name, $arguments));
                if ($run->result === null) {
                    return self::failure((string) $run->error);
                }

                return self::success($this->withLink($run->result->data, $run->result->link));
            }
        }

        $tool = $this->writeTools($holder)[$name] ?? null;
        if ($tool === null) {
            throw McpError::invalidParams(sprintf('Unknown tool: %s', $name));
        }

        try {
            $result = $this->rolledBack(static fn (): ToolResult => $tool->run($user, new ToolArguments($arguments)));
        } catch (ToolError $e) {
            return self::failure($e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('MCP tool failed', ['tool' => $name, 'exception' => $e]);

            return self::failure('The tool failed.');
        }
        $proposal = $result->draft;
        if ($proposal === null) {
            // A question back, missing or refused fields, or already logged.
            $data = $result->data;
            unset($data['say']);
            $status = is_string($result->data['status'] ?? null) ? $result->data['status'] : '';
            $say = $result->data['say'] ?? null;

            return self::answer(
                $data + ['say' => is_string($say) ? $say : $this->translator->trans('mcp.say.' . $status)],
                $status === 'invalid',
            );
        }

        return isset(self::WRITES[$name])
            ? $this->write($user, $proposal)
            : $this->keep($user, $proposal);
    }

    /**
     * The read tools on for this user, in the registry's order.
     *
     * @return list<AskTool>
     */
    private function readTools(User $user): array
    {
        return array_values(array_filter(
            $this->registry->available($user),
            static fn (AskTool $tool): bool => !$tool instanceof DraftTool,
        ));
    }

    /**
     * A read-and-write key's writes and drafts that this user can use
     * (their module is on and they can add that kind to some vehicle).
     *
     * @return array<string, DraftTool> by MCP name
     */
    private function writeTools(KeyHolder $holder): array
    {
        if (!$holder->key->scope->canWrite()) {
            return [];
        }
        $tools = [];
        foreach ([...self::WRITES, ...self::DRAFTS] as $name => $kind) {
            $tool = $this->draftTools[$kind->value] ?? null;
            if ($tool !== null && $tool->canDraft($holder->user)) {
                $tools[$name] = $tool;
            }
        }

        return $tools;
    }

    /**
     * `log_fill_up`, `add_reading`: the validated entry written as the API
     * writes it, in one transaction, judged on the data as it is now.
     *
     * @return array<string, mixed>
     */
    private function write(User $user, DraftProposal $proposal): array
    {
        try {
            [$vehicle, $written] = $this->transaction->run(function () use ($user, $proposal): array {
                $vehicle = $this->writer->vehicle($user, $proposal->kind, $proposal->vehicleId);

                return [$vehicle, $this->writer->write($user, $vehicle, $proposal->kind, $proposal->input)];
            });
        } catch (DraftRefused $refused) {
            return self::failure($this->translator->trans($refused->key));
        } catch (DraftInvalid $invalid) {
            $messages = [];
            foreach ($invalid->errors->all() as $field => $error) {
                $messages[$field] = $this->translator->trans($error['key'], $error['params']);
            }

            return self::answer([
                'status' => 'invalid',
                'kind' => $proposal->kind->value,
                'fields' => $messages,
                'say' => $this->translator->trans('ask.draft.say.invalid'),
            ], true);
        }
        assert($written instanceof DraftWritten);

        $route = $proposal->kind === DraftKind::Fuel ? 'fuel.index' : 'odometer.index';
        $link = $this->urls->route($route, ['id' => (string) $vehicle->id]);

        return self::success([
            'status' => $written->duplicate ? 'duplicate' : 'logged',
            'kind' => $proposal->kind->value,
            'entry_id' => $written->entryId,
            'vehicle' => ['id' => $vehicle->id, 'name' => $vehicle->name()],
            'summary' => $written->card['summary'] ?? ($proposal->card['summary'] ?? ''),
            'fields' => $proposal->card['fields'] ?? [],
            'warnings' => $written->card['warnings'] ?? [],
            'say' => $this->translator->trans($written->duplicate ? 'mcp.say.duplicate' : 'mcp.say.logged', [
                'link' => $link,
            ]),
            'link' => $link,
        ]);
    }

    /**
     * The other draft tools: kept as a draft from MCP, for 7 days, until
     * its user presses *Add* in Logbook.
     *
     * @return array<string, mixed>
     */
    private function keep(User $user, DraftProposal $proposal): array
    {
        $id = $this->drafts->create($user, null, $proposal, DraftSource::Mcp);
        $link = $this->urls->route('home') . '#draft-' . $id;
        $card = $proposal->card;

        return self::success([
            'status' => 'draft_saved',
            'kind' => $proposal->kind->value,
            'draft_id' => $id,
            'vehicle' => ['id' => $proposal->vehicleId, 'name' => is_string($card['vehicle'] ?? null) ? $card['vehicle'] : ''],
            'summary' => $card['summary'] ?? '',
            'fields' => $card['fields'] ?? [],
            'warnings' => $card['warnings'] ?? [],
            'notes' => $card['notes'] ?? [],
            'say' => $this->translator->trans('mcp.say.draft_saved', ['link' => $link]),
            'link' => $link,
        ]);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, bool> $annotations
     * @return array<string, mixed>
     */
    private function definition(string $name, array $schema, array $annotations): array
    {
        // JSON objects stay objects when empty.
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new stdClass();
        }

        return [
            'name' => $name,
            'description' => $this->translator->trans('mcp.tool.' . $name),
            'inputSchema' => $schema,
            'annotations' => $annotations,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withLink(array $data, ?string $link): array
    {
        if ($link === null) {
            return $data;
        }

        return $data + [
            'link' => AbsoluteUrl::origin($this->settings->url, $this->settings->basePath) . $this->settings->basePath . $link,
        ];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function rolledBack(callable $work): mixed
    {
        $this->connection->beginTransaction();
        try {
            return $work();
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function success(array $data): array
    {
        return self::answer($data, false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(string $message): array
    {
        return self::answer(['error' => $message], true);
    }

    /**
     * A `CallToolResult`: the data as structured content and as the same
     * JSON in one text block, for clients that only read text.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function answer(array $data, bool $isError): array
    {
        $text = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $data === [] ? new stdClass() : $data,
            'isError' => $isError,
        ];
    }
}
