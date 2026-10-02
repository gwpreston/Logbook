<?php

declare(strict_types=1);

namespace Logbook\Domain\Station;

use DateTimeImmutable;

/**
 * One of a user's saved places (Home, Work, ...; spec.md §6 Place, §7.33).
 * Private to its user: never in the API, Ask, print views, the sale pack or
 * another user's pages.
 */
final readonly class Place
{
    public function __construct(
        public int $id,
        public int $userId,
        public PlaceData $data,
        public int $sortOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
