<?php

declare(strict_types=1);

namespace Logbook\Domain\Finance;

use DateTimeImmutable;

/**
 * A finance or lease agreement on a vehicle (spec.md §6 FinanceAgreement,
 * §7.32).
 */
final readonly class FinanceAgreement
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public AgreementData $data,
        public AgreementStatus $status,
        public ?DateTimeImmutable $endedOn,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it (Phase 19); null = a former user. */
        public ?int $createdBy = null,
    ) {
    }

    public function type(): AgreementType
    {
        return $this->data->type;
    }

    /**
     * The agreement number masked to its last 4 characters ("•••• 4417"),
     * as everywhere but the edit form shows it (spec.md §6, #120).
     */
    public function maskedNumber(): ?string
    {
        $number = $this->data->agreementNumber;
        if ($number === null) {
            return null;
        }

        return mb_strlen($number) <= 4 ? $number : '•••• ' . mb_substr($number, -4);
    }
}
