<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Provider\ToolDefinition;

/**
 * One read-only tool Ask Logbook offers a model (spec.md §7.26 *Tools*).
 * A tool calls an existing service as the asking user, through the same
 * access policy as the pages, and returns raw values, display strings and
 * a link. No tool writes anything.
 */
interface AskTool
{
    public function name(): string;

    /**
     * The name, a description for the model, and the arguments as a JSON
     * Schema object.
     */
    public function definition(): ToolDefinition;

    /**
     * Whether the tool is offered at all: its module is on.
     */
    public function isAvailable(User $user): bool;

    /**
     * @throws ToolError with a plain message for the model
     */
    public function run(User $user, ToolArguments $arguments): ToolResult;
}
