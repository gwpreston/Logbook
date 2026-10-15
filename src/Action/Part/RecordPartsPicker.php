<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Service\Part\RecordPartsForm;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * *Parts fitted* and *Parts removed* on a service record's or a
 * modification's form (spec.md §7.39), shown only with the `parts` module
 * on: what the lists offer, their ticks when editing, and reading them back.
 */
final readonly class RecordPartsPicker
{
    public function __construct(
        private PartService $parts,
        private FeatureToggles $features,
    ) {
    }

    public function enabled(): bool
    {
        return $this->features->isEnabled(Feature::Parts);
    }

    /**
     * The template's `record_parts`: null with the module off.
     *
     * @param 'maintenance'|'modification' $kind
     * @param array<array-key, mixed> $input the posted form, for its row counts
     * @return array{record_parts: array{fit: list<Part>, remove: list<Part>, rows: array{new: int, off: int}}|null}
     */
    public function context(Vehicle $vehicle, string $kind, ?int $recordId, array $input = []): array
    {
        if (!$this->enabled()) {
            return ['record_parts' => null];
        }
        $made = $recordId === null ? [] : $this->parts->byRecord($vehicle, $kind, $recordId);
        $choices = RecordPartsForm::choices($this->parts->list($vehicle), $made, $kind, $recordId ?? 0);

        return ['record_parts' => $choices + ['rows' => RecordPartsForm::rows($input)]];
    }

    /**
     * The edit form's ticks for what the record already fits and removes.
     *
     * @param 'maintenance'|'modification' $kind
     * @return array<string, string>
     */
    public function values(Vehicle $vehicle, string $kind, int $recordId): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return RecordPartsForm::values($this->parts->byRecord($vehicle, $kind, $recordId), $kind, $recordId);
    }

    /**
     * The posted lists: null when they weren't on the form (or the module
     * is off), else the lists or their errors.
     *
     * @param 'maintenance'|'modification' $kind
     * @param array<array-key, mixed> $input
     */
    public function read(
        Vehicle $vehicle,
        string $kind,
        ?int $recordId,
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
    ): RecordParts|ValidationErrors|null {
        if (!$this->enabled() || !RecordPartsForm::sent($input)) {
            return null;
        }
        $made = $recordId === null ? [] : $this->parts->byRecord($vehicle, $kind, $recordId);
        $choices = RecordPartsForm::choices($this->parts->list($vehicle), $made, $kind, $recordId ?? 0);
        $validator = new Validator($input, $preferences->locale);
        $parts = RecordPartsForm::read($validator, $input, $preferences, $today, $choices['fit'], $choices['remove']);

        return $parts ?? $validator->errors();
    }
}
