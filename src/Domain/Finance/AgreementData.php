<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

use DateTimeImmutable;
use Logbook\Support\Units\DistanceUnit;

/**
 * An agreement's figures as typed from the paperwork (spec.md §6
 * FinanceAgreement). Amounts are canonical decimal strings in the vehicle's
 * currency; dates are calendar dates (midnight UTC).
 */
final readonly class AgreementData
{
    public function __construct(
        public AgreementType $type,
        public string $lender,
        public ?string $agreementNumber,
        public DateTimeImmutable $startedOn,
        public DateTimeImmutable $firstPaymentOn,
        /** Regular monthly payments (rentals after the initial one, for a lease), 1–120. */
        public int $numberOfPayments,
        public string $regularPayment,
        /** When the first payment differs from the regular one. */
        public ?string $firstPayment = null,
        public ?string $finalPayment = null,
        /** Null = one month after the last regular payment. */
        public ?DateTimeImmutable $finalPaymentOn = null,
        public ?string $cashPrice = null,
        public string $customerDeposit = '0',
        public string $dealerContribution = '0',
        public ?string $initialRental = null,
        /** Null = cash price − deposits. */
        public ?string $amountOfCredit = null,
        /** Null = derived from the payments. */
        public ?string $totalAmountPayable = null,
        /** Percent, e.g. "9.9"; 0 is valid. */
        public string $apr = '0',
        public ?string $documentationFee = null,
        public ?string $optionToPurchaseFee = null,
        public ?int $annualMileageAllowance = null,
        public DistanceUnit $mileageUnit = DistanceUnit::Mile,
        /** Per mile or km, in the vehicle's currency. */
        public ?string $excessMileageCharge = null,
        public ?string $startOdometerKm = null,
        public bool $countInCosts = true,
        public ?string $notes = null,
    ) {
    }
}
