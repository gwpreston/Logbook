<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreFormContext;
use Logbook\Support\Validation\ValidationErrors;

/**
 * A tyre change body (Phase 39.2, spec.md §7.17, §7.20) as the change
 * form's flat fields (`pos_fl`, `brand_fl`, `move_12`, `tread_12`), and a
 * map from those fields back to the body's paths for the form's errors.
 *
 * Every kind takes `changed_on` (default today), `odometer` with
 * `distance_unit`, `depth_unit`, `note`, `service_record_id`, and `cost`
 * with `vendor` (the kinds that take a cost). The kind's lines:
 *
 * - `existing`: `tyres`, a list of `{position, brand, model, size, season, dot, tread}`;
 * - `fit`: `tyre` `{brand, model, size, season}`, `tread`, and `positions`, a
 *   list of `{position, dot, replace}` (`replace`: `store` or a retire
 *   reason, for a tyre fitted there now);
 * - `swap`: `set` (a set id, or `{name, storage}` for a new one), `on`
 *   (stored tyre id → position) and `depths` (tyre id → depth);
 * - `rotate`: `moves` (fitted tyre id → position);
 * - `repair`: `tyres`, a list of fitted tyre ids;
 * - `remove`: `set`, `tyres` (fitted tyre id → `store` or a retire reason)
 *   and `depths`.
 */
final class TyreInput
{
    /** The kinds the API records here; a tread check is `POST …/tyres/checks`. */
    public const array KINDS = ['existing', 'fit', 'swap', 'rotate', 'repair', 'remove'];
    private const array COMMON = [
        'kind', 'changed_on', 'odometer', 'distance_unit', 'depth_unit', 'note', 'service_record_id', 'cost', 'vendor',
    ];
    private const array LINES = [
        'existing' => ['tyres'],
        'fit' => ['tyre', 'tread', 'positions'],
        'swap' => ['set', 'on', 'depths'],
        'rotate' => ['moves'],
        'repair' => ['tyres'],
        'remove' => ['set', 'tyres', 'depths'],
    ];
    private const string NUMBER = '/^-?[0-9]+(\.[0-9]+)?$/';

    /** @var array<string, string> */
    private array $input = [];
    /** @var array<string, string> form field → body path */
    private array $paths = [
        'done_on' => 'changed_on',
        'link' => 'service_record_id',
        'set_name' => 'set.name',
        'set_location' => 'set.storage',
    ];
    private ValidationErrors $errors;

    /**
     * @param array<string, mixed> $body
     */
    private function __construct(private readonly array $body)
    {
        $this->errors = new ValidationErrors();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, paths: array<string, string>}|ValidationErrors
     */
    public static function map(TyreChangeKind $kind, array $body, string $today, TyreFormContext $context): array|ValidationErrors
    {
        $mapper = new self($body);
        foreach (array_keys($body) as $name) {
            if (!in_array($name, [...self::COMMON, ...(self::LINES[$kind->value] ?? [])], true)) {
                $mapper->errors->add($name, 'api.validation.unknown_field');
            }
        }
        $mapper->input['done_on'] = $mapper->text('changed_on') ?? $today;
        foreach (['odometer', 'cost'] as $name) {
            $mapper->input[$name] = $mapper->number($name, $name);
        }
        $mapper->input['note'] = $mapper->text('note') ?? '';
        $mapper->input['vendor'] = $mapper->text('vendor') ?? '';
        $mapper->input['link'] = $mapper->number('service_record_id', 'service_record_id');

        // Errors on a tyre the body left out still point into the body.
        foreach ([...array_values($context->fitted), ...$context->stored] as $tyre) {
            $id = $tyre->id;
            $mapper->paths += [
                'move_' . $id => 'moves.' . $id,
                'on_' . $id => 'on.' . $id,
                'action_' . $id => 'tyres.' . $id,
                'repair_' . $id => 'tyres',
                'tread_' . $id => 'depths.' . $id,
            ];
        }
        match ($kind) {
            TyreChangeKind::Existing => $mapper->existing(),
            TyreChangeKind::Fit => $mapper->fit($context),
            TyreChangeKind::Swap => $mapper->swap(),
            TyreChangeKind::Rotate => $mapper->moves('moves', 'move_'),
            TyreChangeKind::Repair => $mapper->repair(),
            TyreChangeKind::Remove => $mapper->remove(),
            TyreChangeKind::Check => null,
        };

        return $mapper->errors->isEmpty() ? ['input' => $mapper->input, 'paths' => $mapper->paths] : $mapper->errors;
    }

    /**
     * The form's errors at the body's paths.
     *
     * @param array<string, string> $paths form field → body path
     */
    public static function renamed(ValidationErrors $form, array $paths): ValidationErrors
    {
        $errors = new ValidationErrors();
        foreach ($form->all() as $field => $error) {
            $errors->add($paths[$field] ?? $field, $error['key'], $error['params']);
        }

        return $errors;
    }

    private function existing(): void
    {
        $this->paths['positions'] = 'tyres';
        foreach ($this->lines('tyres') as $index => $line) {
            $position = $this->position($line, 'tyres.' . $index);
            if ($position === null) {
                continue;
            }
            $this->input['pos_' . $position] = '1';
            foreach (['brand', 'model', 'size', 'season', 'dot', 'tread'] as $field) {
                $form = $field . '_' . $position;
                $this->paths[$form] = 'tyres.' . $index . '.' . $field;
                $this->input[$form] = $this->value($line[$field] ?? null, 'tyres.' . $index . '.' . $field);
            }
        }
    }

    private function fit(TyreFormContext $context): void
    {
        $tyre = $this->body['tyre'] ?? [];
        if (!is_array($tyre) || ($tyre !== [] && array_is_list($tyre))) {
            $this->errors->add('tyre', 'api.validation.object');
            $tyre = [];
        }
        foreach (['brand', 'model', 'size', 'season'] as $field) {
            $this->paths[$field] = 'tyre.' . $field;
            $this->input[$field] = $this->value($tyre[$field] ?? null, 'tyre.' . $field);
        }
        $this->input['tread'] = $this->number('tread', 'tread');
        foreach ($this->lines('positions') as $index => $line) {
            $position = $this->position($line, 'positions.' . $index);
            if ($position === null) {
                continue;
            }
            $this->input['pos_' . $position] = '1';
            $this->paths['dot_' . $position] = 'positions.' . $index . '.dot';
            $this->input['dot_' . $position] = $this->value($line['dot'] ?? null, 'positions.' . $index . '.dot');
            if (isset($context->fitted[$position])) {
                $this->paths['replace_' . $position] = 'positions.' . $index . '.replace';
                $this->input['replace_' . $position] = $this->value($line['replace'] ?? null, 'positions.' . $index . '.replace');
            }
        }
    }

    private function swap(): void
    {
        $this->set();
        $this->moves('on', 'on_');
        $this->depths();
    }

    private function repair(): void
    {
        $tyres = $this->body['tyres'] ?? [];
        if (!is_array($tyres) || !array_is_list($tyres)) {
            $this->errors->add('tyres', 'api.validation.list');

            return;
        }
        foreach ($tyres as $id) {
            if (!is_string($id) || !ctype_digit($id)) {
                $this->errors->add('tyres', 'api.validation.list');

                continue;
            }
            $this->input['repair_' . $id] = '1';
        }
    }

    private function remove(): void
    {
        $this->set();
        foreach ($this->byTyre('tyres') as $id => $choice) {
            $this->paths['action_' . $id] = 'tyres.' . $id;
            $this->input['action_' . $id] = $this->value($choice, 'tyres.' . $id);
        }
        $this->depths();
    }

    private function set(): void
    {
        $set = $this->body['set'] ?? null;
        if ($set === null) {
            return;
        }
        if (is_string($set) && ctype_digit($set)) {
            $this->input['set'] = $set;

            return;
        }
        if (!is_array($set) || array_is_list($set)) {
            $this->errors->add('set', 'api.validation.set');

            return;
        }
        $this->input['set'] = TyreChangeForm::NEW_SET;
        $this->input['set_name'] = $this->value($set['name'] ?? null, 'set.name');
        $this->input['set_location'] = $this->value($set['storage'] ?? null, 'set.storage');
    }

    private function moves(string $name, string $prefix): void
    {
        foreach ($this->byTyre($name) as $id => $position) {
            $this->paths[$prefix . $id] = $name . '.' . $id;
            $this->input[$prefix . $id] = $this->value($position, $name . '.' . $id);
        }
    }

    private function depths(): void
    {
        foreach ($this->byTyre('depths') as $id => $depth) {
            $this->paths['tread_' . $id] = 'depths.' . $id;
            $this->input['tread_' . $id] = $this->value($depth, 'depths.' . $id);
        }
    }

    /**
     * An object keyed by tyre id.
     *
     * @return array<int, mixed>
     */
    private function byTyre(string $name): array
    {
        $value = $this->body[$name] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors->add($name, 'api.validation.by_tyre');

            return [];
        }
        $out = [];
        foreach ($value as $id => $item) {
            if (!ctype_digit((string) $id)) {
                $this->errors->add($name, 'api.validation.by_tyre');

                continue;
            }
            $out[(int) $id] = $item;
        }

        return $out;
    }

    /**
     * A list of objects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(string $name): array
    {
        $value = $this->body[$name] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            $this->errors->add($name, 'api.validation.lines');

            return [];
        }
        $lines = [];
        foreach ($value as $index => $line) {
            if (!is_array($line) || ($line !== [] && array_is_list($line))) {
                $this->errors->add($name . '.' . $index, 'api.validation.object');

                continue;
            }
            $object = [];
            foreach ($line as $key => $item) {
                $object[(string) $key] = $item;
            }
            $lines[$index] = $object;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function position(array $line, string $path): ?string
    {
        $position = $line['position'] ?? null;
        if (!is_string($position) || $position === '') {
            $this->errors->add($path . '.position', 'validation.required');

            return null;
        }

        return $position;
    }

    private function text(string $name): ?string
    {
        $value = $this->body[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $this->errors->add($name, 'api.validation.string');

            return '';
        }

        return $value;
    }

    private function number(string $name, string $path): string
    {
        return $this->value($this->body[$name] ?? null, $path, true);
    }

    /**
     * A field's value as the form posts it: text as it is, numbers plain.
     */
    private function value(mixed $value, string $path, bool $number = false): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || ($number && preg_match(self::NUMBER, trim($value)) !== 1)) {
            $this->errors->add($path, $number ? 'validation.number' : 'api.validation.string');

            return '';
        }

        return trim($value);
    }
}
