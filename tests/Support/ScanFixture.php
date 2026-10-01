<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * One document of the scan fixture set (tests/Fixtures/scans), as
 * build.php wrote it: the file, how it is stored, the user's locale, the
 * vehicle chosen beforehand or picked, the model's reply and what the form
 * should hold.
 */
final readonly class ScanFixture
{
    public const string DIRECTORY = __DIR__ . '/../Fixtures/scans';

    /**
     * @param array<string, mixed> $reply
     * @param array<string, mixed> $expect
     */
    public function __construct(
        public string $name,
        public string $file,
        /** text_pdf, scan_pdf or photo */
        public string $type,
        public string $locale,
        public ?string $chosen,
        public ?string $pick,
        public array $reply,
        public array $expect,
    ) {
    }

    public static function load(string $name): self
    {
        $json = json_decode((string) file_get_contents(self::DIRECTORY . '/' . $name . '.json'), true);
        Assert::assertIsArray($json, $name);

        return new self(
            $name,
            self::string($json, 'file') ?? '',
            self::string($json, 'type') ?? '',
            self::string($json, 'locale') ?? 'en_GB',
            self::string($json, 'chosen'),
            self::string($json, 'pick'),
            self::map($json['reply'] ?? null),
            self::map($json['expect'] ?? null),
        );
    }

    /**
     * @return list<string> every fixture's name, in order
     */
    public static function names(): array
    {
        $names = array_map(
            static fn (string $path): string => basename($path, '.json'),
            glob(self::DIRECTORY . '/[0-9][0-9]-*.json') ?: [],
        );
        sort($names);

        return $names;
    }

    public function bytes(): string
    {
        return (string) file_get_contents(self::DIRECTORY . '/' . $this->file);
    }

    public function mime(): string
    {
        return str_ends_with($this->file, '.pdf') ? 'application/pdf' : 'image/jpeg';
    }

    public function expected(string $key): ?string
    {
        return self::string($this->expect, $key);
    }

    /**
     * @return array<string, string>
     */
    public function expectedMap(string $key): array
    {
        $out = [];
        foreach (self::map($this->expect[$key] ?? null) as $field => $value) {
            if (is_scalar($value)) {
                $out[$field] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function expectedList(string $key): array
    {
        $list = $this->expect[$key] ?? null;

        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    public function expectedCount(string $key): int
    {
        $count = $this->expect[$key] ?? 0;

        return is_int($count) ? $count : 0;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function string(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
