<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\ErrorCode;
use RuntimeException;

/**
 * A provider call that failed, with a code for the user and the
 * provider's own text for admins. The text may hold a secret until
 * AiGateway redacts it; it is never shown or logged before then.
 */
final class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $error,
        public readonly string $detail = '',
        public readonly ?int $status = null,
    ) {
        parent::__construct($detail === '' ? $error->value : $detail);
    }
}
