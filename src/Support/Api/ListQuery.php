<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\Date\LocalTime;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A list endpoint's paging and filter (spec.md §7.20): `limit` (1–200,
 * default 50), an opaque `cursor`, and `since` / `until` as a date or an
 * instant. Lists are newest first; the cursor names the last item of the
 * page before, so an entry added meanwhile never shifts a page.
 *
 * Every list is read whole by its service (the economy of a fill-up needs
 * the full history anyway) and paged here.
 */
final readonly class ListQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    private function __construct(
        public int $limit,
        /** @var array{0: int, 1: int}|null the last item seen: [timestamp, id] */
        private ?array $after,
        /** Inclusive, as a UTC timestamp. */
        private ?int $since,
        /** Inclusive, as a UTC timestamp. */
        private ?int $until,
    ) {
    }

    /**
     * @throws ApiProblem for a parameter that cannot be read
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $query = $request->getQueryParams();

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $query['limit'] ?? null;
        if ($rawLimit !== null) {
            if (
                !is_string($rawLimit) || preg_match('/^[0-9]{1,3}$/', $rawLimit) !== 1
                || (int) $rawLimit < 1 || (int) $rawLimit > self::MAX_LIMIT
            ) {
                throw ApiProblem::invalidParameter('limit', sprintf('a whole number from 1 to %d.', self::MAX_LIMIT));
            }
            $limit = (int) $rawLimit;
        }

        $after = null;
        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null) {
            $after = is_string($cursor) ? self::decodeCursor($cursor) : null;
            if ($after === null) {
                throw ApiProblem::invalidParameter('cursor', 'use the "next" URL of the previous page.');
            }
        }

        $since = self::bound($query, 'since', false);
        $until = self::bound($query, 'until', true);

        return new self($limit, $after, $since, $until);
    }

    /**
     * One page of a list, newest first.
     *
     * @template T
     * @param list<T> $items in any order
     * @param callable(T): array{0: DateTimeImmutable, 1: int} $key the item's date or instant, and its id
     * @return array{items: list<T>, cursor: ?string} the page, and the cursor of the next one
     */
    public function page(array $items, callable $key): array
    {
        $keyed = [];
        foreach ($items as $item) {
            [$at, $id] = $key($item);
            $keyed[] = [$at->getTimestamp(), $id, $item];
        }
        usort($keyed, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        $page = [];
        $last = null;
        $more = false;
        foreach ($keyed as [$at, $id, $item]) {
            if (($this->since !== null && $at < $this->since) || ($this->until !== null && $at > $this->until)) {
                continue;
            }
            if ($this->after !== null && [$at, $id] >= $this->after) {
                continue;
            }
            if (count($page) === $this->limit) {
                $more = true;
                break;
            }
            $page[] = $item;
            $last = [$at, $id];
        }

        return ['items' => $page, 'cursor' => $more && $last !== null ? self::encodeCursor($last) : null];
    }

    /**
     * @param array{0: int, 1: int} $after
     */
    private static function encodeCursor(array $after): string
    {
        return rtrim(strtr(base64_encode($after[0] . ':' . $after[1]), '+/', '-_'), '=');
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match('/^(-?[0-9]{1,12}):([0-9]{1,18})$/', $decoded, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * `since` / `until`: a date (the whole day, in UTC) or an instant.
     *
     * @param array<array-key, mixed> $query
     */
    private static function bound(array $query, string $name, bool $isEnd): ?int
    {
        $value = $query[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiProblem::invalidParameter($name, 'a date (YYYY-MM-DD) or an instant (2026-09-29T07:42:00Z).');
        }

        $date = LocalTime::parseDate($value);
        if ($date !== null) {
            return $isEnd ? $date->getTimestamp() + 86399 : $date->getTimestamp();
        }
        $instant = self::instant($value);
        if ($instant === null) {
            throw ApiProblem::invalidParameter($name, 'a date (YYYY-MM-DD) or an instant (2026-09-29T07:42:00Z).');
        }

        return $instant->getTimestamp();
    }

    /**
     * An ISO 8601 instant with a zone ("2026-09-29T07:42:00Z",
     * "2026-09-29T08:42+01:00"), in UTC; null for anything else.
     */
    public static function instant(string $value): ?DateTimeImmutable
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/i';
        if (preg_match($pattern, $value) !== 1 || LocalTime::parseDate(substr($value, 0, 10)) === null) {
            return null;
        }
        try {
            $parsed = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
}
