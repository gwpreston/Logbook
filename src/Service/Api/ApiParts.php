<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartForm;
use Logbook\Service\Part\PartNotFound;
use Logbook\Service\Part\PartService;
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
use Psr\Clock\ClockInterface;

/**
 * The part endpoints (spec.md §7.20 *Parts and modifications*, §7.39): lists, one part with its
 * fittings, warranty and distance, and the writes the part pages make, each through the page's
 * own form parser and PartService. A price needs `ViewCosts` (#429): without it a write that
 * sends one is ignored. Editing and deleting add `EntryAccess` (ApiEditor).
 */
final readonly class ApiParts
{
    public function __construct(
        private PartService $parts,
        private ValidationProblem $validation,
        private EntityTag $tags,
        private ClockInterface $clock,
        private VehicleAccess $access,
        private VehicleService $vehicles,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function list(User $user, Vehicle $vehicle, ListQuery $query, ?PartStatus $status): array
    {
        $page = $query->page(
            $this->parts->list($vehicle, $status === null ? [] : [$status]),
            static fn (Part $part): array => [$part->createdAt, $part->id],
        );
        $fittings = $this->parts->fittingsFor($page['items']);

        return [
            'items' => array_map(
                fn (Part $part): array => $this->render($user, $vehicle, $part, $fittings[$part->id] ?? []),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * @throws ApiProblem 404
     */
    public function state(Vehicle $vehicle, int $id): PartState
    {
        try {
            $part = $this->parts->get($vehicle, $id);
        } catch (PartNotFound) {
            throw ApiProblem::notFound('The vehicle has no such entry.');
        }

        return new PartState($part, $this->parts->fittings($part));
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, Vehicle $vehicle, PartState $state): array
    {
        return $this->render($user, $vehicle, $state->part, $state->fittings);
    }

    public function tag(PartState $state): string
    {
        return $this->tags->of($state);
    }

    /**
     * @throws ApiProblem 412
     */
    public function precondition(?string $ifMatch, PartState $state): void
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
     * Add a part. A retry with the same name, part number and purchase date finds the one already
     * added (spec.md §7.20).
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
        $mapped = $this->mapped(JsonInput::part($this->guardCosts($user, $vehicle, $body), $user->preferences));
        $data = PartForm::parse($mapped['input'], $mapped['preferences'], $today);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::PART_FIELDS));
        }
        foreach ($this->parts->list($vehicle) as $stored) {
            $same = $stored->data->name === $data->name
                && $stored->data->partNumber === $data->partNumber
                && $stored->data->boughtOn?->format('Y-m-d') === $data->boughtOn?->format('Y-m-d');
            if ($same) {
                $entry = $this->serialize($user, $vehicle, $this->state($vehicle, $stored->id));

                return ['entry' => $entry, 'duplicate' => true, 'warnings' => []];
            }
        }
        $part = $this->parts->create($vehicle, $data, $zone);

        return [
            'entry' => $this->serialize($user, $vehicle, $this->state($vehicle, $part->id)),
            'duplicate' => false,
            'warnings' => [],
        ];
    }

    /**
     * `PATCH`: the sent fields over the stored part, through the edit form. Called by ApiEditor
     * after its access, archive and `If-Match` checks.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     * @throws ApiProblem 422
     */
    public function patch(User $user, Vehicle $vehicle, PartState $state, array $body): array
    {
        $part = $state->part;
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $body = $this->guardCosts($user, $vehicle, $body);
        $mapped = $this->mapped(JsonInput::part($body, self::units($user->preferences, $body)));
        $stored = PartForm::values($part, $mapped['preferences']);
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::PART_FIELDS);
        $data = PartForm::parse($input, $mapped['preferences'], $today, $part);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::PART_FIELDS));
        }
        $this->parts->update($vehicle, $part, $data);

        return [];
    }

    public function delete(Vehicle $vehicle, PartState $state): void
    {
        $this->parts->delete($vehicle, $state->part);
    }

    /**
     * `POST …/fit` and `POST …/remove`: *Fit* / *Fit again* and *Remove*, without a record.
     *
     * @param array<string, mixed> $body
     * @throws ApiProblem 409, 422
     */
    public function move(User $user, Vehicle $vehicle, PartState $state, array $body, bool $remove): void
    {
        ApiWriter::assertActive($vehicle);
        $part = $state->part;
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $body = $this->guardCosts($user, $vehicle, $body);
        $mapped = $this->mapped(JsonInput::partMove($body, $user->preferences, $today, $remove));
        try {
            if ($remove) {
                $parsed = PartForm::parseRemove($mapped['input'], $mapped['preferences'], $today);
                if ($parsed instanceof ValidationErrors) {
                    throw $this->validation->of($parsed);
                }
                $this->parts->remove($vehicle, $part, $parsed['on'], $parsed['km'], $parsed['removal'], $zone);
            } else {
                $parsed = PartForm::parseFit($mapped['input'], $mapped['preferences'], $today);
                if ($parsed instanceof ValidationErrors) {
                    throw $this->validation->of($this->renamed($parsed, ['fit_on' => 'fitted_on', 'fit_odometer' => 'odometer']));
                }
                $this->parts->fit($vehicle, $part, $parsed['on'], $parsed['km'], $zone);
            }
        } catch (PartChangeRefused $refused) {
            throw $this->validation->of(ApiWriter::refusal($refused));
        }
    }

    /**
     * @param list<\Logbook\Domain\Part\PartFitting> $fittings
     * @return array<string, mixed>
     */
    private function render(User $user, Vehicle $vehicle, Part $part, array $fittings): array
    {
        $zone = $user->preferences->timeZone();

        return Serializer::part(
            $part,
            $fittings,
            $this->parts->warranty($vehicle, $part, $fittings, $zone),
            $this->parts->distance($vehicle, $fittings, $zone),
            $this->vehicles->currencyFor($user, $vehicle),
            $this->access->can($user, VehicleAbility::ViewCosts, $vehicle),
        );
    }

    /**
     * Without `ViewCosts` a price or a sale price sent is ignored (spec.md §7.39 *Access*, #429).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function guardCosts(User $user, Vehicle $vehicle, array $body): array
    {
        if ($this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            return $body;
        }
        unset($body['price'], $body['sold_price']);

        return $body;
    }

    /**
     * @param array<string, string> $names form field → body field
     */
    private function renamed(ValidationErrors $errors, array $names): ValidationErrors
    {
        $renamed = new ValidationErrors();
        foreach ($errors->all() as $field => $error) {
            $renamed->add($names[$field] ?? $field, $error['key'], $error['params']);
        }

        return $renamed;
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
     * The units the stored part is laid out in for a `PATCH`: the owner's (or the body's) when the
     * body sends a warranty distance, else km, so one not sent round-trips exactly.
     *
     * @param array<string, mixed> $body
     */
    private static function units(DisplayPreferences $owner, array $body): DisplayPreferences
    {
        $sends = array_intersect(array_keys($body), ['warranty_distance', 'distance_unit']) !== [];

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
