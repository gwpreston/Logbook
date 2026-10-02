<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Support\Date\LocalTime;

/**
 * Agreements and events for the finance tests, with the paperwork figures
 * the hand-worked examples use.
 */
final class FinanceFixtures
{
    public static function date(string $value): DateTimeImmutable
    {
        return LocalTime::parseDate($value) ?? throw new \LogicException($value);
    }

    /**
     * @param array{
     *     type: AgreementType,
     *     startedOn: string,
     *     firstPaymentOn: string,
     *     numberOfPayments: int,
     *     regularPayment: string,
     *     firstPayment?: string,
     *     finalPayment?: string,
     *     finalPaymentOn?: string,
     *     cashPrice?: string,
     *     customerDeposit?: string,
     *     dealerContribution?: string,
     *     initialRental?: string,
     *     amountOfCredit?: string,
     *     totalAmountPayable?: string,
     *     apr?: string,
     *     documentationFee?: string,
     *     optionToPurchaseFee?: string,
     *     annualMileageAllowance?: int,
     *     excessMileageCharge?: string,
     *     countInCosts?: bool,
     * } $data
     */
    public static function agreement(
        array $data,
        AgreementStatus $status = AgreementStatus::Active,
        ?string $endedOn = null,
        int $id = 1,
    ): FinanceAgreement {
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        return new FinanceAgreement(
            id: $id,
            vehicleId: 1,
            data: new AgreementData(
                type: $data['type'],
                lender: 'Lender',
                agreementNumber: null,
                startedOn: self::date($data['startedOn']),
                firstPaymentOn: self::date($data['firstPaymentOn']),
                numberOfPayments: $data['numberOfPayments'],
                regularPayment: $data['regularPayment'],
                firstPayment: $data['firstPayment'] ?? null,
                finalPayment: $data['finalPayment'] ?? null,
                finalPaymentOn: isset($data['finalPaymentOn']) ? self::date($data['finalPaymentOn']) : null,
                cashPrice: $data['cashPrice'] ?? null,
                customerDeposit: $data['customerDeposit'] ?? '0',
                dealerContribution: $data['dealerContribution'] ?? '0',
                initialRental: $data['initialRental'] ?? null,
                amountOfCredit: $data['amountOfCredit'] ?? null,
                totalAmountPayable: $data['totalAmountPayable'] ?? null,
                apr: $data['apr'] ?? '0',
                documentationFee: $data['documentationFee'] ?? null,
                optionToPurchaseFee: $data['optionToPurchaseFee'] ?? null,
                annualMileageAllowance: $data['annualMileageAllowance'] ?? null,
                excessMileageCharge: $data['excessMileageCharge'] ?? null,
                countInCosts: $data['countInCosts'] ?? true,
            ),
            status: $status,
            endedOn: $endedOn === null ? null : self::date($endedOn),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * HP at 9.9% APR: cash price 15,000, deposit 3,000, so 12,000 of
     * credit, over 48 payments of 301.35 from 15 Feb 2024 (agreement 15 Jan
     * 2024). Total amount payable 3,000 + 48 × 301.35 = 17,464.80.
     */
    public static function hp(AgreementStatus $status = AgreementStatus::Active, ?string $endedOn = null): FinanceAgreement
    {
        return self::agreement([
            'type' => AgreementType::Hp,
            'startedOn' => '2024-01-15',
            'firstPaymentOn' => '2024-02-15',
            'numberOfPayments' => 48,
            'regularPayment' => '301.35',
            'cashPrice' => '15000',
            'customerDeposit' => '3000',
            'apr' => '9.9',
        ], $status, $endedOn);
    }

    /**
     * PCP at 0%: cash price 20,000, deposit 2,000 and a dealer contribution
     * of 1,000, so 17,000 of credit, as 36 payments of 250 from 31 Jan 2025
     * and an optional final payment of 8,000, 8,000 mi a year at 9p.
     */
    public static function pcp(AgreementStatus $status = AgreementStatus::Active, ?string $endedOn = null): FinanceAgreement
    {
        return self::agreement([
            'type' => AgreementType::Pcp,
            'startedOn' => '2024-12-31',
            'firstPaymentOn' => '2025-01-31',
            'numberOfPayments' => 36,
            'regularPayment' => '250',
            'finalPayment' => '8000',
            'cashPrice' => '20000',
            'customerDeposit' => '2000',
            'dealerContribution' => '1000',
            'apr' => '0',
            'annualMileageAllowance' => 8000,
            'excessMileageCharge' => '0.09',
        ], $status, $endedOn);
    }

    /**
     * A personal loan at 6.9% APR: 8,000 over 36 payments of 245.89 from
     * 1 Apr 2025 (agreement 1 Mar 2025). Total 36 × 245.89 = 8,852.04.
     */
    public static function loan(): FinanceAgreement
    {
        return self::agreement([
            'type' => AgreementType::Loan,
            'startedOn' => '2025-03-01',
            'firstPaymentOn' => '2025-04-01',
            'numberOfPayments' => 36,
            'regularPayment' => '245.89',
            'amountOfCredit' => '8000',
            'apr' => '6.9',
        ]);
    }

    /**
     * A lease: an initial rental of 1,500 on 10 Mar 2025, then 23 rentals of
     * 300 from 10 Apr 2025, and a documentation fee of 200.
     */
    public static function lease(): FinanceAgreement
    {
        return self::agreement([
            'type' => AgreementType::Lease,
            'startedOn' => '2025-03-10',
            'firstPaymentOn' => '2025-04-10',
            'numberOfPayments' => 23,
            'regularPayment' => '300',
            'initialRental' => '1500',
            'documentationFee' => '200',
            'annualMileageAllowance' => 10000,
            'excessMileageCharge' => '0.08',
        ]);
    }

    public static function event(
        PaymentEventKind $kind,
        ?string $dueOn,
        ?string $amount = null,
        ?string $paidOn = null,
        int $id = 1,
    ): PaymentEvent {
        return new PaymentEvent(
            id: $id,
            agreementId: 1,
            kind: $kind,
            dueOn: $dueOn === null ? null : self::date($dueOn),
            amount: $amount,
            paidOn: $paidOn === null ? null : self::date($paidOn),
            notes: null,
        );
    }
}
