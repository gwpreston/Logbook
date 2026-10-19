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
     * @param list<string> $notOnPage fields OCR's cross-check didn't find where it read confidently (Phase 53.3)
     * @param array{found: int, flagged: int, unchecked: int}|null $crossCheck the cross-check's counts, when it ran
     * @param array<string, self> $alternatives read without AI (Phase 53.4): each other kind's own reading of
     *     the same text, keyed by kind, so *Read it as* maps that kind's values
     */
    public function __construct(
        public ScanKind $kind,
        public array $fields = [],
        public array $lines = [],
        public array $recommendations = [],
        public array $dropped = [],
        public array $hardToRead = [],
        public ?ReadBy $readBy = null,
        public array $notOnPage = [],
        public ?array $crossCheck = null,
        public array $alternatives = [],
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

    public function isNotOnPage(string $name): bool
    {
        return in_array($name, $this->notOnPage, true);
    }

    /**
     * Each value looked for in OCR's text of the same file (spec.md §7.27
     * *The cross-check*, #508): a value not found where OCR read
     * confidently is kept and marked; the counts go to the usage log.
     */
    public function crossChecked(OcrText $ocr): self
    {
        $counts = ['found' => 0, 'flagged' => 0, 'unchecked' => 0];
        $flagged = [];
        foreach ($this->fields as $name => $field) {
            $result = $ocr->check($field['value'], $field['evidence']);
            $counts[$result->value]++;
            if ($result === CrossCheck::Flagged) {
                $flagged[] = $name;
            }
        }

        return new self(
            $this->kind,
            $this->fields,
            $this->lines,
            $this->recommendations,
            $this->dropped,
            $this->hardToRead,
            $this->readBy,
            $flagged,
            $counts,
            $this->alternatives,
        );
    }

    /**
     * The same reading taken as another kind ("This is a fuel receipt").
     */
    public function as(ScanKind $kind): self
    {
        $own = $this->alternatives[$kind->value] ?? null;
        if ($own !== null) {
            // Read without AI: that kind's own reading of the same text, keeping this one to come back to.
            $alternatives = $this->alternatives;
            unset($alternatives[$kind->value]);
            $alternatives[$this->kind->value] = new self(
                $this->kind,
                $this->fields,
                $this->lines,
                $this->recommendations,
                $this->dropped,
                $this->hardToRead,
            );

            return new self(
                $kind,
                $own->fields,
                $own->lines,
                $own->recommendations,
                $own->dropped,
                $own->hardToRead,
                $this->readBy,
                $own->notOnPage,
                $own->crossCheck,
                $alternatives,
            );
        }

        return new self(
            $kind,
            $this->fields,
            $this->lines,
            $this->recommendations,
            $this->dropped,
            $this->hardToRead,
            $this->readBy,
            $this->notOnPage,
            $this->crossCheck,
            $this->alternatives,
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
            $this->notOnPage,
            $this->crossCheck,
            $this->alternatives,
        );
    }

    /**
     * With each other kind's own reading (read without AI, Phase 53.4).
     *
     * @param array<string, self> $alternatives keyed by kind
     */
    public function withAlternatives(array $alternatives): self
    {
        unset($alternatives[$this->kind->value]);

        return new self(
            $this->kind,
            $this->fields,
            $this->lines,
            $this->recommendations,
            $this->dropped,
            $this->hardToRead,
            $this->readBy,
            $this->notOnPage,
            $this->crossCheck,
            // Only what a reading of that kind holds, never a further level.
            array_map(
                static fn (self $a): self
                    => new self($a->kind, $a->fields, $a->lines, $a->recommendations, $a->dropped, $a->hardToRead),
                $alternatives,
            ),
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
     *     not_on_page: list<string>,
     *     cross_check: array{found: int, flagged: int, unchecked: int}|null,
     *     alternatives?: array<string, array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        $array = [
            'kind' => $this->kind->value,
            'fields' => $this->fields,
            'lines' => $this->lines,
            'recommendations' => $this->recommendations,
            'dropped' => $this->dropped,
            'hard_to_read' => $this->hardToRead,
            'read_by' => $this->readBy?->toArray(),
            'not_on_page' => $this->notOnPage,
            'cross_check' => $this->crossCheck,
        ];

        return $this->alternatives === []
            ? $array
            : $array + ['alternatives' => array_map(static fn (self $a): array => $a->toArray(), $this->alternatives)];
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

        $notOnPage = array_values(array_filter(
            is_array($stored['not_on_page'] ?? null) ? $stored['not_on_page'] : [],
            static fn (mixed $name): bool => is_string($name) && isset($read->fields[$name]),
        ));
        $counts = is_array($stored['cross_check'] ?? null) ? $stored['cross_check'] : null;
        $count = static fn (string $key): int => is_int($counts[$key] ?? null) ? $counts[$key] : 0;

        $alternatives = [];
        foreach (is_array($stored['alternatives'] ?? null) ? $stored['alternatives'] : [] as $kind => $alternative) {
            $other = is_string($kind) && ScanKind::tryFrom($kind) !== null && is_array($alternative)
                ? self::fromStored(['kind' => $kind] + self::keyed($alternative))
                : null;
            if ($other !== null && $kind !== $read->kind->value) {
                $alternatives[$kind] = $other;
            }
        }

        return new self(
            $read->kind,
            $read->fields,
            $read->lines,
            $read->recommendations,
            $dropped,
            $hard,
            ReadBy::fromArray(is_array($stored['read_by'] ?? null) ? $stored['read_by'] : null),
            $notOnPage,
            $counts === null ? null : [
                'found' => $count('found'),
                'flagged' => $count('flagged'),
                'unchecked' => $count('unchecked'),
            ],
            $alternatives,
        );
    }

    /**
     * @param array<mixed> $array
     * @return array<string, mixed>
     */
    private static function keyed(array $array): array
    {
        return array_filter($array, static fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
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
