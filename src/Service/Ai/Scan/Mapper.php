<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use DateTimeImmutable;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Draft\Resolver;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reading onto the form it fills (spec.md §7.27 *Mapping to Logbook*,
 * *Checking values*). Every value is read as printed, in the user's locale
 * and units: a date in the user's day/month order (marked to check when it
 * reads both ways), amounts and readings with their separators, distances
 * and volumes converted from the document's unit to the user's. A value
 * that cannot be read, a date in the future or before the vehicle's
 * first registration, is left empty with the reason. The form's own
 * parser still has the last word on Save.
 */
final readonly class Mapper
{
    private const int TITLE_MAX = 150;
    private const int TEXT_MAX = 2000;

    public function __construct(
        private Resolver $resolver,
        private ScheduleService $schedules,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function map(User $user, Vehicle $vehicle, Extraction $reading, ?ScanKind $as = null): ScanForm
    {
        $kind = $as ?? $reading->kind;
        $reading = $reading->as($kind);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $form = new ScanFormBuilder($this->translator, $user, $vehicle, $reading, $today);

        match ($kind->target()) {
            ScanTarget::Maintenance => $this->maintenance($form, $user, $vehicle, $reading),
            ScanTarget::Fuel => $this->fuel($form, $user, $vehicle, $reading),
            ScanTarget::Document => $this->document($form, $reading),
            ScanTarget::Vehicle => $this->vehicle($form, $reading),
        };
        $this->currency($form, $vehicle, $reading);

        return $form->build($kind);
    }

    private function maintenance(ScanFormBuilder $form, User $user, Vehicle $vehicle, Extraction $reading): void
    {
        $form->date('performed_on', 'date');
        $form->odometer('odometer');
        $form->text('vendor', 'vendor');
        $work = $reading->lines('work');
        if ($work !== []) {
            $form->set('title', mb_substr($work[0], 0, self::TITLE_MAX), $work[0]);
        }
        $form->amount('cost', 'total');

        $description = [];
        if ($work !== []) {
            $description[] = implode("\n", $work);
        }
        if ($reading->lines('parts') !== []) {
            $description[] = implode("\n", $reading->lines('parts'));
        }
        $totals = [];
        foreach (['labour_total' => 'scan.description.labour', 'parts_total' => 'scan.description.parts'] as $field => $key) {
            $amount = $form->number($field);
            if ($amount !== null) {
                $totals[] = $this->translator->trans($key, ['amount' => $form->money($amount)]);
            }
        }
        if ($totals !== []) {
            $description[] = implode(' · ', $totals);
        }
        $vat = $form->number('vat_amount');
        if ($vat !== null) {
            $rate = $reading->value('vat_rate');
            $description[] = $rate === null
                ? $this->translator->trans('scan.description.vat', ['amount' => $form->money($vat)])
                : $this->translator->trans('scan.description.vat_rate', [
                    'amount' => $form->money($vat),
                    'rate' => trim($rate),
                ]);
        }
        if ($description !== []) {
            $form->set('description', mb_substr(implode("\n\n", $description), 0, self::TEXT_MAX));
        }

        $category = $this->category($user, [...$work, ...$reading->lines('parts')]);
        if ($category !== null) {
            $form->set('category', $category, $work[0] ?? null);
            foreach ($this->schedules->list($vehicle) as $schedule) {
                if ($schedule->data->category->value === $category) {
                    $form->hint('schedule', $this->translator->trans('scan.hint.schedule', ['title' => $schedule->data->title]));
                    break;
                }
            }
        }
    }

    private function fuel(ScanFormBuilder $form, User $user, Vehicle $vehicle, Extraction $reading): void
    {
        $on = $form->dateOnly('date');
        if ($on !== null) {
            $time = PrintedDate::time((string) $reading->value('time')) ?? PrintedDate::time((string) $reading->value('date'));
            [$hour, $minute] = $time ?? [12, 0];
            $form->set(
                'filled_at',
                $on->setTime($hour, $minute)->format(OdometerReadingForm::LOCAL_FORMAT),
                $reading->evidence('time') ?? $reading->evidence('date'),
            );
        }

        $choice = null;
        $grade = $reading->value('grade');
        if ($grade !== null) {
            // A phrase naming a grade ("B7") beats one naming only the fuel ("Diesel").
            foreach (self::phrases([$grade]) as $phrase) {
                $found = $this->resolver->fuel($user, $vehicle, $phrase);
                if (!is_array($found) || $found['fuel'] === null) {
                    continue;
                }
                if ($found['grade'] !== null) {
                    $choice = new FuelChoice($found['fuel'], $found['grade']);
                    break;
                }
                $choice ??= new FuelChoice($found['fuel']);
            }
            if ($choice !== null) {
                $form->set('fuel', $choice->value(), $reading->evidence('grade') ?? $grade);
            }
        }

        $electric = $choice?->fuel->isElectric() ?? $vehicle->data->fuelType->fittingFamilies()[0]->isElectric();
        $printedUnit = self::volumeUnit((string) $reading->value('volume_unit'), $user->preferences->volumeUnit);
        $unit = $electric ? VolumeUnit::Litre : $user->preferences->volumeUnit;
        $volume = $form->number('volume');
        if ($volume !== null && Decimal::compare($volume, '0') > 0) {
            $litres = $electric || $printedUnit === null ? $volume : $printedUnit->toLitresDecimal($volume, 6);
            $shown = $electric ? $volume : $unit->fromLitresDecimal($litres, 3);
            $form->set('volume', Decimal::trim($shown), $reading->evidence('volume'));
        }
        $price = $form->number('price_per_unit');
        if ($price !== null) {
            if (preg_match('/\d\s*(p|c|ct|cent|cents)\b/i', (string) $reading->value('price_per_unit')) === 1) {
                $price = Decimal::divide($price, '100', 6);
            }
            $perLitre = $electric || $printedUnit === null ? $price : $printedUnit->pricePerLitre($price, 6);
            $form->set(
                'price',
                Decimal::trim($electric ? $price : $unit->pricePerUnit($perLitre, 4)),
                $reading->evidence('price_per_unit'),
            );
        }
        $form->amount('total', 'total');
        $form->text('station', 'vendor');
    }

    private function document(ScanFormBuilder $form, Extraction $reading): void
    {
        $kind = $reading->kind;
        $failed = $kind === ScanKind::Inspection && self::failed($reading);

        if ($kind === ScanKind::Inspection && !$failed) {
            $form->set('type', ComplianceType::Inspection->value);
            $form->date('start_on', 'date');
            $form->date('expiry_on', 'expiry', future: true);
            $form->odometer('odometer');
            $form->text('provider', 'vendor');
            $form->text('reference', 'reference');
            $form->notes('notes', [
                'scan.notes.advisories' => $reading->lines('advisories'),
            ]);

            return;
        }
        if ($failed) {
            // A failed test is a note, never the vehicle's MOT (#81).
            $form->set('type', ComplianceType::Other->value);
            $on = $form->date('start_on', 'date');
            $form->set('title', $this->translator->trans('scan.failed_title', [
                'date' => $on === null ? '' : $form->formatDate($on),
            ]));
            $form->text('provider', 'vendor');
            $form->text('reference', 'reference');
            $form->notes('notes', [
                'scan.notes.failures' => $reading->lines('failures'),
                'scan.notes.advisories' => $reading->lines('advisories'),
            ]);
            $form->warn($this->translator->trans('scan.warning.failed_test'));

            return;
        }
        if ($kind === ScanKind::Insurance) {
            $form->set('type', ComplianceType::Insurance->value);
            $form->text('provider', 'vendor');
            $form->text('reference', 'reference');
            if ($form->date('start_on', 'start', future: true) === null) {
                $form->date('start_on', 'date');
            }
            $form->date('expiry_on', 'expiry', future: true);
            $form->amount('cost', 'total');

            return;
        }

        $form->set('type', ComplianceType::Other->value);
        $title = $reading->value('title');
        if ($title !== null) {
            $form->set('title', mb_substr($title, 0, self::TITLE_MAX), $reading->evidence('title'));
        }
        $form->date('start_on', 'date');
        $form->text('provider', 'vendor');
        $form->date('expiry_on', 'expiry', future: true);
    }

    private function vehicle(ScanFormBuilder $form, Extraction $reading): void
    {
        $registration = $reading->value('registration');
        if ($registration !== null) {
            $form->set('registration', mb_strtoupper(trim($registration)), $reading->evidence('registration'));
        }
        $vin = $reading->value('vin');
        if ($vin !== null) {
            $form->set('vin', (string) preg_replace('/\s+/', '', mb_strtoupper($vin)), $reading->evidence('vin'));
        }
        $form->date('first_registered_on', 'first_registration', againstVehicle: false);
    }

    private function currency(ScanFormBuilder $form, Vehicle $vehicle, Extraction $reading): void
    {
        $printed = $reading->value('currency') ?? $reading->value('total');
        $code = $printed === null ? null : self::currencyCode($printed);
        $own = $form->currency();
        if ($code !== null && $code !== $own && $form->has(['cost', 'total'])) {
            $form->warn($this->translator->trans('scan.warning.currency', ['found' => $code, 'vehicle' => $own]));
        }
    }

    /**
     * The category the work names (the Phase 26.3 resolver), longest phrase
     * first, in the order of the lines; null when none does.
     *
     * @param list<string> $lines
     */
    private function category(User $user, array $lines): ?string
    {
        foreach ($lines as $line) {
            foreach (self::phrases([$line]) as $phrase) {
                $code = $this->resolver->category($user, DraftKind::Maintenance, $phrase);
                if ($code !== null && $code !== 'other') {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Each line's phrases of up to three words, longest first.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function phrases(array $lines): array
    {
        $phrases = [];
        foreach ($lines as $line) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $phrases[] = implode(' ', $words);
            for ($size = 3; $size >= 1; $size--) {
                for ($i = 0; $i + $size <= count($words); $i++) {
                    $phrases[] = implode(' ', array_slice($words, $i, $size));
                }
            }
        }

        return array_values(array_unique(array_filter($phrases, static fn (string $p): bool => $p !== '')));
    }

    private static function failed(Extraction $reading): bool
    {
        $result = mb_strtolower((string) $reading->value('result'));

        return str_contains($result, 'fail')
            || ($result === '' && $reading->lines('failures') !== [] && $reading->value('expiry') === null);
    }

    public static function distanceUnit(string $printed): ?DistanceUnit
    {
        $unit = mb_strtolower(trim($printed, " .\t"));

        return match (true) {
            $unit === '' => null,
            in_array($unit, ['mi', 'mile', 'miles', 'mls', 'm'], true) => DistanceUnit::Mile,
            in_array($unit, ['km', 'kms', 'kilometre', 'kilometres', 'kilometer', 'kilometers'], true) => DistanceUnit::Kilometre,
            default => null,
        };
    }

    private static function volumeUnit(string $printed, VolumeUnit $preferred): ?VolumeUnit
    {
        $unit = mb_strtolower(trim($printed, " .\t"));

        return match (true) {
            $unit === '' => null,
            in_array($unit, ['l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters'], true) => VolumeUnit::Litre,
            str_starts_with($unit, 'gal') => $preferred === VolumeUnit::Litre ? VolumeUnit::UkGallon : $preferred,
            default => null,
        };
    }

    private static function currencyCode(string $printed): ?string
    {
        $codes = ['GBP', 'EUR', 'USD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'CAD', 'AUD', 'NZD', 'INR', 'JPY'];
        if (preg_match('/\b([A-Z]{3})\b/', mb_strtoupper($printed), $m) === 1 && in_array($m[1], $codes, true)) {
            return $m[1];
        }

        return match (true) {
            str_contains($printed, '£') => 'GBP',
            str_contains($printed, '€') => 'EUR',
            str_contains($printed, '₹') => 'INR',
            default => null,
        };
    }
}
