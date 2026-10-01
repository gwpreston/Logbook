<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Doctrine\DBAL\Connection;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The fixed set of Ask tools (spec.md §7.26). Only tools whose module is
 * on are offered; a call to any other name is an error for the model.
 * Each call runs as the asking user, in their units and language, inside a
 * transaction that is always rolled back: the tools only read, and even a
 * write hidden in a service they call never lands.
 */
final readonly class ToolRegistry
{
    /** @var array<string, AskTool> */
    private array $tools;

    /**
     * @param iterable<AskTool> $tools
     */
    public function __construct(
        iterable $tools,
        private Connection $connection,
        private UserDisplayScope $display,
        private LoggerInterface $logger,
    ) {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }
        $this->tools = $byName;
    }

    /**
     * @return list<AskTool> the tools offered to this user
     */
    public function available(User $user): array
    {
        return array_values(array_filter($this->tools, static fn (AskTool $tool): bool => $tool->isAvailable($user)));
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(User $user): array
    {
        return array_map(static fn (AskTool $tool): ToolDefinition => $tool->definition(), $this->available($user));
    }

    /**
     * @return list<string> every tool's name, offered or not
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function run(User $user, ToolCall $call): ToolRun
    {
        $tool = $this->tools[$call->name] ?? null;
        if ($tool === null || !$tool->isAvailable($user)) {
            $error = sprintf('There is no tool called "%s".', $call->name);

            return new ToolRun($call->id, $call->name, $call->arguments, null, $error);
        }

        try {
            $result = $this->display->run($user, fn (): ToolResult => $this->readOnly(
                static fn (): ToolResult => $tool->run($user, new ToolArguments($call->arguments)),
            ));
        } catch (ToolError $e) {
            return new ToolRun($call->id, $call->name, $call->arguments, null, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Ask tool failed', ['tool' => $call->name, 'exception' => $e]);

            return new ToolRun($call->id, $call->name, $call->arguments, null, 'The tool failed. Answer without it.');
        }

        return new ToolRun($call->id, $call->name, $call->arguments, $result, null, $result->vehicleIds);
    }

    /**
     * @param callable(): ToolResult $work
     */
    private function readOnly(callable $work): ToolResult
    {
        $this->connection->beginTransaction();
        try {
            return $work();
        } finally {
            $this->connection->rollBack();
        }
    }
}
