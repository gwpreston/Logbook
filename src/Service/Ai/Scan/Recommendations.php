<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Service\Issue\IssueForm;
use DateTimeImmutable;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderRules;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * A garage's recommended work and an MOT's advisories, offered as manual
 * reminders after the entry is saved (spec.md §7.27 *Recommended work*).
 * Never added on their own. A date is taken as printed; a distance is kept
 * as a distance (#82): due at the entry's odometer (or the latest reading)
 * plus the distance, with the projected date in the label; neither is due
 * in 30 days, marked so the user can change it.
 */
final readonly class Recommendations
{
    public const int DEFAULT_DAYS = 30;
    private const int TITLE_MAX = 120;

    public function __construct(
        private OdometerService $odometer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What the card offers for a saved entry, oldest line first.
     *
     * @param string|null $atKm the saved entry's odometer, km
     * @return list<array{
     *     text: string,
     *     due_on: ?string,
     *     due_km: ?string,
     *     distance_km: ?string,
     *     projected_on: ?string,
     *     guessed: bool,
     *     added: bool,
     * }>
     */
    public function offers(User $user, Vehicle $vehicle, Extraction $reading, ?string $atKm): array
    {
        $lines = match ($reading->kind) {
            ScanKind::ServiceInvoice => $reading->recommendations,
            ScanKind::Inspection => array_map(
                static fn (string $line): array => ['text' => $line, 'distance' => null, 'distance_unit' => null, 'date' => null],
                $reading->lines('advisories'),
            ),
            default => [],
        };
        if ($lines === []) {
            return [];
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $history = $this->odometer->history($vehicle);
        $baseKm = $atKm ?? $history->latest()?->readingKm;
        $offers = [];
        foreach ($lines as $line) {
            $title = mb_substr($line['text'], 0, self::TITLE_MAX);
            $dueOn = null;
            $dueKm = null;
            $distanceKm = null;
            $projected = null;

            $date = $line['date'] === null ? null : PrintedDate::read($line['date'], $user->preferences->locale);
            if ($date !== null && $date->date >= $today) {
                $dueOn = $date->date;
            }
            $distance = $line['distance'] === null ? null : PrintedNumber::read($line['distance'], $user->preferences->locale);
            if ($dueOn === null && $distance !== null && Decimal::compare($distance, '0') > 0 && $baseKm !== null) {
                $unit = Mapper::distanceUnit((string) $line['distance_unit']) ?? $user->preferences->distanceUnit;
                $distanceKm = $unit->toKmDecimal($distance, 3);
                $dueKm = Decimal::add($baseKm, $distanceKm);
                $projected = ReminderRules::manual(
                    null,
                    $dueKm,
                    $today,
                    0,
                    $history->latest()?->readingKm,
                    $history->averageKmPerDay(),
                )->on;
            }
            $guessed = $dueOn === null && $dueKm === null;
            if ($guessed) {
                $dueOn = $today->modify('+' . self::DEFAULT_DAYS . ' days');
            }

            $offers[] = [
                'text' => $title,
                'due_on' => $dueOn?->format('Y-m-d'),
                'due_km' => $dueKm,
                'distance_km' => $distanceKm,
                'projected_on' => $projected?->format('Y-m-d'),
                'guessed' => $guessed,
                'added' => false,
            ];
        }

        return $offers;
    }

    /**
     * An offer as the manual reminder *Add reminder* creates.
     *
     * @param array{text: string, due_on: ?string, due_km: ?string} $offer
     */
    public static function reminder(Vehicle $vehicle, array $offer, int $leadDays): ManualReminderData
    {
        return new ManualReminderData(
            $vehicle->id,
            $offer['text'],
            $offer['due_on'] === null ? null : LocalTime::parseDate($offer['due_on']),
            $leadDays,
            null,
            $offer['due_km'],
        );
    }

    /**
     * An offer as the issue *Add as issue* (open) or *Watch* creates
     * (spec.md §7.37 *Phase 40.2*, #314): noticed on the entry's date at its
     * odometer; watching takes the line's own date or distance as the
     * look-again point, never the guessed 30 days.
     *
     * @param array{text: string, due_on: ?string, due_km: ?string, guessed: bool} $offer
     */
    public static function issue(array $offer, DateTimeImmutable $noticedOn, ?string $atKm, bool $watch): IssueData
    {
        $lookOn = $watch && !$offer['guessed'] && $offer['due_on'] !== null ? LocalTime::parseDate($offer['due_on']) : null;

        return new IssueData(
            $noticedOn,
            mb_substr($offer['text'], 0, IssueForm::TITLE_MAX),
            $watch ? IssueStatus::Watching : IssueStatus::Open,
            $atKm,
            lookAgainOn: $lookOn !== null && $lookOn >= $noticedOn ? $lookOn : null,
            lookAgainKm: $watch ? $offer['due_km'] : null,
        );
    }

    public static function date(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : LocalTime::parseDate($value);
    }
}
