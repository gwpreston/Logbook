<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\ActivityQuery;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The history feed over the API (spec.md §7.16, §7.20 *Phase 39*): the
 * same ActivityFeed the History tab and the fleet history read, newest
 * first, `?kinds=` (comma list), `?since=` / `?until=` (calendar dates in
 * the owner's calendar, both inclusive) and paged by a cursor of its own
 * (entries of different kinds can share a day and an id). Amounts follow
 * EntryAccess::canSeeAmount; a milestone's or a valuation's price needs
 * ViewCosts.
 */
final readonly class ApiHistory
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    public function __construct(
        private ActivityFeed $feed,
        private EntryAccess $entries,
        private ApiReader $reader,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles already resolved for the key's user
     * @return array{items: list<array<string, mixed>>, cursor: ?string}
     * @throws ApiProblem 400 for a parameter that can't be read
     */
    public function page(User $user, array $vehicles, ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $limit = self::limit($params['limit'] ?? null);
        $after = self::after($params['cursor'] ?? null);
        $since = self::day($params, 'since');
        $until = self::day($params, 'until');

        $items = $this->feed->items($user, new ActivityQuery(
            $vehicles,
            self::kinds($params['kinds'] ?? null),
            $since,
            $until?->modify('+1 day'),
        ));
        usort($items, static fn (ActivityItem $a, ActivityItem $b): int => self::key($b) <=> self::key($a));

        $page = [];
        $more = false;
        foreach ($items as $item) {
            if ($after !== null && self::key($item) >= $after) {
                continue;
            }
            if (count($page) === $limit) {
                $more = true;
                break;
            }
            $page[] = $item;
        }
        $last = $page === [] ? null : $page[array_key_last($page)];

        return [
            'items' => array_map(fn (ActivityItem $item): array => $this->item($user, $item), $page),
            'cursor' => $more && $last !== null ? self::encode(self::key($last)) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(User $user, ActivityItem $item): array
    {
        $vehicle = $item->vehicle;
        $amount = $item->amount !== null && $this->entries->canSeeAmount($user, $vehicle, $item->createdBy);
        $price = $item->price !== null && $this->reader->costs($user, $vehicle);

        return [
            'kind' => $item->kind->value,
            'milestone' => $item->milestone?->value,
            'vehicle_id' => $vehicle->id,
            'entry_id' => $item->kind === ActivityKind::Milestone ? null : $item->entryId,
            'date' => Serializer::date($item->date),
            'created_at' => Serializer::instant($item->createdAt),
            'summary' => $item->label !== '' ? $item->label : $this->translator->trans($item->labelKey),
            'amount' => $amount ? Serializer::dec($item->amount, Serializer::QUANTITY_SCALE) : null,
            'price' => $price ? Serializer::dec($item->price, Serializer::QUANTITY_SCALE) : null,
            'currency' => $amount || $price ? $item->currency : null,
            'odometer' => Serializer::dec($item->odometerKm, Serializer::QUANTITY_SCALE),
            'distance_unit' => Serializer::DISTANCE_UNIT,
            'attachments' => $item->files,
            'created_by' => $item->createdBy,
            'links' => ['entry' => self::link($item)],
        ];
    }

    /**
     * The entry's API path under /api/v1, where it has a single read.
     */
    private static function link(ActivityItem $item): ?string
    {
        $list = match ($item->kind) {
            ActivityKind::Fuel => 'fuel',
            ActivityKind::Odometer => 'odometer',
            ActivityKind::Maintenance => 'maintenance',
            ActivityKind::Document => 'documents',
            ActivityKind::Expense => 'expenses',
            ActivityKind::Valuation => 'valuations',
            ActivityKind::Trip => 'trips',
            ActivityKind::Incident => 'incidents',
            ActivityKind::IssueNoticed, ActivityKind::IssueFixed => 'issues',
            ActivityKind::Milestone => '',
            ActivityKind::Tyre => null,
        };

        return match ($list) {
            null => null,
            '' => '/vehicles/' . $item->vehicle->id,
            default => '/vehicles/' . $item->vehicle->id . '/' . $list . '/' . $item->entryId,
        };
    }

    /**
     * Newest first: the owner's date, milestones' rank, when it was added,
     * the id, then the kind, so no two items share a key.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: string}
     */
    private static function key(ActivityItem $item): array
    {
        return [
            $item->date->getTimestamp(),
            $item->milestone?->rank() ?? 0,
            $item->createdAt->getTimestamp(),
            $item->entryId,
            $item->kind->value,
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $key
     */
    private static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode(implode(':', $key)), '+/', '-_'), '=');
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: string}|null
     */
    private static function after(mixed $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $decoded = is_string($cursor) ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        $pattern = '/^(-?[0-9]{1,12}):(-?[0-9]{1,3}):(-?[0-9]{1,12}):([0-9]{1,18}):([a-z_]{1,20})$/';
        if ($decoded === false || preg_match($pattern, $decoded, $m) !== 1) {
            throw ApiProblem::invalidParameter('cursor', 'use the "next" URL of the previous page.');
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], $m[5]];
    }

    private static function limit(mixed $raw): int
    {
        if ($raw === null) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,3}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
            throw ApiProblem::invalidParameter('limit', sprintf('a whole number from 1 to %d.', self::MAX_LIMIT));
        }

        return (int) $raw;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function day(array $params, string $name): ?DateTimeImmutable
    {
        $raw = $params[$name] ?? null;
        if ($raw === null) {
            return null;
        }
        $day = is_string($raw) ? LocalTime::parseDate($raw) : null;
        if ($day === null) {
            throw ApiProblem::invalidParameter($name, 'a date (YYYY-MM-DD).');
        }

        return $day;
    }

    /**
     * @return list<ActivityKind>
     */
    private static function kinds(mixed $raw): array
    {
        if ($raw === null) {
            return ActivityKind::cases();
        }
        $codes = array_map(static fn (ActivityKind $k): string => $k->value, ActivityKind::cases());
        $kinds = [];
        foreach (is_string($raw) ? explode(',', $raw) : [''] as $code) {
            $kinds[] = ActivityKind::tryFrom(trim($code))
                ?? throw ApiProblem::invalidParameter('kinds', 'a comma list of ' . implode(', ', $codes) . '.');
        }

        return array_values(array_unique($kinds, SORT_REGULAR));
    }
}
