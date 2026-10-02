<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

/**
 * An update check's error: its code and the values its message needs
 * (strings only, as stored in `updates.status`).
 */
final readonly class UpdateError
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public UpdateErrorCode $code,
        public array $params = [],
    ) {
    }
}
