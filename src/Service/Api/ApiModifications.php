<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Part\ModificationForm;
use Logbook\Service\Part\ModificationNotFound;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Service\Part\RecordPartsForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\ListQuery;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Psr\Clock\ClockInterface;

/**
 * The modification endpoints (spec.md §7.20 *Parts and modifications*, §7.39): lists, one
 * modification with the parts it made, and the writes the pages make, each through the page's
 * own form parser and ModificationService. A cost needs `ViewCosts` (#429): without it a write
 * that sends one is ignored. Editing and deleting add `EntryAccess` (ApiEditor).
 */
final readonly class ApiModifications
{
    public function __construct(
        private ModificationService $modifications,
        private PartService $parts,
        private ValidationProblem $validation,
        private EntityTag $tags,
        private ClockInterface $clock,
        private VehicleAccess $access,
        private VehicleService $vehicles,
    ) {
    }

    /**
     * @param 'current'|'reverted'|null $state
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function list(User $user, Vehicle $vehicle, ListQuery $query, ?string $state): array
    {
        $all = array_values(array_filter(
            $this->modifications->list($vehicle),
            static fn (Modification $m): bool => match ($state) {
                'current' => !$m->isReverted(),
                'reverted' => $m->isReverted(),
                default => true,
            },
        ));
        $page = $query->page($all, static fn (Modification $m): array => [$m->data->doneOn, $m->id]);
        $costs = $this->costs($user, $vehicle);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $made = $this->madeFor($vehicle);

        return [
            'items' => array_map(
                static fn (Modification $m): array => Serializer::modification($m, $made[$m->id] ?? [], $currency, $costs),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * @throws ApiProblem 404
     */
    public function state(Vehicle $vehicle, int $id): ModificationState
    {
        try {
            $modification = $this->modifications->get($vehicle, $id);
        } catch (ModificationNotFound) {
            throw ApiProblem::notFound('The vehicle has no such entry.');
        }

        return new ModificationState($modification, $this->parts->byRecord($vehicle, PartService::MODIFICATION, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, Vehicle $vehicle, ModificationState $state): array
    {
        return Serializer::modification(
            $state->modification,
            $state->made,
            $this->vehicles->currencyFor($user, $vehicle),
            $this->costs($user, $vehicle),
        );
    }

    public function tag(ModificationState $state): string
    {
        return $this->tags->of($state);
    }

    /**
     * @throws ApiProblem 412
     */
    public function precondition(?string $ifMatch, ModificationState $state): void
    {
        if ($ifMatch !== null && !EntityTag::matches($ifMatch, $this->tag($state))) {
            throw new ApiProblem(
                412,
                'precondition_failed',
                'The entry changed since it was read (If-Match does not match its ETag); nothing was written.',
            );
        }
    }

    /**
     * Log a modification, with its parts lists, in one transaction. A retry with the same date and
     * title finds the one already logged (spec.md §7.20).
     *
     * @param array<string, mixed> $body
     * @return array{entry: array<string, mixed>, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function log(User $user, Vehicle $vehicle, array $body): array
    {
        ApiWriter::assertActive($vehicle);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $body = $this->guardCosts($user, $vehicle, $body);
        $mapped = $this->mapped(JsonInput::modification($body, $user->preferences, $today, true));
        $data = ModificationForm::parse($mapped['input'], $mapped['preferences'], $today);
        $parts = $this->lists($vehicle, $mapped, $today, null);
        $errors = new ValidationErrors();
        if ($data instanceof ValidationErrors) {
            $errors = $errors->merge(JsonInput::renamed($data, JsonInput::MODIFICATION_FIELDS));
        }
        if ($parts instanceof ValidationErrors) {
            $errors = $errors->merge($parts);
        }
        if (!$errors->isEmpty()) {
            throw $this->validation->of($errors);
        }
        if ($data instanceof ValidationErrors || $parts instanceof ValidationErrors) {
            throw new \LogicException('Errors were merged above.');
        }
        foreach ($this->modifications->list($vehicle) as $stored) {
            $same = $stored->data->title === $data->title
                && $stored->data->doneOn->format('Y-m-d') === $data->doneOn->format('Y-m-d');
            if ($same) {
                $entry = $this->serialize($user, $vehicle, $this->state($vehicle, $stored->id));

                return ['entry' => $entry, 'duplicate' => true, 'warnings' => []];
            }
        }
        try {
            $modification = $this->modifications->create($vehicle, $data, $zone, parts: $parts);
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }

        return [
            'entry' => $this->serialize($user, $vehicle, $this->state($vehicle, $modification->id)),
            'duplicate' => false,
            'warnings' => [],
        ];
    }

    /**
     * `PATCH`: the sent fields over the stored modification, through the edit form. Called by
     * ApiEditor after its access, archive and `If-Match` checks. The parts lists are not patchable.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     * @throws ApiProblem 422
     */
    public function patch(User $user, Vehicle $vehicle, ModificationState $state, array $body): array
    {
        $modification = $state->modification;
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $body = $this->guardCosts($user, $vehicle, $body);
        $mapped = $this->mapped(JsonInput::modification($body, self::units($user->preferences, $body), $today, false));
        $stored = ModificationForm::values($modification, $mapped['preferences']);
        $stored['declared_on'] = $modification->data->declaredOn?->format('Y-m-d') ?? '';
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::MODIFICATION_FIELDS);
        $data = ModificationForm::parse($input, $mapped['preferences'], $today, $modification);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::MODIFICATION_FIELDS));
        }
        try {
            $this->modifications->update($vehicle, $modification, $data, $zone);
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }

        return [];
    }

    /**
     * @throws ApiProblem 422 when a part's history refuses it
     */
    public function delete(Vehicle $vehicle, ModificationState $state): void
    {
        try {
            $this->modifications->delete($vehicle, $state->modification);
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }
    }

    /**
     * `POST …/revert`.
     *
     * @param array<string, mixed> $body
     * @return bool whether anything changed
     * @throws ApiProblem 409, 422
     */
    public function revert(User $user, Vehicle $vehicle, ModificationState $state, array $body): bool
    {
        ApiWriter::assertActive($vehicle);
        $modification = $state->modification;
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $parsed = JsonInput::modificationRevert($body, $user->preferences, $today);
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of($parsed);
        }
        if ($modification->isReverted()) {
            return false;
        }
        $removable = [];
        $refittable = [];
        foreach ($state->made as ['part' => $part, 'fitting' => $fitting]) {
            if ($fitting->fittedModificationId === $modification->id && $fitting->isOpen()) {
                $removable[$part->id] = $part->id;
            }
            if (
                $fitting->removedModificationId === $modification->id
                && $part->status === \Logbook\Domain\Part\PartStatus::Stored
                && $part->data->origin === \Logbook\Domain\Part\PartOrigin::Original
            ) {
                $refittable[$part->id] = $part->id;
            }
        }
        // The form's fields: each part to take off is ticked, as kept; the originals go back when asked.
        $input = $parsed['input'];
        if ($parsed['remove']) {
            foreach ($removable as $id) {
                $input['remove_' . $id] = '1';
                $input['remove_' . $id . '_outcome'] = 'kept';
            }
        }
        if ($parsed['refit']) {
            foreach ($refittable as $id) {
                $input['refit_' . $id] = '1';
            }
        }
        $form = ModificationForm::parseRevert(
            $input,
            $parsed['preferences'],
            $today,
            $modification,
            array_values($removable),
            array_values($refittable),
        );
        if ($form instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($form, ['reverted_on' => 'reverted_on', 'note' => 'note']));
        }
        try {
            $this->modifications->revert(
                $vehicle,
                $modification,
                $form['on'],
                $form['km'],
                $form['note'],
                $form['remove'],
                $form['refit'],
                $zone,
            );
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }

        return true;
    }

    /**
     * `POST …/undo-revert`.
     *
     * @return bool whether anything changed
     * @throws ApiProblem 409, 422
     */
    public function undoRevert(Vehicle $vehicle, ModificationState $state): bool
    {
        ApiWriter::assertActive($vehicle);
        if (!$state->modification->isReverted()) {
            return false;
        }
        try {
            $this->modifications->undoRevert($vehicle, $state->modification);
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }

        return true;
    }

    /**
     * The parts lists of a body, read through the form's own reader: only a part the lists offer
     * may be named, so an id the vehicle doesn't have, or one in the wrong state, is a 422.
     *
     * @param array{input: array<string, string>, preferences: DisplayPreferences} $mapped
     */
    private function lists(
        Vehicle $vehicle,
        array $mapped,
        \DateTimeImmutable $today,
        ?int $recordId,
    ): RecordParts|ValidationErrors|null {
        $input = $mapped['input'];
        if (!RecordPartsForm::sent($input)) {
            return null;
        }
        $made = $recordId === null ? [] : $this->parts->byRecord($vehicle, PartService::MODIFICATION, $recordId);
        $choices = RecordPartsForm::choices($this->parts->list($vehicle), $made, PartService::MODIFICATION, $recordId ?? 0);
        $errors = new ValidationErrors();
        $offered = [
            'fit_' => array_map(static fn ($part): int => $part->id, $choices['fit']),
            'remove_' => array_map(static fn ($part): int => $part->id, $choices['remove']),
        ];
        foreach ($offered as $prefix => $ids) {
            foreach ($input as $key => $value) {
                if (preg_match('/^' . $prefix . '([0-9]+)$/', $key, $m) === 1 && !in_array((int) $m[1], $ids, true)) {
                    $errors->add($prefix === 'fit_' ? 'parts_fitted' : 'parts_removed', 'api.validation.part_id');
                }
            }
        }
        if (!$errors->isEmpty()) {
            return $errors;
        }
        $validator = new Validator($input, $mapped['preferences']->locale);
        $lists = RecordPartsForm::read($validator, $input, $mapped['preferences'], $today, $choices['fit'], $choices['remove']);

        return $lists ?? self::listErrors($validator->errors());
    }

    /**
     * The form's row fields under the body's names: `new_0_price` is `parts_fitted.0.price` (counting
     * the new parts only), `off_1_outcome` is `parts_removed.1.came_off.outcome` and
     * `remove_12_sold_price` is `parts_removed.part_12.sold_price`.
     */
    private static function listErrors(ValidationErrors $errors): ValidationErrors
    {
        $renamed = new ValidationErrors();
        foreach ($errors->all() as $field => $error) {
            $name = (string) preg_replace(
                ['/^new_([0-9]+)_/', '/^off_([0-9]+)_/', '/^remove_([0-9]+)_/'],
                ['parts_fitted.$1.', 'parts_removed.$1.came_off.', 'parts_removed.part_$1.'],
                $field,
            );
            $renamed->add($name, $error['key'], $error['params']);
        }

        return $renamed;
    }

    /**
     * Without `ViewCosts` a cost, a part's price or a sale price sent is ignored (spec.md §7.39 *Access*, #429).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function guardCosts(User $user, Vehicle $vehicle, array $body): array
    {
        if ($this->costs($user, $vehicle)) {
            return $body;
        }
        unset($body['cost']);
        foreach (['parts_fitted', 'parts_removed'] as $list) {
            if (!isset($body[$list]) || !is_array($body[$list])) {
                continue;
            }
            foreach ($body[$list] as $i => $row) {
                if (is_array($row)) {
                    unset($row['price'], $row['sold_price']);
                    $body[$list][$i] = $row;
                }
            }
        }

        return $body;
    }

    private function costs(User $user, Vehicle $vehicle): bool
    {
        return $this->access->can($user, VehicleAbility::ViewCosts, $vehicle);
    }

    /**
     * @return array<int, list<array{part: \Logbook\Domain\Part\Part, fitting: \Logbook\Domain\Part\PartFitting}>>
     */
    private function madeFor(Vehicle $vehicle): array
    {
        $made = [];
        foreach ($this->modifications->list($vehicle) as $modification) {
            $made[$modification->id] = $this->parts->byRecord($vehicle, PartService::MODIFICATION, $modification->id);
        }

        return $made;
    }

    /**
     * @param array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors $mapped
     * @return array{input: array<string, string>, preferences: DisplayPreferences}
     * @throws ApiProblem 422
     */
    private function mapped(array|ValidationErrors $mapped): array
    {
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }

        return $mapped;
    }

    /**
     * The units the stored modification is laid out in for a `PATCH`: the owner's (or the body's)
     * when the body sends an odometer, else km, so one not sent round-trips exactly.
     *
     * @param array<string, mixed> $body
     */
    private static function units(DisplayPreferences $owner, array $body): DisplayPreferences
    {
        $sends = array_intersect(array_keys($body), ['odometer', 'distance_unit']) !== [];

        return new DisplayPreferences(
            $owner->locale,
            $owner->timezone,
            $sends ? $owner->distanceUnit : DistanceUnit::Kilometre,
            $owner->volumeUnit,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
            $owner->accent,
            $owner->depthUnit,
        );
    }
}
