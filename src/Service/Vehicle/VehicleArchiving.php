<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\EndAgreement;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Incident\TotalLoss;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The *Archive* page's rules (spec.md §7.1, §7.29 *Total loss*, §7.32
 * *Archive page*), shared by the page and the API (Phase 39.2): which
 * disposals a vehicle offers, what each asks for, and the archiving itself.
 *
 * - *Written off*, with the incident and the settlement as the sale date
 *   and price;
 * - *Sold* (while an agreement is active, not for a lease), with the sale
 *   date and price; an active HP or PCP may be *Settled from the sale*,
 *   which ends it as settled early on the sale date;
 * - *Returned to the lender* (PCP: the optional final payment as the sale
 *   price) or *Returned to the lessor* (lease: no sale price), ending an
 *   active agreement as handed back or lease ended on the sale date;
 * - *Just archive* (''), which leaves an active agreement as it is.
 */
final readonly class VehicleArchiving
{
    public const string WRITTEN_OFF = 'written_off';
    private const int MONEY_SCALE = 3;
    private const int MONEY_WHOLE_DIGITS = 11;

    public function __construct(
        private VehicleService $vehicles,
        private TotalLoss $totalLoss,
        private FinanceService $finance,
    ) {
    }

    /**
     * What the page offers this vehicle: the settled write-offs, the
     * agreement it gives choices for, and the choices in order, the first
     * chosen by default, ending with *Just archive* ('').
     *
     * @return array{candidates: list<Incident>, agreement: ?FinanceAgreement, choices: list<string>}
     */
    public function options(User $user, Vehicle $vehicle): array
    {
        $candidates = $this->totalLoss->candidates($vehicle);
        $agreement = $this->finance->archiveAgreement($user, $vehicle);

        return [
            'candidates' => $candidates,
            'agreement' => $agreement,
            'choices' => self::choices($candidates, $agreement),
        ];
    }

    /**
     * Archive the vehicle as the page's form asks: `disposal` (one of the
     * choices; '' or missing just archives), `incident_id`, `sale_date`,
     * `sale_price`, `settle_from_sale` and `settlement` as that disposal
     * needs them. The page's own messages on refusal.
     *
     * @param array<array-key, mixed> $input
     * @return string|ValidationErrors the flash message key for what was done, or why not
     */
    public function archive(User $user, Vehicle $vehicle, array $input): string|ValidationErrors
    {
        $options = $this->options($user, $vehicle);
        $candidates = $options['candidates'];
        $agreement = $options['agreement'];
        $chosen = is_string($input['disposal'] ?? null) ? $input['disposal'] : '';
        if ($chosen === '' || !in_array($chosen, $options['choices'], true)) {
            $this->vehicles->archive($user, $vehicle);

            return 'vehicle.archived';
        }

        $validator = new Validator($input, $user->preferences->locale);
        $incidentId = null;
        if ($chosen === self::WRITTEN_OFF) {
            $incidentId = $validator->choice(
                'incident_id',
                array_map(static fn (Incident $incident): string => (string) $incident->id, $candidates),
                true,
            );
        }
        $saleDate = $validator->date('sale_date', true);
        // Handed back is a sale at the optional final payment (#123); a lease returned has no sale price.
        $salePrice = match ($chosen) {
            Disposal::ReturnedLessor->value => null,
            Disposal::ReturnedLender->value => $agreement?->data->finalPayment ?? '0',
            default => $validator->decimal('sale_price', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS),
        };
        $purchased = $vehicle->data->purchaseDate;
        if ($purchased !== null && $saleDate !== null && $saleDate < $purchased) {
            $validator->addError('sale_date', 'vehicle.sale_before_purchase');
        }

        $end = null;
        if ($agreement !== null && $agreement->status->isActive() && $saleDate !== null) {
            $settleFromSale = ($input['settle_from_sale'] ?? '') === '1';
            $today = $this->finance->ownerToday($user, $vehicle);
            $end = $this->endFor($validator, $agreement, $chosen, $settleFromSale, $saleDate, $today);
        }

        $priceMissing = $salePrice === null && $chosen !== Disposal::ReturnedLessor->value;
        if (!$validator->errors()->isEmpty() || $saleDate === null || $priceMissing) {
            return $validator->errors();
        }

        if ($chosen === self::WRITTEN_OFF) {
            $this->vehicles->archiveWrittenOff($user, $vehicle, (int) $incidentId, $saleDate, (string) $salePrice);

            return 'vehicle.archived_written_off';
        }

        if ($end !== null && $agreement !== null) {
            $this->finance->end($user, $vehicle, $agreement, $end);
        }
        $this->vehicles->archiveAs($user, $vehicle, Disposal::from($chosen), $saleDate, $salePrice);

        return 'vehicle.archived_as.' . $chosen;
    }

    /**
     * @param list<Incident> $candidates
     * @return list<string>
     */
    private static function choices(array $candidates, ?FinanceAgreement $agreement): array
    {
        $choices = $candidates === [] ? [] : [self::WRITTEN_OFF];
        if ($agreement !== null) {
            $active = $agreement->status->isActive();
            $choices = [...$choices, ...match ($agreement->type()) {
                AgreementType::Pcp => $active
                    ? [Disposal::Sold->value, Disposal::ReturnedLender->value]
                    : [Disposal::ReturnedLender->value],
                AgreementType::Lease => [Disposal::ReturnedLessor->value],
                default => $active ? [Disposal::Sold->value] : [],
            }];
        }
        $choices[] = '';

        return $choices;
    }

    /**
     * What ending the active agreement this way means, checked: *Settled
     * from the sale* with its amount, or handed back or lease ended on the
     * sale date. Null when the agreement is left as it is.
     */
    private function endFor(
        Validator $validator,
        FinanceAgreement $agreement,
        string $chosen,
        bool $settleFromSale,
        DateTimeImmutable $saleDate,
        DateTimeImmutable $today,
    ): ?EndAgreement {
        $outcome = match ($chosen) {
            Disposal::Sold->value => $agreement->type()->hasCashPrice() && $settleFromSale
                ? AgreementStatus::Settled
                : null,
            Disposal::ReturnedLender->value => AgreementStatus::HandedBack,
            Disposal::ReturnedLessor->value => AgreementStatus::Ended,
            default => null,
        };
        if ($outcome === null) {
            return null;
        }
        if ($saleDate > $today) {
            $validator->addError('sale_date', 'finance.end.error_future');
        } elseif ($saleDate < $agreement->data->startedOn) {
            $validator->addError('sale_date', 'finance.end.error_before_start');
        }
        $settlement = null;
        if ($outcome === AgreementStatus::Settled) {
            $settlement = $validator->decimal('settlement', true, 2, '0', null, self::MONEY_WHOLE_DIGITS);
        }

        return new EndAgreement($outcome, $saleDate, $settlement);
    }
}
