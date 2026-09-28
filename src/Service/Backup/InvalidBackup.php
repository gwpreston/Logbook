<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use RuntimeException;

/**
 * A backup archive that cannot be restored (spec.md §7.13). Carries a
 * translation key and its parameters for the page; nothing was changed.
 */
final class InvalidBackup extends RuntimeException
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(sprintf('Invalid backup: %s %s', $key, json_encode($params)));
    }
}
