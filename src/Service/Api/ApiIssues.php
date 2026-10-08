<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueForm;
use Logbook\Service\Issue\IssueNotFound;
use Logbook\Service\Issue\IssueService;
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
 * The issue endpoints (spec.md §7.20 *Issues*, §7.37): lists, one issue
 * with its timeline, and the writes the issue page makes, each through the
 * page's own form parser and IssueService. Access to the vehicle (`View`
 * or `Log`) is the route's; editing and deleting add `EntryAccess`
 * (ApiEditor). Logbook records the owner's words and never a cause.
 */
final readonly class ApiIssues
{
    public function __construct(
        private IssueService $issues,
        private ValidationProblem $validation,
        private EntityTag $tags,
        private ClockInterface $clock,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function list(Vehicle $vehicle, ListQuery $query, ?IssueStatus $status): array
    {
        return $this->page($this->issues->list($vehicle, $status === null ? [] : [$status]), $query);
    }

    /**
     * Every given vehicle's issues, in one list (`GET /issues`).
     *
     * @param list<Vehicle> $vehicles
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    public function fleet(array $vehicles, ListQuery $query, ?IssueStatus $status): array
    {
        $ids = array_map(static fn (Vehicle $vehicle): int => $vehicle->id, $vehicles);

        return $this->page($this->issues->listFor($ids, $status === null ? [] : [$status]), $query);
    }

    /**
     * The issue with its timeline and fixes; what its `ETag` is taken of.
     *
     * @throws ApiProblem 404
     */
    public function state(Vehicle $vehicle, int $id): IssueState
    {
        try {
            $issue = $this->issues->get($vehicle, $id);
        } catch (IssueNotFound) {
            throw ApiProblem::notFound('The vehicle has no such entry.');
        }

        return new IssueState(
            $issue,
            $this->issues->updatesOf($issue),
            $this->issues->fixLinks([$issue], currentOnly: true)[$issue->id] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(IssueState $state): array
    {
        return Serializer::issue($state->issue, $state->updates, $state->fixedBy);
    }

    public function tag(IssueState $state): string
    {
        return $this->tags->of($state);
    }

    /**
     * 412 unless an `If-Match` header, when sent, names the issue's current
     * tag (the sub-writes: updates, fix, reopen).
     *
     * @throws ApiProblem
     */
    public function precondition(?string $ifMatch, IssueState $state): void
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
     * Log an issue. A retry with the same date, title and source reference
     * finds the one already logged (spec.md §7.20 *Issues*).
     *
     * @param array<string, mixed> $body
     * @return array{entry: array<string, mixed>, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function log(User $user, Vehicle $vehicle, array $body): array
    {
        $result = $this->logIssue($user, $vehicle, $body);

        return [
            'entry' => $this->serialize($this->state($vehicle, $result['issue']->id)),
            'duplicate' => $result['duplicate'],
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * The same write, giving the issue (the draft tools' path, spec.md §7.26).
     *
     * @param array<string, mixed> $body
     * @return array{issue: Issue, duplicate: bool, warnings: list<array{code: string, detail: string}>}
     * @throws ApiProblem 409 for an archived vehicle, 422 for invalid input
     */
    public function logIssue(
        User $user,
        Vehicle $vehicle,
        array $body,
        IssueSource $source = IssueSource::Manual,
        ?string $sourceRef = null,
    ): array {
        ApiWriter::assertActive($vehicle);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $mapped = $this->mapped(JsonInput::issue($body, $user->preferences, $today));
        $data = IssueForm::parse($mapped['input'], $mapped['preferences'], $today);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::ISSUE_FIELDS));
        }

        foreach ($this->issues->list($vehicle) as $issue) {
            if (
                $issue->data->noticedOn->format('Y-m-d') === $data->noticedOn->format('Y-m-d')
                && $issue->data->title === $data->title
                && $issue->sourceRef === $sourceRef
            ) {
                return ['issue' => $issue, 'duplicate' => true, 'warnings' => []];
            }
        }

        $issue = $this->issues->create($vehicle, $data, $zone, source: $source, sourceRef: $sourceRef);

        return [
            'issue' => $issue,
            'duplicate' => false,
            'warnings' => ApiWriter::odometerWarnings($this->issues->odometerWarning($vehicle, $issue)),
        ];
    }

    /**
     * `PATCH`: the sent fields over the stored issue, through the edit form.
     * Called by ApiEditor after its access, archive and `If-Match` checks.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     * @throws ApiProblem 422
     */
    public function patch(User $user, Vehicle $vehicle, IssueState $state, array $body): array
    {
        $issue = $state->issue;
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $units = self::units($user->preferences, $body);
        $mapped = $this->mapped(JsonInput::issue($body, $units, $today));
        if ($issue->isFixed() && array_key_exists('status', $body) && $body['status'] !== null) {
            $errors = new ValidationErrors();
            $errors->add('status', 'issue.error.fixed');
            throw $this->validation->of($errors);
        }
        $stored = IssueForm::values($issue, $mapped['preferences']);
        $input = JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::ISSUE_FIELDS);
        if (($body['status'] ?? null) === IssueStatus::Open->value && !array_key_exists('look_again_on', $body)) {
            // Leaving watching clears the point (spec.md §7.37), unless the body says otherwise.
            $input['look_again_on'] = '';
            $input['look_again_odometer'] = '';
        }
        if ($issue->isFixed()) {
            // The form's status for a fixed issue is not one it accepts back.
            $input['status'] = IssueStatus::Open->value;
            $input['look_again_on'] = '';
            $input['look_again_odometer'] = '';
        }
        $data = IssueForm::parse($input, $mapped['preferences'], $today, $issue);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::ISSUE_FIELDS));
        }
        $saved = $this->issues->update($vehicle, $issue, $data, $zone);

        return ApiWriter::odometerWarnings($this->issues->odometerWarning($vehicle, $saved));
    }

    public function delete(Vehicle $vehicle, IssueState $state): void
    {
        $this->issues->delete($vehicle, $state->issue);
    }

    /**
     * `POST …/updates`: *Add update*.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, detail: string}>
     * @throws ApiProblem 409, 422
     */
    public function addUpdate(User $user, Vehicle $vehicle, IssueState $state, array $body): array
    {
        ApiWriter::assertActive($vehicle);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $mapped = $this->mapped(JsonInput::issueUpdate($body, $user->preferences, $today));
        $data = IssueForm::parseUpdate($mapped['input'], $mapped['preferences'], $today, $state->issue);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::ISSUE_UPDATE_FIELDS));
        }
        $update = $this->issues->addUpdate($vehicle, $state->issue, $data, $zone);

        return ApiWriter::odometerWarnings($this->issues->updateOdometerWarning($vehicle, $update));
    }

    /**
     * `POST …/fix`: linked to service records of the vehicle dated on or
     * after it was noticed, or fixed without a record. A fixed issue is left
     * as it is.
     *
     * @param array<string, mixed> $body
     * @return bool whether anything changed
     * @throws ApiProblem 409, 422
     */
    public function fix(User $user, Vehicle $vehicle, IssueState $state, array $body): bool
    {
        ApiWriter::assertActive($vehicle);
        $issue = $state->issue;
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $parsed = JsonInput::issueFix($body, $today);
        if ($parsed instanceof ValidationErrors) {
            throw $this->validation->of($parsed);
        }
        $records = $parsed['records'];
        // Service records are linked only while the maintenance module is on, as on the page.
        $allowed = $this->features->isEnabled(Feature::Maintenance)
            ? [
                ...array_map(static fn ($record): int => $record->id, $this->issues->linkableRecords($vehicle, $issue)),
                ...$state->fixedBy,
            ]
            : [];
        if (array_diff($records, $allowed) !== []) {
            $errors = new ValidationErrors();
            $errors->add('records', 'api.validation.issue_records');
            throw $this->validation->of($errors);
        }
        if ($issue->isFixed()) {
            return false;
        }
        if ($records !== []) {
            $this->issues->fixWith($vehicle, $issue, $records);

            return true;
        }
        $data = IssueForm::parseFixedWithout($parsed['input'], $user->preferences, $today, $issue);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of($data);
        }
        $this->issues->fixWithoutRecord($vehicle, $issue, $data->notedOn, $data->note);

        return true;
    }

    /**
     * `POST …/reopen`: *It's back* on a fixed issue, *Reopen* on a watching
     * one. An open issue is left as it is.
     *
     * @return bool whether anything changed
     * @throws ApiProblem 409
     */
    public function reopen(User $user, Vehicle $vehicle, IssueState $state): bool
    {
        ApiWriter::assertActive($vehicle);
        if ($state->issue->status() === IssueStatus::Open) {
            return false;
        }
        $this->issues->reopen($vehicle, $state->issue, $user->preferences->timeZone());

        return true;
    }

    /**
     * @param list<Issue> $issues
     * @return array{items: list<array<string, mixed>>, cursor: string|null}
     */
    private function page(array $issues, ListQuery $query): array
    {
        $page = $query->page($issues, static fn (Issue $issue): array => [$issue->data->noticedOn, $issue->id]);
        $updates = $this->issues->updatesFor($page['items']);
        $fixes = $this->issues->fixLinks($page['items'], currentOnly: true);

        return [
            'items' => array_map(
                static fn (Issue $issue): array
                    => Serializer::issue($issue, $updates[$issue->id] ?? [], $fixes[$issue->id] ?? []),
                $page['items'],
            ),
            'cursor' => $page['cursor'],
        ];
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
     * The units the stored issue is laid out in for a `PATCH` (#283): the
     * owner's (or the body's) when the body sends an odometer, else km, so
     * an odometer not sent round-trips exactly.
     *
     * @param array<string, mixed> $body
     */
    private static function units(DisplayPreferences $owner, array $body): DisplayPreferences
    {
        $sends = array_intersect(array_keys($body), ['odometer', 'look_again_odometer', 'distance_unit']) !== [];

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
