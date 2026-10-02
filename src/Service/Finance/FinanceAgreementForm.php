<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The agreement form (spec.md §7.32 *Form*), with fields by type: a field
 * that doesn't belong to the type is ignored. Amounts are in the vehicle's
 * currency (2 places as typed, 0 valid), the APR to 3 places, the excess
 * charge to 4; the start odometer in the user's distance unit.
 */
final class FinanceAgreementForm
{
    public const int LENDER_MAX = 100;
    public const int NUMBER_MAX = 50;
    public const int NOTES_MAX = 2000;
    public const int PAYMENTS_MAX = 120;
    public const int MILEAGE_MAX = 200000;
    private const int MONEY_SCALE = 2;
    private const int MONEY_WHOLE_DIGITS = 11;

    /** The fields each type's form shows (besides lender, number, dates, payments and notes). */
    private const array FIELDS = [
        'hp' => ['cash_price', 'customer_deposit', 'dealer_contribution', 'amount_of_credit', 'apr', 'first_payment',
            'final_payment', 'final_payment_on', 'documentation_fee', 'option_to_purchase_fee', 'total_amount_payable'],
        'pcp' => ['cash_price', 'customer_deposit', 'dealer_contribution', 'amount_of_credit', 'apr', 'first_payment',
            'final_payment', 'final_payment_on', 'documentation_fee', 'option_to_purchase_fee', 'total_amount_payable',
            'annual_mileage_allowance', 'mileage_unit', 'excess_mileage_charge', 'start_odometer'],
        'loan' => ['amount_of_credit', 'apr', 'documentation_fee', 'total_amount_payable'],
        'lease' => ['initial_rental', 'documentation_fee', 'total_amount_payable', 'annual_mileage_allowance',
            'mileage_unit', 'excess_mileage_charge', 'start_odometer'],
    ];

    /**
     * Whether a type's form has a field.
     */
    public static function has(AgreementType $type, string $field): bool
    {
        return in_array($field, self::FIELDS[$type->value], true);
    }

    /**
     * @return array<string, list<string>> each type's own fields, for the form's show and hide
     */
    public static function fieldsByType(): array
    {
        return self::FIELDS;
    }

    /**
     * A new agreement's form: the type, today as the agreement date, the
     * first payment a month later, the distance unit the user reads.
     *
     * @return array<string, string>
     */
    public static function defaults(AgreementType $type, DateTimeImmutable $today, DisplayPreferences $preferences): array
    {
        return [
            'type' => $type->value,
            'started_on' => $today->format('Y-m-d'),
            'first_payment_on' => LocalTime::addMonths($today, 1)->format('Y-m-d'),
            'customer_deposit' => '0',
            'dealer_contribution' => '0',
            'apr' => '0',
            'mileage_unit' => $preferences->distanceUnit->value,
            'count_in_costs' => '1',
        ];
    }

    /**
     * An agreement as the edit form shows it, the agreement number in full.
     *
     * @return array<string, string>
     */
    public static function values(FinanceAgreement $agreement, DisplayPreferences $preferences): array
    {
        $data = $agreement->data;
        $money = static fn (?string $amount): string => $amount === null ? '' : Decimal::trim($amount);

        return [
            'type' => $data->type->value,
            'lender' => $data->lender,
            'agreement_number' => $data->agreementNumber ?? '',
            'started_on' => $data->startedOn->format('Y-m-d'),
            'first_payment_on' => $data->firstPaymentOn->format('Y-m-d'),
            'number_of_payments' => (string) $data->numberOfPayments,
            'regular_payment' => $money($data->regularPayment),
            'first_payment' => $money($data->firstPayment),
            'final_payment' => $money($data->finalPayment),
            'final_payment_on' => $data->finalPaymentOn?->format('Y-m-d') ?? '',
            'cash_price' => $money($data->cashPrice),
            'customer_deposit' => $money($data->customerDeposit),
            'dealer_contribution' => $money($data->dealerContribution),
            'initial_rental' => $money($data->initialRental),
            'amount_of_credit' => $money($data->amountOfCredit),
            'total_amount_payable' => $money($data->totalAmountPayable),
            'apr' => Decimal::trim($data->apr),
            'documentation_fee' => $money($data->documentationFee),
            'option_to_purchase_fee' => $money($data->optionToPurchaseFee),
            'annual_mileage_allowance' => $data->annualMileageAllowance === null ? '' : (string) $data->annualMileageAllowance,
            'mileage_unit' => $data->mileageUnit->value,
            'excess_mileage_charge' => $data->excessMileageCharge === null ? '' : Decimal::trim($data->excessMileageCharge),
            'start_odometer' => $data->startOdometerKm === null
                ? ''
                : OdometerReadingForm::distanceForDisplay($data->startOdometerKm, $preferences),
            'count_in_costs' => $data->countInCosts ? '1' : '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): FinanceInput|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);
        $type = $validator->enum('type', AgreementType::class, true);
        $field = static fn (string $name): bool => $type !== null && self::has($type, $name);
        $money = static fn (string $name, bool $required = false): ?string => $field($name) || $name === 'regular_payment'
            ? $validator->decimal($name, $required, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS)
            : null;

        $lender = $validator->string('lender', true, self::LENDER_MAX);
        $number = $validator->string('agreement_number', false, self::NUMBER_MAX);
        $startedOn = $validator->date('started_on', true);
        $firstPaymentOn = $validator->date('first_payment_on', true);
        $payments = $validator->integer('number_of_payments', true, 1, self::PAYMENTS_MAX);
        $regular = $money('regular_payment', true);
        $first = $money('first_payment');
        $final = $money('final_payment');
        $finalOn = $field('final_payment_on') ? $validator->date('final_payment_on') : null;
        $cash = $money('cash_price', $type !== null && $type->hasCashPrice());
        $deposit = $money('customer_deposit');
        $contribution = $money('dealer_contribution');
        $initial = $money('initial_rental');
        $credit = $money('amount_of_credit', $type === AgreementType::Loan);
        $total = $money('total_amount_payable');
        $apr = $field('apr') ? $validator->decimal('apr', false, 3, '0', '99.999', 2) : null;
        $docFee = $money('documentation_fee');
        $optionFee = $money('option_to_purchase_fee');
        $allowance = $field('annual_mileage_allowance')
            ? $validator->integer('annual_mileage_allowance', false, 0, self::MILEAGE_MAX)
            : null;
        $unit = $field('mileage_unit') ? $validator->enum('mileage_unit', DistanceUnit::class) : null;
        $excess = $field('excess_mileage_charge')
            ? $validator->decimal('excess_mileage_charge', false, 4, '0', null, 6)
            : null;
        $odometer = $field('start_odometer')
            ? $validator->decimal(
                'start_odometer',
                false,
                OdometerReadingForm::KM_SCALE,
                '0',
                null,
                OdometerReadingForm::MAX_WHOLE_DIGITS,
            )
            : null;
        $notes = $validator->string('notes', false, self::NOTES_MAX);

        if ($startedOn !== null && $firstPaymentOn !== null && $firstPaymentOn < $startedOn) {
            $validator->addError('first_payment_on', 'finance.error.first_before_start');
        }
        if ($finalOn !== null && $final === null) {
            $validator->addError('final_payment', 'finance.error.final_date_without_amount');
        }
        if (
            $finalOn !== null && $firstPaymentOn !== null && $payments !== null
            && $finalOn <= LocalTime::addMonths($firstPaymentOn, $payments - 1)
        ) {
            $validator->addError('final_payment_on', 'finance.error.final_before_last');
        }

        if (
            !$validator->errors()->isEmpty() || $type === null || $lender === null || $startedOn === null
            || $firstPaymentOn === null || $payments === null || $regular === null
        ) {
            return $validator->errors();
        }

        $data = new AgreementData(
            type: $type,
            lender: $lender,
            agreementNumber: $number,
            startedOn: $startedOn,
            firstPaymentOn: $firstPaymentOn,
            numberOfPayments: $payments,
            regularPayment: $regular,
            firstPayment: $first,
            finalPayment: $final,
            finalPaymentOn: $finalOn,
            cashPrice: $cash,
            customerDeposit: $deposit ?? '0',
            dealerContribution: $contribution ?? '0',
            initialRental: $initial,
            amountOfCredit: $credit,
            totalAmountPayable: $total,
            apr: $apr ?? '0',
            documentationFee: $docFee,
            optionToPurchaseFee: $optionFee,
            annualMileageAllowance: $allowance,
            mileageUnit: $unit ?? $preferences->distanceUnit,
            excessMileageCharge: $excess,
            startOdometerKm: $odometer === null
                ? null
                : $preferences->distanceUnit->toKmDecimal($odometer, OdometerReadingForm::KM_SCALE),
            countInCosts: $validator->checkbox('count_in_costs'),
            notes: $notes,
        );

        return new FinanceInput(
            $data,
            setPurchasePrice: $type->hasCashPrice() && $validator->checkbox('set_purchase_price'),
            clearPurchasePrice: $type === AgreementType::Lease && $validator->checkbox('clear_purchase_price'),
        );
    }
}
