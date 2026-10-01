<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Number\Decimal;
use NumberFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collects one form's values as the Mapper reads them, with the checks
 * every value goes through (spec.md §7.27 *Checking values*).
 */
final class ScanFormBuilder
{
    /** @var array<string, string> */
    private array $values = [];
    /** @var array<string, string> */
    private array $evidence = [];
    /** @var array<string, string> */
    private array $problems = [];
    /** @var array<string, string> */
    private array $checks = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, string> */
    private array $hints = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly User $user,
        private readonly Vehicle $vehicle,
        private readonly Extraction $reading,
        /** The user's calendar date (LocalTime::today()). */
        private readonly DateTimeImmutable $today,
    ) {
    }

    public function set(string $field, string $value, ?string $evidence = null): void
    {
        if ($value === '') {
            return;
        }
        $this->values[$field] = $value;
        if ($evidence !== null && $evidence !== '') {
            $this->evidence[$field] = $evidence;
        }
    }

    public function hint(string $field, string $text): void
    {
        $this->hints[$field] = $text;
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    /**
     * @param list<string> $fields
     */
    public function has(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($this->values[$field] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    public function text(string $field, string $from): void
    {
        $value = $this->reading->value($from);
        if ($value !== null) {
            $this->set($field, mb_substr($value, 0, 150), $this->reading->evidence($from));
        }
    }

    /**
     * A printed number as a canonical decimal, or null (a problem is noted
     * under $from's field only by the callers that set it).
     */
    public function number(string $from): ?string
    {
        $printed = $this->reading->value($from);

        return $printed === null ? null : PrintedNumber::read($printed, $this->user->preferences->locale);
    }

    public function amount(string $field, string $from): void
    {
        $printed = $this->reading->value($from);
        if ($printed === null) {
            return;
        }
        $amount = PrintedNumber::read($printed, $this->user->preferences->locale);
        if ($amount === null || Decimal::compare($amount, '0') < 0) {
            $this->problem($field, 'scan.problem_field.unreadable', $printed);

            return;
        }
        $this->set($field, Decimal::trim($amount), $this->reading->evidence($from) ?? $printed);
    }

    public function odometer(string $field): void
    {
        $printed = $this->reading->value('odometer');
        if ($printed === null) {
            return;
        }
        $value = PrintedNumber::read($printed, $this->user->preferences->locale);
        if ($value === null || Decimal::compare($value, '0') < 0) {
            $this->problem($field, 'scan.problem_field.unreadable', $printed);

            return;
        }
        $unit = Mapper::distanceUnit((string) $this->reading->value('odometer_unit')) ?? $this->user->preferences->distanceUnit;
        $km = $unit->toKmDecimal($value, 3);
        $this->set(
            $field,
            OdometerReadingForm::distanceForDisplay($km, $this->user->preferences),
            $this->reading->evidence('odometer') ?? $printed,
        );
    }

    /**
     * A printed date into a date field. Dates in the future (unless
     * $future, for an expiry) and before the vehicle's first registration
     * are left empty with the reason; one that reads two ways is marked.
     */
    public function date(string $field, string $from, bool $future = false, bool $againstVehicle = true): ?DateTimeImmutable
    {
        $read = $this->read($field, $from, $future, $againstVehicle);
        if ($read === null) {
            return null;
        }
        $this->set($field, $read->date->format('Y-m-d'), $this->reading->evidence($from) ?? $this->reading->value($from));

        return $read->date;
    }

    /**
     * The same checks, for a field built from the date (a fill-up's time).
     */
    public function dateOnly(string $from): ?DateTimeImmutable
    {
        return $this->read('filled_at', $from, false, true)?->date;
    }

    /**
     * @param array<string, list<string>> $sections heading key → lines
     */
    public function notes(string $field, array $sections): void
    {
        $parts = [];
        foreach ($sections as $key => $lines) {
            if ($lines !== []) {
                $items = array_map(static fn (string $l): string => '- ' . $l, $lines);
                $parts[] = $this->translator->trans($key) . "\n" . implode("\n", $items);
            }
        }
        if ($parts !== []) {
            $this->set($field, mb_substr(implode("\n\n", $parts), 0, 2000));
        }
    }

    public function money(string $amount): string
    {
        $formatter = new NumberFormatter($this->user->preferences->locale, NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency((float) $amount, $this->currency());
    }

    public function currency(): string
    {
        return $this->vehicle->data->currency ?? $this->user->preferences->currency;
    }

    public function formatDate(DateTimeImmutable $date): string
    {
        $formatter = new IntlDateFormatter(
            $this->user->preferences->locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE,
            'UTC',
        );

        return (string) $formatter->format($date);
    }

    public function build(ScanKind $kind): ScanForm
    {
        return new ScanForm(
            $kind,
            $kind->target(),
            $this->values,
            $this->evidence,
            $this->problems,
            $this->checks,
            $this->warnings,
            $this->hints,
        );
    }

    private function read(string $field, string $from, bool $future, bool $againstVehicle): ?PrintedDate
    {
        $printed = $this->reading->value($from);
        if ($printed === null) {
            return null;
        }
        $read = PrintedDate::read($printed, $this->user->preferences->locale);
        if ($read === null) {
            $this->problem($field, 'scan.problem_field.unreadable', $printed);

            return null;
        }
        if (!$future && $read->date > $this->today) {
            $this->problem($field, 'scan.problem_field.future', $printed);

            return null;
        }
        $earliest = $this->vehicle->data->firstRegisteredOn ?? $this->vehicle->data->purchaseDate;
        if ($againstVehicle && !$future && $earliest !== null && $read->date < $earliest) {
            $this->problem($field, 'scan.problem_field.before_vehicle', $printed);

            return null;
        }
        if ($read->alternative !== null) {
            $this->checks[$field] = $this->translator->trans('scan.check_date', [
                'date' => $this->formatDate($read->date),
                'other' => $this->formatDate($read->alternative),
            ]);
        }

        return $read;
    }

    private function problem(string $field, string $key, string $printed): void
    {
        $this->problems[$field] = $this->translator->trans($key, ['printed' => $printed]);
    }
}
