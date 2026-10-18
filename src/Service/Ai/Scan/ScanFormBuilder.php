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
    /** @var array<string, true> evidence of values OCR read with low confidence */
    private array $hardEvidence = [];
    /** @var array<string, true> dropped source fields already explained under a form field */
    private array $explained = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly User $user,
        private readonly Vehicle $vehicle,
        private readonly Extraction $reading,
        /** The user's calendar date (LocalTime::today()). */
        private readonly DateTimeImmutable $today,
    ) {
        foreach ($reading->hardToRead as $name) {
            $words = $reading->evidence($name) ?? $reading->value($name);
            if ($words !== null) {
                $this->hardEvidence[$words] = true;
            }
        }
    }

    public function set(string $field, string $value, ?string $evidence = null): void
    {
        if ($value === '') {
            return;
        }
        $this->values[$field] = $value;
        if ($evidence !== null && $evidence !== '') {
            $this->evidence[$field] = $evidence;
            // OCR read these words with low confidence (spec.md §7.27, Phase 53.2).
            if (isset($this->hardEvidence[$evidence])) {
                $this->check($field, $this->translator->trans('scan.check_hard_to_read'));
            }
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
        } else {
            $this->missing($field, $from);
        }
    }

    /**
     * A printed number as a canonical decimal, or null. With $field, the
     * reasons go under it: not in the file's text, unreadable, or a number
     * that reads two ways (#453).
     */
    public function number(string $from, ?string $field = null): ?string
    {
        $printed = $this->reading->value($from);
        if ($printed === null) {
            if ($field !== null) {
                $this->missing($field, $from);
            }

            return null;
        }
        $number = PrintedNumber::read($printed, $this->user->preferences->locale);
        if ($field !== null) {
            if ($number === null) {
                $this->problem($field, 'scan.problem_field.unreadable', $printed);
            } else {
                $this->twoWays($field, $printed);
            }
        }

        return $number;
    }

    public function amount(string $field, string $from): void
    {
        $printed = $this->reading->value($from);
        if ($printed === null) {
            $this->missing($field, $from);

            return;
        }
        $amount = PrintedNumber::read($printed, $this->user->preferences->locale);
        if ($amount === null || Decimal::compare($amount, '0') < 0) {
            $this->problem($field, 'scan.problem_field.unreadable', $printed);

            return;
        }
        $this->twoWays($field, $printed);
        $this->set($field, Decimal::trim($amount), $this->reading->evidence($from) ?? $printed);
    }

    public function odometer(string $field): void
    {
        $printed = $this->reading->value('odometer');
        if ($printed === null) {
            $this->missing($field, 'odometer');

            return;
        }
        $value = PrintedNumber::read($printed, $this->user->preferences->locale);
        if ($value === null || Decimal::compare($value, '0') < 0) {
            $this->problem($field, 'scan.problem_field.unreadable', $printed);

            return;
        }
        $this->twoWays($field, $printed);
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
        // A dropped value no form field explained is said above the form.
        foreach ($this->reading->dropped as $name => $evidence) {
            if (!isset($this->explained[$name])) {
                $this->warnings[] = $this->translator->trans('scan.problem_field.not_in_text', ['printed' => $evidence]);
            }
        }

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
            $this->missing($field, $from);

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
            $this->check($field, $this->translator->trans('scan.check_date', [
                'date' => $this->formatDate($read->date),
                'other' => $this->formatDate($read->alternative),
            ]));
        }

        return $read;
    }

    private function problem(string $field, string $key, string $printed): void
    {
        $this->problems[$field] = $this->translator->trans($key, ['printed' => $printed]);
    }

    /**
     * A value the evidence check dropped (spec.md §7.27): the reason goes
     * under $field. Only the first field to read a source explains it.
     */
    private function missing(string $field, string $from): void
    {
        $evidence = $this->reading->dropped($from);
        if ($evidence !== null && !isset($this->explained[$from])) {
            $this->explained[$from] = true;
            $this->problem($field, 'scan.problem_field.not_in_text', $evidence);
        }
    }

    /**
     * A number with one separator and three digits after it is read in the
     * user's locale and marked, naming the other reading (#453, K46).
     */
    private function twoWays(string $field, string $printed): void
    {
        $locale = $this->user->preferences->locale;
        $other = PrintedNumber::alternative($printed, $locale);
        $read = PrintedNumber::read($printed, $locale);
        if ($other === null || $read === null) {
            return;
        }
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 6);
        $this->check($field, $this->translator->trans('scan.check_number', [
            'value' => (string) $formatter->format((float) $read),
            'other' => (string) $formatter->format((float) $other),
        ]));
    }

    private function check(string $field, string $text): void
    {
        $this->checks[$field] = isset($this->checks[$field]) && !str_contains($this->checks[$field], $text)
            ? $this->checks[$field] . ' ' . $text
            : $text;
    }
}
