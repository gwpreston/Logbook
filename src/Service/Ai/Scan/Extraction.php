<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanKind;

/**
 * What was read from a file (spec.md §7.27): the kind, and each field's
 * value as printed with the words it came from. Validated against
 * ScanSchema, scrubbed and evidence-checked before it is built, and stored
 * as toArray() on the pending upload.
 */
final readonly class Extraction
{
    /**
     * @param array<string, array{value: string, evidence: string}> $fields
     * @param array<string, list<string>> $lines
     * @param list<array{text: string, distance: ?string, distance_unit: ?string, date: ?string}> $recommendations
     */
    public function __construct(
        public ScanKind $kind,
        public array $fields = [],
        public array $lines = [],
        public array $recommendations = [],
    ) {
    }

    public function value(string $name): ?string
    {
        $value = $this->fields[$name]['value'] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function evidence(string $name): ?string
    {
        $evidence = $this->fields[$name]['evidence'] ?? '';

        return $evidence === '' ? null : $evidence;
    }

    /**
     * @return list<string>
     */
    public function lines(string $name): array
    {
        return $this->lines[$name] ?? [];
    }

    /**
     * The same reading taken as another kind ("This is a fuel receipt").
     */
    public function as(ScanKind $kind): self
    {
        return new self($kind, $this->fields, $this->lines, $this->recommendations);
    }

    /**
     * From the model's object (already checked against the schema). Strings
     * are trimmed and capped; a registration document is scrubbed of
     * 11-digit runs; with $text (a text PDF) a value whose evidence is not
     * in the text is dropped.
     *
     * @param array<string, mixed> $object
     */
    public static function fromObject(array $object, ?string $text = null): self
    {
        $kind = ScanKind::tryFrom(is_string($object['kind'] ?? null) ? $object['kind'] : '') ?? ScanKind::Other;
        if ($kind === ScanKind::Registration) {
            $object = (array) Scrubber::value($object);
        }
        $haystack = $text === null ? null : self::normal($text);

        $fields = [];
        $given = is_array($object['fields'] ?? null) ? $object['fields'] : [];
        foreach (ScanSchema::FIELDS as $name => $_) {
            $field = $given[$name] ?? null;
            if (!is_array($field)) {
                continue;
            }
            $value = self::text($field['value'] ?? null);
            $evidence = self::text($field['evidence'] ?? null);
            if ($value === '') {
                continue;
            }
            if ($haystack !== null && $evidence !== '' && !str_contains($haystack, self::normal($evidence))) {
                continue;
            }
            $fields[$name] = ['value' => $value, 'evidence' => $evidence];
        }

        $lines = [];
        $givenLines = is_array($object['lines'] ?? null) ? $object['lines'] : [];
        foreach (ScanSchema::LINES as $name => $_) {
            $list = [];
            foreach (is_array($givenLines[$name] ?? null) ? $givenLines[$name] : [] as $line) {
                $line = self::text($line);
                if ($line !== '') {
                    $list[] = $line;
                }
            }
            if ($list !== []) {
                $lines[$name] = $list;
            }
        }

        $recommendations = [];
        foreach (is_array($givenLines['recommendations'] ?? null) ? $givenLines['recommendations'] : [] as $item) {
            if (!is_array($item) || self::text($item['text'] ?? null) === '') {
                continue;
            }
            $recommendations[] = [
                'text' => self::text($item['text'] ?? null),
                'distance' => self::text($item['distance'] ?? null) ?: null,
                'distance_unit' => self::text($item['distance_unit'] ?? null) ?: null,
                'date' => self::text($item['date'] ?? null) ?: null,
            ];
        }

        return new self($kind, $fields, $lines, $recommendations);
    }

    /**
     * @return array{
     *     kind: string,
     *     fields: array<string, array{value: string, evidence: string}>,
     *     lines: array<string, list<string>>,
     *     recommendations: list<array{text: string, distance: ?string, distance_unit: ?string, date: ?string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'fields' => $this->fields,
            'lines' => $this->lines,
            'recommendations' => $this->recommendations,
        ];
    }

    /**
     * A stored extraction (the pending upload's result), or null.
     *
     * @param array<string, mixed>|null $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null || !is_string($stored['kind'] ?? null)) {
            return null;
        }

        return self::fromObject([
            'kind' => $stored['kind'],
            'fields' => $stored['fields'] ?? [],
            'lines' => ['recommendations' => $stored['recommendations'] ?? []]
                + (is_array($stored['lines'] ?? null) ? $stored['lines'] : []),
        ]);
    }

    private static function text(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, ScanSchema::MAX_TEXT);
    }

    private static function normal(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
