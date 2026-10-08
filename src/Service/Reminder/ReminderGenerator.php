<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Finance\MileageAllowance;
use Logbook\Service\Finance\Schedule;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Tyre\TyreVerdict;

/**
 * Which reminders a vehicle's schedules, documents, tyres and first MOT call for today
 * (spec.md §7.6). Pure: the states come in already judged against the
 * owner's today and lead times.
 */
final class ReminderGenerator
{
    /** *Agreement ends: decide what to do* falls due this many days before the end. */
    public const int FINANCE_END_DAYS = 90;

    /**
     * One reminder per schedule whose next-due point can be judged. Its due
     * date is the sooner of the date limit and the projected distance limit;
     * the occurrence is the stored next-due point, so a projection that
     * drifts day to day is still the same occurrence.
     *
     * @param list<ScheduleState> $states
     * @return list<GeneratedReminder>
     */
    public static function fromSchedules(int $vehicleId, array $states, ReminderPreferences $preferences): array
    {
        $reminders = [];
        foreach ($states as $state) {
            $status = ReminderRules::statusForSchedule($state->due);
            if ($status === null) {
                continue;
            }

            $schedule = $state->schedule;
            $next = $schedule->nextDue;
            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::Schedule,
                sourceId: $schedule->id,
                occurrence: ($next->on?->format('Y-m-d') ?? '') . '|' . ($next->km ?? ''),
                category: $schedule->data->category->value,
                title: $schedule->data->title,
                dueOn: $state->due->dueOn,
                dueKm: $next->km,
                leadTimeDays: $preferences->scheduleDays,
                status: $status,
            );
        }

        return $reminders;
    }

    /**
     * One reminder per current document with an expiry date. A replaced
     * document (a renewal has taken over) raises none.
     *
     * @param list<DocumentState> $states
     * @param DateTimeImmutable $today the owner's calendar date
     * @return list<GeneratedReminder>
     */
    public static function fromDocuments(
        int $vehicleId,
        array $states,
        DateTimeImmutable $today,
        ReminderPreferences $preferences,
    ): array {
        $reminders = [];
        foreach ($states as $state) {
            $data = $state->document->data;
            if (!$state->status->isCurrent() || $data->expiryOn === null) {
                continue;
            }

            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::Compliance,
                sourceId: $state->document->id,
                occurrence: $data->expiryOn->format('Y-m-d'),
                category: $data->type->value,
                title: $data->title ?? '',
                dueOn: $data->expiryOn,
                dueKm: null,
                leadTimeDays: $preferences->documentDays,
                status: ReminderRules::statusForDate($data->expiryOn, $today, $preferences->documentDays),
            );
        }

        return $reminders;
    }

    /**
     * The one tyre reminder of a vehicle, or null when nothing about its
     * tyres can be judged. Its source id is the vehicle's own id (one per
     * vehicle, not per tyre). The occurrence is the vehicle's latest tyre
     * change, not the projected date: the projection moves with every
     * fill-up and would reopen and re-send the reminder each time; a new
     * check, fit or swap is what opens it again.
     *
     * @param int|null $latestChangeId the vehicle's latest tyre change
     * @param string $title already in the owner's language (TyreReminderTitle)
     */
    public static function fromTyres(
        int $vehicleId,
        TyreVerdict $verdict,
        ?int $latestChangeId,
        string $title,
        ReminderPreferences $preferences,
    ): ?GeneratedReminder {
        $status = ReminderRules::statusForTyres($verdict);
        if ($status === null) {
            return null;
        }

        return new GeneratedReminder(
            vehicleId: $vehicleId,
            source: ReminderSource::Tyre,
            sourceId: $vehicleId,
            occurrence: (string) ($latestChangeId ?? 0),
            category: 'tyres',
            title: $title,
            dueOn: $verdict->dueOn,
            dueKm: $verdict->dueKm,
            leadTimeDays: $preferences->scheduleDays,
            status: $status,
        );
    }

    /**
     * The vehicle's first MOT reminder (Phase 21.2), from its *First MOT
     * due* date while it has no inspection document (FirstInspection). Its
     * source id is the vehicle's own id; the occurrence is the date, so
     * changing the date moves it. It uses the document lead time.
     *
     * @param DateTimeImmutable $dueOn FirstInspection::pending()
     * @param DateTimeImmutable $today the owner's calendar date
     * @param string $title already in the owner's language
     */
    /**
     * An active agreement's reminders (spec.md §7.32 *Reminders*): its final
     * payment on its date with the document lead time (source `finance`),
     * and for a PCP or lease *Agreement ends: decide what to do* 90 days
     * before the end date, lead time 0 (source `finance_end`, #130). None for
     * regular payments, which go by direct debit. Each occurrence is its
     * date, so editing the agreement's dates opens it again.
     *
     * @param string $finalTitle already in the owner's language
     * @param string $endTitle already in the owner's language
     * @return list<GeneratedReminder>
     */
    public static function fromFinance(
        int $vehicleId,
        FinanceAgreement $agreement,
        DateTimeImmutable $today,
        string $finalTitle,
        string $endTitle,
        ReminderPreferences $preferences,
    ): array {
        if (!$agreement->status->isActive()) {
            return [];
        }
        $data = $agreement->data;
        $reminders = [];
        if ($data->finalPayment !== null) {
            $dueOn = Schedule::finalPaymentOn($agreement);
            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::Finance,
                sourceId: $agreement->id,
                occurrence: $dueOn->format('Y-m-d'),
                category: null,
                title: $finalTitle,
                dueOn: $dueOn,
                dueKm: null,
                leadTimeDays: $preferences->documentDays,
                status: ReminderRules::statusForDate($dueOn, $today, $preferences->documentDays),
            );
        }
        if ($data->type->hasMileage()) {
            $endsOn = MileageAllowance::endsOn($agreement);
            $dueOn = $endsOn->modify(sprintf('-%d days', self::FINANCE_END_DAYS));
            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::FinanceEnd,
                sourceId: $agreement->id,
                occurrence: $endsOn->format('Y-m-d'),
                category: null,
                title: $endTitle,
                dueOn: $dueOn,
                dueKm: null,
                leadTimeDays: 0,
                status: ReminderRules::statusForDate($dueOn, $today, 0),
            );
        }

        return $reminders;
    }

    /**
     * A watching issue's look-again reminder (Phase 40.1, spec.md §7.37,
     * #311): due at its date and/or odometer, judged as a manual reminder by
     * distance with the owner's manual lead time. The occurrence is the
     * point, so a new point opens it again. None for an issue that is not
     * watching or has no point.
     *
     * @param string $title already in the owner's language
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function fromIssue(
        int $vehicleId,
        Issue $issue,
        string $title,
        DateTimeImmutable $today,
        ReminderPreferences $preferences,
        ?string $currentKm,
        ?float $kmPerDay,
    ): ?GeneratedReminder {
        $data = $issue->data;
        if ($data->status !== IssueStatus::Watching || !$data->hasLookAgain()) {
            return null;
        }
        $due = ReminderRules::manual(
            $data->lookAgainOn,
            $data->lookAgainKm,
            $today,
            $preferences->manualDays,
            $currentKm,
            $kmPerDay,
            $preferences->scheduleKm,
        );

        return new GeneratedReminder(
            vehicleId: $vehicleId,
            source: ReminderSource::Issue,
            sourceId: $issue->id,
            occurrence: ($data->lookAgainOn?->format('Y-m-d') ?? '') . '|' . ($data->lookAgainKm ?? ''),
            category: null,
            title: $title,
            dueOn: $data->lookAgainOn,
            dueKm: $data->lookAgainKm,
            leadTimeDays: $preferences->manualDays,
            status: $due->status,
        );
    }

    public static function fromFirstInspection(
        int $vehicleId,
        DateTimeImmutable $dueOn,
        DateTimeImmutable $today,
        string $title,
        ReminderPreferences $preferences,
    ): GeneratedReminder {
        return new GeneratedReminder(
            vehicleId: $vehicleId,
            source: ReminderSource::FirstInspection,
            sourceId: $vehicleId,
            occurrence: $dueOn->format('Y-m-d'),
            category: ComplianceType::Inspection->value,
            title: $title,
            dueOn: $dueOn,
            dueKm: null,
            leadTimeDays: $preferences->documentDays,
            status: ReminderRules::statusForDate($dueOn, $today, $preferences->documentDays),
        );
    }
}
