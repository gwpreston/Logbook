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
     * @param array<string, string> $dropped field → the evidence that wasn't found in the file's text
     * @param list<string> $hardToRead fields whose evidence OCR read with low confidence
     */
    public function __construct(
        public ScanKind $kind,
        public array $fields = [],
        public array $lines = [],
        public array $recommendations = [],
        public array $dropped = [],
        public array $hardToRead = [],
        public ?ReadBy $readBy = null,
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
     * The evidence of a value dropped because it wasn't in the file's text.
     */
    public function dropped(string $name): ?string
    {
        return $this->dropped[$name] ?? null;
    }

    public function isHardToRead(string $name): bool
    {
        return in_array($name, $this->hardToRead, true);
    }

    /**
     * The same reading taken as another kind ("This is a fuel receipt").
     */
    public function as(ScanKind $kind): self
    {
        return new self(
            $kind,
            $this->fields,
            $this->lines,
            $this->recommendations,
            $this->dropped,
            $this->hardToRead,
            $this->readBy,
        );
    }

    public function withReadBy(ReadBy $readBy): self
    {
        return new self(
            $this->kind,
            $this->fields,
            $this->lines,
            $this->recommendations,
            $this->dropped,
            $this->hardToRead,
            $readBy,
        );
    }

    /**
     * From the model's object (already checked against the schema). Strings
     * are trimmed and capped; a registration document is scrubbed of
     * 11-digit runs; with $text (a text PDF's text, or OCR's) a value whose
     * evidence is not in the text is dropped, and noted. Against OCR text
     * the match is tolerant and a value read with low confidence is marked.
     *
     * @param array<string, mixed> $object
     */
    public static function fromObject(array $object, string|OcrText|null $text = null): self
    {
        $kind = ScanKind::tryFrom(is_string($object['kind'] ?? null) ? $object['kind'] : '') ?? ScanKind::Other;
        if ($kind === ScanKind::Registration) {
            $object = (array) Scrubber::value($object);
        }
        $haystack = is_string($text) ? self::normal($text) : null;

        $fields = [];
        $dropped = [];
        $hard = [];
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
                $dropped[$name] = $evidence;
                continue;
            }
            if ($text instanceof OcrText) {
                // Without evidence the value itself is looked for, to judge how well it was read.
                $confidence = $text->find($evidence !== '' ? $evidence : $value);
                if ($confidence === null && $evidence !== '') {
                    $dropped[$name] = $evidence;
                    continue;
                }
                if ($confidence !== null && $confidence < OcrText::HARD_TO_READ) {
                    $hard[] = $name;
                }
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

        return new self($kind, $fields, $lines, $recommendations, $dropped, $hard);
    }

    /**
     * @return array{
     *     kind: string,
     *     fields: array<string, array{value: string, evidence: string}>,
     *     lines: array<string, list<string>>,
     *     recommendations: list<array{text: string, distance: ?string, distance_unit: ?string, date: ?string}>,
     *     dropped: array<string, string>,
     *     hard_to_read: list<string>,
     *     read_by: array<string, string>|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'fields' => $this->fields,
            'lines' => $this->lines,
            'recommendations' => $this->recommendations,
            'dropped' => $this->dropped,
            'hard_to_read' => $this->hardToRead,
            'read_by' => $this->readBy?->toArray(),
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

        $read = self::fromObject([
            'kind' => $stored['kind'],
            'fields' => $stored['fields'] ?? [],
            'lines' => ['recommendations' => $stored['recommendations'] ?? []]
                + (is_array($stored['lines'] ?? null) ? $stored['lines'] : []),
        ]);
        $dropped = [];
        foreach (is_array($stored['dropped'] ?? null) ? $stored['dropped'] : [] as $name => $evidence) {
            if (isset(ScanSchema::FIELDS[$name]) && is_string($evidence)) {
                $dropped[$name] = self::text($evidence);
            }
        }
        $hard = array_values(array_filter(
            is_array($stored['hard_to_read'] ?? null) ? $stored['hard_to_read'] : [],
            static fn (mixed $name): bool => is_string($name) && isset($read->fields[$name]),
        ));

        return new self(
            $read->kind,
            $read->fields,
            $read->lines,
            $read->recommendations,
            $dropped,
            $hard,
            ReadBy::fromArray(is_array($stored['read_by'] ?? null) ? $stored['read_by'] : null),
        );
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
