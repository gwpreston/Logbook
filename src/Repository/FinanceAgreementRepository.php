<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Units\DistanceUnit;
use UnexpectedValueException;

/**
 * Finance agreements and what hangs off them: payment events and settlement
 * quotes (spec.md §6 FinanceAgreement, FinancePaymentEvent,
 * SettlementQuote). Vehicle queries are scoped to a vehicle the caller has
 * already resolved; event and quote queries to an agreement resolved through
 * its vehicle.
 */
final readonly class FinanceAgreementRepository
{
    private const string TABLE = 'finance_agreements';
    private const string EVENTS = 'finance_payment_events';
    private const string QUOTES = 'settlement_quotes';
    public const int MONEY_SCALE = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<FinanceAgreement> the active one first, then newest first
     */
    public function listForVehicle(int $vehicleId): array
    {
        return $this->listForVehicles([$vehicleId]);
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<FinanceAgreement> active ones first, then newest first
     */
    public function listForVehicles(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $rows = $this->select()
            ->where('vehicle_id IN (:vehicles)')
            ->setParameter('vehicles', $vehicleIds, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();
        $agreements = array_values(array_map($this->hydrate(...), $rows));
        usort($agreements, static fn (FinanceAgreement $a, FinanceAgreement $b): int =>
            ($b->status->isActive() <=> $a->status->isActive())
            ?: ($b->data->startedOn <=> $a->data->startedOn)
            ?: $b->id <=> $a->id);

        return $agreements;
    }

    public function find(int $vehicleId, int $id): ?FinanceAgreement
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'id = :id')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function activeFor(int $vehicleId): ?FinanceAgreement
    {
        $row = $this->select()
            ->where('vehicle_id = :vehicle', 'status = :status')
            ->setParameter('vehicle', $vehicleId, ParameterType::INTEGER)
            ->setParameter('status', AgreementStatus::Active->value)
            ->orderBy('id')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $vehicleId, AgreementData $data, DateTimeImmutable $now, ?int $createdBy): int
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'vehicle_id' => $vehicleId,
            'created_by' => $createdBy,
            'status' => AgreementStatus::Active->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::dataColumns($data), self::types() + ['vehicle_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $vehicleId, int $id, AgreementData $data, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())] + self::dataColumns($data),
            ['vehicle_id' => $vehicleId, 'id' => $id],
            self::types() + ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Set where the agreement stands: `active` with no end date, or ended on
     * a date (spec.md §7.32 *Ending*).
     */
    public function setStatus(
        int $vehicleId,
        int $id,
        AgreementStatus $status,
        ?DateTimeImmutable $endedOn,
        DateTimeImmutable $now,
    ): void {
        $this->connection->update(
            self::TABLE,
            [
                'status' => $status->value,
                'ended_on' => $endedOn?->format('Y-m-d'),
                'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
            ],
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Deleting removes its events and quotes (`ON DELETE CASCADE`).
     */
    public function delete(int $vehicleId, int $id): void
    {
        $this->connection->delete(
            self::TABLE,
            ['vehicle_id' => $vehicleId, 'id' => $id],
            ['vehicle_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<PaymentEvent> by the date they concern, then as recorded
     */
    public function eventsFor(int $agreementId): array
    {
        return $this->eventsForAgreements([$agreementId])[$agreementId] ?? [];
    }

    /**
     * @param list<int> $agreementIds
     * @return array<int, list<PaymentEvent>> by agreement id
     */
    public function eventsForAgreements(array $agreementIds): array
    {
        if ($agreementIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'agreement_id', 'due_on', 'kind', 'amount', 'paid_on', 'notes')
            ->from(self::EVENTS)
            ->where('agreement_id IN (:agreements)')
            ->setParameter('agreements', $agreementIds, ArrayParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();
        $by = [];
        foreach ($rows as $row) {
            $event = new PaymentEvent(
                id: Row::int($row, 'id'),
                agreementId: Row::int($row, 'agreement_id'),
                kind: PaymentEventKind::tryFrom(Row::string($row, 'kind'))
                    ?? throw new UnexpectedValueException('Unknown payment event kind.'),
                dueOn: Row::nullableDate($row, 'due_on'),
                amount: Row::nullableDecimal($row, 'amount', self::MONEY_SCALE),
                paidOn: Row::nullableDate($row, 'paid_on'),
                notes: Row::nullableString($row, 'notes'),
            );
            $by[$event->agreementId][] = $event;
        }
        foreach ($by as $id => $events) {
            usort($events, static fn (PaymentEvent $a, PaymentEvent $b): int =>
                (($a->dueOn ?? $a->paidOn) <=> ($b->dueOn ?? $b->paidOn)) ?: $a->id <=> $b->id);
            $by[$id] = $events;
        }

        return $by;
    }

    public function insertEvent(
        int $agreementId,
        PaymentEventKind $kind,
        ?DateTimeImmutable $dueOn,
        ?string $amount,
        ?DateTimeImmutable $paidOn,
        ?string $notes,
        DateTimeImmutable $now,
    ): int {
        $this->connection->insert(self::EVENTS, [
            'agreement_id' => $agreementId,
            'kind' => $kind->value,
            'due_on' => $dueOn?->format('Y-m-d'),
            'amount' => $amount,
            'paid_on' => $paidOn?->format('Y-m-d'),
            'notes' => $notes,
            'created_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['agreement_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteEvent(int $agreementId, int $id): void
    {
        $this->connection->delete(
            self::EVENTS,
            ['agreement_id' => $agreementId, 'id' => $id],
            ['agreement_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<SettlementQuote> newest first
     */
    public function quotesFor(int $agreementId): array
    {
        return $this->quotesForAgreements([$agreementId])[$agreementId] ?? [];
    }

    /**
     * @param list<int> $agreementIds
     * @return array<int, list<SettlementQuote>> by agreement id, newest first
     */
    public function quotesForAgreements(array $agreementIds): array
    {
        if ($agreementIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'agreement_id', 'quoted_on', 'amount', 'valid_until', 'notes')
            ->from(self::QUOTES)
            ->where('agreement_id IN (:agreements)')
            ->setParameter('agreements', $agreementIds, ArrayParameterType::INTEGER)
            ->orderBy('quoted_on', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->fetchAllAssociative();
        $by = [];
        foreach ($rows as $row) {
            $quote = new SettlementQuote(
                id: Row::int($row, 'id'),
                agreementId: Row::int($row, 'agreement_id'),
                quotedOn: Row::nullableDate($row, 'quoted_on')
                    ?? throw new UnexpectedValueException('Column "quoted_on" is null.'),
                amount: Row::decimal($row, 'amount', self::MONEY_SCALE),
                validUntil: Row::nullableDate($row, 'valid_until')
                    ?? throw new UnexpectedValueException('Column "valid_until" is null.'),
                notes: Row::nullableString($row, 'notes'),
            );
            $by[$quote->agreementId][] = $quote;
        }

        return $by;
    }

    public function insertQuote(
        int $agreementId,
        DateTimeImmutable $quotedOn,
        string $amount,
        DateTimeImmutable $validUntil,
        ?string $notes,
        DateTimeImmutable $now,
    ): int {
        $this->connection->insert(self::QUOTES, [
            'agreement_id' => $agreementId,
            'quoted_on' => $quotedOn->format('Y-m-d'),
            'amount' => $amount,
            'valid_until' => $validUntil->format('Y-m-d'),
            'notes' => $notes,
            'created_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['agreement_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    public function deleteQuote(int $agreementId, int $id): void
    {
        $this->connection->delete(
            self::QUOTES,
            ['agreement_id' => $agreementId, 'id' => $id],
            ['agreement_id' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'vehicle_id',
                'created_by',
                'type',
                'lender',
                'agreement_number',
                'status',
                'started_on',
                'first_payment_on',
                'number_of_payments',
                'regular_payment',
                'first_payment',
                'final_payment',
                'final_payment_on',
                'cash_price',
                'customer_deposit',
                'dealer_contribution',
                'initial_rental',
                'amount_of_credit',
                'total_amount_payable',
                'apr',
                'documentation_fee',
                'option_to_purchase_fee',
                'annual_mileage_allowance',
                'mileage_unit',
                'excess_mileage_charge',
                'start_odometer_km',
                'count_in_costs',
                'ended_on',
                'notes',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private static function dataColumns(AgreementData $data): array
    {
        return [
            'type' => $data->type->value,
            'lender' => $data->lender,
            'agreement_number' => $data->agreementNumber,
            'started_on' => $data->startedOn->format('Y-m-d'),
            'first_payment_on' => $data->firstPaymentOn->format('Y-m-d'),
            'number_of_payments' => $data->numberOfPayments,
            'regular_payment' => $data->regularPayment,
            'first_payment' => $data->firstPayment,
            'final_payment' => $data->finalPayment,
            'final_payment_on' => $data->finalPaymentOn?->format('Y-m-d'),
            'cash_price' => $data->cashPrice,
            'customer_deposit' => $data->customerDeposit,
            'dealer_contribution' => $data->dealerContribution,
            'initial_rental' => $data->initialRental,
            'amount_of_credit' => $data->amountOfCredit,
            'total_amount_payable' => $data->totalAmountPayable,
            'apr' => $data->apr,
            'documentation_fee' => $data->documentationFee,
            'option_to_purchase_fee' => $data->optionToPurchaseFee,
            'annual_mileage_allowance' => $data->annualMileageAllowance,
            'mileage_unit' => $data->mileageUnit->value,
            'excess_mileage_charge' => $data->excessMileageCharge,
            'start_odometer_km' => $data->startOdometerKm,
            'count_in_costs' => $data->countInCosts,
            'notes' => $data->notes,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private static function types(): array
    {
        return [
            'number_of_payments' => ParameterType::INTEGER,
            'annual_mileage_allowance' => ParameterType::INTEGER,
            'count_in_costs' => ParameterType::BOOLEAN,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FinanceAgreement
    {
        $platform = $this->connection->getDatabasePlatform();
        $money = static fn (string $column): ?string => Row::nullableDecimal($row, $column, self::MONEY_SCALE);
        $date = static fn (string $column): DateTimeImmutable => Row::nullableDate($row, $column)
            ?? throw new UnexpectedValueException(sprintf('Column "%s" is null.', $column));

        return new FinanceAgreement(
            id: Row::int($row, 'id'),
            vehicleId: Row::int($row, 'vehicle_id'),
            data: new AgreementData(
                type: AgreementType::tryFrom(Row::string($row, 'type'))
                    ?? throw new UnexpectedValueException('Unknown agreement type.'),
                lender: Row::string($row, 'lender'),
                agreementNumber: Row::nullableString($row, 'agreement_number'),
                startedOn: $date('started_on'),
                firstPaymentOn: $date('first_payment_on'),
                numberOfPayments: Row::int($row, 'number_of_payments'),
                regularPayment: Row::decimal($row, 'regular_payment', self::MONEY_SCALE),
                firstPayment: $money('first_payment'),
                finalPayment: $money('final_payment'),
                finalPaymentOn: Row::nullableDate($row, 'final_payment_on'),
                cashPrice: $money('cash_price'),
                customerDeposit: Row::decimal($row, 'customer_deposit', self::MONEY_SCALE),
                dealerContribution: Row::decimal($row, 'dealer_contribution', self::MONEY_SCALE),
                initialRental: $money('initial_rental'),
                amountOfCredit: $money('amount_of_credit'),
                totalAmountPayable: $money('total_amount_payable'),
                apr: Row::decimal($row, 'apr', 3),
                documentationFee: $money('documentation_fee'),
                optionToPurchaseFee: $money('option_to_purchase_fee'),
                annualMileageAllowance: Row::nullableInt($row, 'annual_mileage_allowance'),
                mileageUnit: DistanceUnit::tryFrom(Row::string($row, 'mileage_unit')) ?? DistanceUnit::Mile,
                excessMileageCharge: Row::nullableDecimal($row, 'excess_mileage_charge', 4),
                startOdometerKm: Row::nullableDecimal($row, 'start_odometer_km', 3),
                countInCosts: Row::bool($row, 'count_in_costs'),
                notes: Row::nullableString($row, 'notes'),
            ),
            status: AgreementStatus::tryFrom(Row::string($row, 'status')) ?? AgreementStatus::Active,
            endedOn: Row::nullableDate($row, 'ended_on'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
            createdBy: Row::nullableInt($row, 'created_by'),
        );
    }
}
