<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Issue\IssueUpdateData;
use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Issue\IssueForm;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Domain\User\User;
use Logbook\Support\Display\DisplayFormatter;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The review card's offers and what taking them does (spec.md §7.38
 * *Review card*): passed tests as `inspection` documents, DVSA's first MOT
 * due date, and defects as issues (`fail`, `dangerous`, `major` open; the
 * rest watching, #324, #328, #333), with repeats becoming updates of the
 * issue they repeat. Access and modules are checked by the action.
 */
final readonly class MotReview
{
    public const string PROVIDER = 'DVSA MOT';
    private const int LOOK_AGAIN_DAYS = 30;

    public function __construct(
        private MotTestRepository $tests,
        private ComplianceDocumentRepository $documents,
        private ComplianceService $compliance,
        private IssueService $issues,
        private VehicleService $vehicles,
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Read-only: a test with nothing left to decide is left out, and marked
     * reviewed only when something is done on the card (#337 review).
     *
     * @param bool $issuesOn whether defects are offered (the `issues` module)
     */
    public function card(Vehicle $vehicle, bool $issuesOn): ReviewCard
    {
        $all = $this->tests->listForVehicle($vehicle->id);
        $oldestFirst = array_reverse($all);
        $inspections = $this->inspections($vehicle);
        $tests = [];
        foreach ($oldestFirst as $index => $test) {
            if ($test->reviewedAt !== null) {
                continue;
            }
            $review = new ReviewTest(
                $test,
                $test->passed() && self::match($inspections, $test) === null,
                $issuesOn ? array_map(
                    fn (MotDefect $defect): ReviewDefect => new ReviewDefect($defect, $this->isRepeat($defect, $all)),
                    $test->defects,
                ) : [],
                $issuesOn ? $this->notSeenAgain(array_slice($oldestFirst, 0, $index), $test) : [],
            );
            if ($review->anythingOffered() || $review->notSeenAgain !== []) {
                $tests[] = $review;
            }
        }
        $state = $this->tests->state($vehicle->id);
        $firstDue = $all === [] && $state->firstDueOn !== null && $vehicle->data->firstInspectionDueOn === null
            ? $state->firstDueOn
            : null;

        return new ReviewCard($tests, $firstDue);
    }

    /**
     * Repeats (spec.md §7.38 *Repeats*): a defect on a test whose text was
     * on the vehicle's previous test, as a defect that became an issue, is
     * not offered; that issue gets an update and the defect links to it.
     * Run after a fetch, with the `issues` module on.
     */
    public function applyRepeats(Vehicle $vehicle, DateTimeZone $zone): int
    {
        $all = $this->tests->listForVehicle($vehicle->id);
        $live = [];
        foreach ($this->issues->list($vehicle, [IssueStatus::Open, IssueStatus::Watching]) as $issue) {
            $live[$issue->id] = $issue;
        }
        // The text of every live issue made from an MOT defect (#338).
        $byKey = [];
        foreach ($all as $test) {
            foreach ($test->defects as $defect) {
                if ($defect->issueId !== null && isset($live[$defect->issueId])) {
                    $byKey[$defect->key()] ??= $live[$defect->issueId];
                }
            }
        }
        $count = 0;
        foreach (array_reverse($all) as $test) {
            foreach ($test->defects as $defect) {
                $issue = $defect->settled() ? null : ($byKey[$defect->key()] ?? null);
                if ($issue === null) {
                    continue;
                }
                $this->issues->addUpdate($vehicle, $issue, new IssueUpdateData(
                    $this->day($test, $zone),
                    $this->translator->trans('mot_history.review.advised_again', [
                        'date' => $this->formatter->instantDate($test->completedAt),
                        'odometer' => $test->odometerKm === null ? '' : $this->formatter->distance($test->odometerKm),
                    ]),
                    $test->odometerKm,
                    null,
                    null,
                    null,
                ), $zone);
                $this->tests->linkIssue($defect->id, $issue->id);
                $count++;
            }
        }

        return $count;
    }

    public function addDocument(Vehicle $vehicle, MotTest $test, DateTimeZone $zone): bool
    {
        if (!$test->passed() || $this->logged($vehicle, $test)) {
            return false;
        }
        $this->compliance->create($vehicle, new ComplianceDocumentData(
            ComplianceType::Inspection,
            null,
            self::PROVIDER,
            $test->reference(),
            $this->day($test, $zone),
            $test->expiryOn,
            '0.000',
            null,
            // The test's `mot` reading is the reading.
            null,
        ), $zone);
        $this->settle($vehicle, $test->id);

        return true;
    }

    /**
     * *Add all*: every offered pass, oldest first, so the latest drives the
     * MOT reminder (§7.5, §7.6).
     */
    public function addAllDocuments(Vehicle $vehicle, DateTimeZone $zone): int
    {
        $added = 0;
        foreach (array_reverse($this->tests->listForVehicle($vehicle->id)) as $test) {
            if ($test->reviewedAt === null && $this->addDocument($vehicle, $test, $zone)) {
                $added++;
            }
        }

        return $added;
    }

    public function useFirstDue(User $user, Vehicle $vehicle): bool
    {
        $state = $this->tests->state($vehicle->id);
        if ($state->firstDueOn === null || $vehicle->data->firstInspectionDueOn !== null) {
            return false;
        }
        $this->vehicles->update($user, $vehicle, $vehicle->data->withFirstInspectionDueOn($state->firstDueOn));

        return true;
    }

    public function addIssue(Vehicle $vehicle, MotTest $test, MotDefect $defect, DateTimeZone $zone): bool
    {
        if ($defect->settled()) {
            return false;
        }
        $title = mb_substr($defect->text, 0, IssueForm::TITLE_MAX);
        $watching = !$defect->type->opensIssue();
        $lookAgain = $watching ? $this->lookAgain($vehicle, $zone) : null;
        $data = new IssueData(
            $this->day($test, $zone),
            $title,
            $watching ? IssueStatus::Watching : IssueStatus::Open,
            $test->odometerKm,
            mb_strlen($defect->text) > IssueForm::TITLE_MAX ? $defect->text : null,
            null,
            $defect->dangerous || in_array($defect->type, [MotDefectType::Dangerous, MotDefectType::Major], true),
            $lookAgain,
            null,
        );
        $issue = $this->issues->create($vehicle, $data, $zone, source: IssueSource::MotAdvisory, sourceRef: $test->number);
        $this->tests->linkIssue($defect->id, $issue->id);
        $this->settle($vehicle, $test->id);

        return true;
    }

    /**
     * @return int issues added
     */
    public function addAllIssues(Vehicle $vehicle, ?MotTest $only, DateTimeZone $zone): int
    {
        $added = 0;
        foreach (array_reverse($this->tests->listForVehicle($vehicle->id)) as $test) {
            if ($test->reviewedAt !== null || ($only !== null && $only->id !== $test->id)) {
                continue;
            }
            foreach ($test->defects as $defect) {
                $added += $this->addIssue($vehicle, $test, $defect, $zone) ? 1 : 0;
            }
        }

        return $added;
    }

    public function notNow(Vehicle $vehicle, MotTest $test, MotDefect $defect): void
    {
        if (!$defect->settled()) {
            $this->tests->dismiss($defect->id, $this->clock->now());
        }
        $this->settle($vehicle, $test->id);
    }

    /**
     * *Done* for a test: what is still offered is put off, and the card no
     * longer shows it.
     */
    public function done(MotTest $test): void
    {
        $now = $this->clock->now();
        foreach ($test->defects as $defect) {
            if (!$defect->settled()) {
                $this->tests->dismiss($defect->id, $now);
            }
        }
        $this->tests->markReviewed($test->id, $now);
    }

    /**
     * An `inspection` document with this test's number, or starting on its
     * day (spec.md §7.38 "Already logged").
     */
    public function logged(Vehicle $vehicle, MotTest $test): bool
    {
        return $this->documentFor($vehicle, $test) !== null;
    }

    public function documentFor(Vehicle $vehicle, MotTest $test): ?int
    {
        return self::match($this->inspections($vehicle), $test);
    }

    /**
     * The document each test became, from one read of the vehicle's documents.
     *
     * @param list<MotTest> $tests
     * @return array<int, int|null> by test id
     */
    public function documentsFor(Vehicle $vehicle, array $tests): array
    {
        $inspections = $this->inspections($vehicle);
        $found = [];
        foreach ($tests as $test) {
            $found[$test->id] = self::match($inspections, $test);
        }

        return $found;
    }

    /**
     * The overview's notice (spec.md §7.38 *Refresh*): the newest test,
     * while it still has something on the review card.
     */
    public function newResult(Vehicle $vehicle, bool $issuesOn): ?MotTest
    {
        $all = $this->tests->listForVehicle($vehicle->id);
        $newest = $all[0] ?? null;
        if ($newest === null || $newest->reviewedAt !== null) {
            return null;
        }
        $review = new ReviewTest(
            $newest,
            $newest->passed() && self::match($this->inspections($vehicle), $newest) === null,
            $issuesOn ? array_map(
                fn (MotDefect $defect): ReviewDefect => new ReviewDefect($defect, $this->isRepeat($defect, $all)),
                $newest->defects,
            ) : [],
        );

        return $review->anythingOffered() ? $newest : null;
    }

    /**
     * @return list<ComplianceDocument>
     */
    private function inspections(Vehicle $vehicle): array
    {
        return array_values(array_filter(
            $this->documents->listForVehicle($vehicle->id),
            static fn (ComplianceDocument $document): bool => $document->data->type === ComplianceType::Inspection,
        ));
    }

    /**
     * The `inspection` document a test became: its number as reference, or
     * its date as start (spec.md §7.38 "Already logged"). Other types never match.
     *
     * @param list<ComplianceDocument> $inspections
     */
    public static function match(array $inspections, MotTest $test): ?int
    {
        $day = $test->completedAt->format('Y-m-d');
        $reference = $test->reference();
        foreach ($inspections as $document) {
            if ($document->vehicleId !== $test->vehicleId || $document->data->type !== ComplianceType::Inspection) {
                continue;
            }
            if (
                ($reference !== null && $document->data->reference === $reference)
                || $document->data->startOn?->format('Y-m-d') === $day
            ) {
                return $document->id;
            }
        }

        return null;
    }

    /**
     * Marks the test reviewed once nothing on it is left to decide.
     */
    private function settle(Vehicle $vehicle, int $testId): void
    {
        $test = $this->tests->find($vehicle->id, $testId);
        if ($test === null || $test->reviewedAt !== null) {
            return;
        }
        if ($test->passed() && !$this->logged($vehicle, $test)) {
            return;
        }
        foreach ($test->defects as $defect) {
            if (!$defect->settled()) {
                return;
            }
        }
        $this->tests->markReviewed($test->id, $this->clock->now());
    }

    /**
     * *Look again* (#337): 30 days before the latest stored test's expiry,
     * so before the next MOT whichever test the defect is from; none when
     * that is already past in the owner's today.
     */
    private function lookAgain(Vehicle $vehicle, DateTimeZone $zone): ?DateTimeImmutable
    {
        foreach ($this->tests->listForVehicle($vehicle->id) as $test) {
            if ($test->expiryOn === null) {
                continue;
            }
            $on = $test->expiryOn->modify(sprintf('-%d days', self::LOOK_AGAIN_DAYS));
            $today = $this->clock->now()->setTimezone($zone)->format('Y-m-d');

            return $on->format('Y-m-d') > $today ? $on : null;
        }

        return null;
    }

    /**
     * Advised again (#338): its issue is one an earlier defect became.
     *
     * @param list<MotTest> $all
     */
    private function isRepeat(MotDefect $defect, array $all): bool
    {
        if ($defect->issueId === null) {
            return false;
        }
        foreach ($all as $test) {
            foreach ($test->defects as $other) {
                if ($other->id !== $defect->id && $other->issueId === $defect->issueId && $test->id !== $defect->motTestId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Issues from earlier tests' defects not on this one, judged only at a
     * pass (a retest after a fail lists none, #338), and only against the
     * pass that comes next.
     *
     * @param list<MotTest> $earlier oldest first
     * @return list<int>
     */
    private function notSeenAgain(array $earlier, MotTest $test): array
    {
        if (!$test->passed()) {
            return [];
        }
        $keys = array_map(static fn (MotDefect $defect): string => $defect->key(), $test->defects);
        $ids = [];
        // Back to the previous pass: the tests this pass is the next pass after.
        foreach (array_reverse($earlier) as $before) {
            foreach ($before->defects as $defect) {
                if ($defect->issueId !== null && !in_array($defect->key(), $keys, true)) {
                    $ids[] = $defect->issueId;
                }
            }
            if ($before->passed()) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The test's day in the owner's time zone, as a calendar date.
     */
    private function day(MotTest $test, DateTimeZone $zone): DateTimeImmutable
    {
        $local = $test->completedAt->setTimezone($zone)->format('Y-m-d');

        return DateTimeImmutable::createFromFormat('!Y-m-d', $local, new DateTimeZone('UTC')) ?: $test->completedAt;
    }
}
