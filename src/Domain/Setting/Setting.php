<?php

declare(strict_types=1);

namespace Logbook\Domain\Setting;

use DateTimeImmutable;

final readonly class Setting
{
    public function __construct(
        public string $name,
        public SettingScope $scope,
        public int $ownerId,
        /** Any JSON-serialisable value. */
        public mixed $value,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
