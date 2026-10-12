<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Repository\BackupRepository;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenFigures;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Everything a backup must carry, of one instance holding the golden
 * household (Phase 45.2's restore matrix): every backed-up table's rows,
 * every upload's hash, and the app's figure for every golden row
 * (expected.csv), read through the services.
 *
 * Rows are normalised so that the same data reads the same on every
 * engine: decimals without trailing zeros ("12.500" and "12.5"), booleans
 * as 1 and 0, timestamps as "Y-m-d H:i:s" in UTC, JSON with its keys
 * sorted (PostgreSQL's jsonb reorders them).
 */
final readonly class InstanceSnapshot
{
    /**
     * @param array<string, list<array<string, string|null>>> $tables
     * @param array<string, string> $files relative path => sha256
     * @param array<string, string> $figures golden row id => the app's figure
     */
    public function __construct(public array $tables, public array $files, public array $figures)
    {
    }

    public static function take(ContainerInterface $container): self
    {
        $repository = $container->get(BackupRepository::class);
        $storage = $container->get(FileStorage::class);
        if (!$repository instanceof BackupRepository || !$storage instanceof FileStorage) {
            throw new RuntimeException('No backup repository or file storage');
        }
        // Figures first: reading them can provide a GB user's HMRC rates, once (spec.md §7.23).
        $golden = new GoldenFigures($container, GoldenHousehold::found($container));
        $figures = [];
        foreach (ExpectedSkeleton::read(dirname(GoldenHousehold::INPUTS) . '/expected.csv') as $row) {
            try {
                $figures[$row['id']] = $golden->actual($row);
            } catch (Throwable $e) {
                $figures[$row['id']] = 'cannot be read: ' . $e->getMessage();
            }
        }

        $tables = [];
        foreach (BackupRepository::TABLES as $table) {
            $tables[$table] = array_map(
                static fn (array $row): array => array_map(self::normal(...), $row),
                $repository->rows($table),
            );
        }
        $files = [];
        foreach ($storage->all() as $relative) {
            $files[$relative] = (string) hash_file('sha256', $storage->absolutePath($relative));
        }

        return new self($tables, $files, $figures);
    }

    public static function fromJson(string $json): self
    {
        /** @var array{tables: array<string, list<array<string, string|null>>>, files: array<string, string>, figures: array<string, string>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self($data['tables'], $data['files'], $data['figures']);
    }

    public function toJson(): string
    {
        return json_encode(
            ['tables' => $this->tables, 'files' => $this->files, 'figures' => $this->figures],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * What differs from another snapshot, one line each (at most $limit
     * per kind), empty when they are the same.
     *
     * @return list<string>
     */
    public function differences(self $other, int $limit = 20): array
    {
        $lines = [];
        foreach ($this->tables as $table => $rows) {
            $theirs = $other->tables[$table] ?? [];
            if (count($rows) !== count($theirs)) {
                $lines[] = sprintf('%s: %d rows, then %d', $table, count($rows), count($theirs));
                continue;
            }
            $shown = 0;
            foreach ($rows as $i => $row) {
                foreach ($row as $column => $value) {
                    $then = $theirs[$i][$column] ?? null;
                    if ($value !== $then && $shown++ < $limit) {
                        $at = sprintf('%s #%s.%s', $table, $row['id'] ?? $i, $column);
                        $lines[] = sprintf('%s: %s, then %s', $at, json_encode($value), json_encode($then));
                    }
                }
            }
        }
        foreach (array_keys($this->files + $other->files) as $file) {
            if (($this->files[$file] ?? null) !== ($other->files[$file] ?? null)) {
                $lines[] = sprintf('file %s: %s, then %s', $file, $this->files[$file] ?? 'missing', $other->files[$file] ?? '-');
            }
        }
        $shown = 0;
        foreach ($this->figures as $id => $figure) {
            if ($figure !== ($other->figures[$id] ?? null) && $shown++ < $limit) {
                $lines[] = sprintf('golden row %s: %s, then %s', $id, $figure, $other->figures[$id] ?? 'missing');
            }
        }

        return $lines;
    }

    private static function normal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === 't' || $value === 'true') {
            return '1';
        }
        if ($value === 'f' || $value === 'false') {
            return '0';
        }
        if (preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?$/', $value) === 1) {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }
        if (($value[0] ?? '') === '{' || ($value[0] ?? '') === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return (string) json_encode(self::sorted($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
