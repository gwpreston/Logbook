<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use RuntimeException;

/**
 * A tool could not answer; the message goes back to the model as it is
 * ("No vehicle with that id"), in English, as the tool descriptions are.
 */
final class ToolError extends RuntimeException
{
}
