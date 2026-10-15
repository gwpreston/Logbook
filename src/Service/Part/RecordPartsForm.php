<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\Validator;

/**
 * *Parts fitted* and *Parts removed* on a service record's or a
 * modification's form (spec.md §7.39 *Fitting parts from a record*), as flat
 * fields so the form re-fills without JS:
 *
 * - `parts_lists=1` says the lists were on the form (else parts are left alone);
 * - `fit_{id}=1` per stored part fitted (or already fitted by this record);
 * - `remove_{id}=1`, `remove_{id}_outcome`, `remove_{id}_sold_price` per
 *   fitted part removed;
 * - `new_rows` and `new_{i}_{field}` per new part row, a row with no name
 *   being left out; `off_rows` and `off_{i}_{field}` per *Record what came
 *   off* row.
 */
final class RecordPartsForm
{
    public const int MAX_ROWS = 20;

    /**
     * Whether the posted form carried the parts lists.
     *
     * @param array<array-key, mixed> $input
     */
    public static function sent(array $input): bool
    {
        return ($input['parts_lists'] ?? '') === '1';
    }

    /**
     * Which "Add another" button was pressed (re-render, don't save), if any.
     *
     * @param array<array-key, mixed> $input
     */
    public static function more(array $input): ?string
    {
        $more = $input['parts_more'] ?? null;

        return $more === 'new' || $more === 'off' ? $more : null;
    }

    /**
     * The row counts to render: as posted (at least one each), plus one for "Add another".
     *
     * @param array<array-key, mixed> $input
     * @return array{new: int, off: int}
     */
    public static function rows(array $input): array
    {
        $more = self::more($input);

        return [
            'new' => min(self::MAX_ROWS, self::count($input, 'new_rows') + ($more === 'new' ? 1 : 0)),
            'off' => min(self::MAX_ROWS, self::count($input, 'off_rows') + ($more === 'off' ? 1 : 0)),
        ];
    }

    /**
     * The edit form's ticks for what the record already fits and removes.
     *
     * @param list<array{part: Part, fitting: PartFitting}> $made the record's fittings (PartService::byRecord)
     * @return array<string, string>
     */
    public static function values(array $made, string $kind, int $recordId): array
    {
        $values = [];
        foreach ($made as ['part' => $part, 'fitting' => $fitting]) {
            $fitted = $kind === PartService::MAINTENANCE ? $fitting->fittedMaintenanceId : $fitting->fittedModificationId;
            $removed = $kind === PartService::MAINTENANCE ? $fitting->removedMaintenanceId : $fitting->removedModificationId;
            if ($fitted === $recordId && !$fitting->fittedByRevert) {
                $values['fit_' . $part->id] = '1';
            }
            if ($removed === $recordId && !$fitting->removedByRevert) {
                $values['remove_' . $part->id] = '1';
                $values['remove_' . $part->id . '_outcome'] = ($fitting->outcome ?? RemovalOutcome::Kept)->value;
                $price = $fitting->soldPrice;
                $values['remove_' . $part->id . '_sold_price'] = $price === null ? '' : Decimal::trim($price);
            }
        }

        return $values;
    }

    /**
     * The lists, or null (errors left on the validator) when a row doesn't
     * validate. Only parts offered on the form are taken: $fittable (stored,
     * or fitted by this record) and $removable (fitted, or removed by it).
     *
     * @param array<array-key, mixed> $input
     * @param list<Part> $fittable
     * @param list<Part> $removable
     */
    public static function read(
        Validator $validator,
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        array $fittable,
        array $removable,
    ): ?RecordParts {
        $fit = [];
        foreach ($fittable as $part) {
            if ($validator->checkbox('fit_' . $part->id)) {
                $fit[] = $part->id;
            }
        }
        $remove = [];
        foreach ($removable as $part) {
            if ($validator->checkbox('remove_' . $part->id)) {
                $removal = PartForm::removal($validator, 'remove_' . $part->id . '_');
                if ($removal !== null) {
                    $remove[$part->id] = $removal;
                }
            }
        }

        $new = [];
        for ($i = 0, $n = self::count($input, 'new_rows'); $i < $n; $i++) {
            $prefix = 'new_' . $i . '_';
            if (!self::filled($input, $prefix)) {
                continue;
            }
            $data = PartForm::read($validator, $preferences, $today, $prefix);
            if ($data !== null) {
                $new[] = $data;
            }
        }
        $off = [];
        for ($i = 0, $n = self::count($input, 'off_rows'); $i < $n; $i++) {
            $prefix = 'off_' . $i . '_';
            if (!self::filled($input, $prefix)) {
                continue;
            }
            $name = $validator->string($prefix . 'name', true, PartForm::NAME_MAX);
            $brand = $validator->string($prefix . 'brand', false, PartForm::CODE_MAX);
            $number = $validator->string($prefix . 'part_number', false, PartForm::CODE_MAX);
            $removal = PartForm::removal($validator, $prefix);
            if ($name !== null && $removal !== null) {
                $off[] = new CameOff(new PartData(name: $name, brand: $brand, partNumber: $number), $removal);
            }
        }

        if (!$validator->errors()->isEmpty()) {
            return null;
        }

        return new RecordParts($new, $fit, $remove, $off);
    }

    /**
     * The parts the lists offer: stored ones (and those this record fitted)
     * to fit; fitted ones (and those it removed) to remove.
     *
     * @param list<Part> $parts the vehicle's
     * @param list<array{part: Part, fitting: PartFitting}> $made the record's fittings
     * @return array{fit: list<Part>, remove: list<Part>}
     */
    public static function choices(array $parts, array $made, string $kind, int $recordId): array
    {
        $values = self::values($made, $kind, $recordId);
        $fit = [];
        $remove = [];
        foreach ($parts as $part) {
            if ($part->status === PartStatus::Stored || isset($values['fit_' . $part->id])) {
                $fit[] = $part;
            }
            if ($part->status === PartStatus::Fitted || isset($values['remove_' . $part->id])) {
                $remove[] = $part;
            }
        }

        return ['fit' => $fit, 'remove' => $remove];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private static function count(array $input, string $field): int
    {
        $raw = $input[$field] ?? '1';

        return is_string($raw) && ctype_digit($raw) ? max(1, min(self::MAX_ROWS, (int) $raw)) : 1;
    }

    /**
     * A row counts once any of its text fields has something in it.
     *
     * @param array<array-key, mixed> $input
     */
    private static function filled(array $input, string $prefix): bool
    {
        foreach (['name', 'brand', 'part_number', 'supplier', 'price', 'sold_price'] as $field) {
            $value = $input[$prefix . $field] ?? '';
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
