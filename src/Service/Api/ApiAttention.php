<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attention\AttentionItem;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Attention\AttentionWording;
use Logbook\Support\Api\Serializer;

/**
 * *Needs attention* over the API (spec.md §7.24, §7.20 *Phase 39*): the
 * items the overview card shows this user, in its order and words, each
 * with the API link of its fix where one exists and, for a hideable item,
 * the `key` 39.2's *Hide* takes. Hidden items are left out, as on the page.
 */
final readonly class ApiAttention
{
    public function __construct(
        private AttentionList $attention,
        private AttentionWording $wording,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<array<string, mixed>>
     */
    public function items(User $user, array $vehicles): array
    {
        return array_map(
            fn (AttentionItem $item): array => [
                'vehicle_id' => $item->vehicle->id,
                'kind' => $item->kind->value,
                'severity' => $item->severity()->value,
                'title' => $this->wording->title($item),
                'detail' => $this->wording->detail($item),
                'days' => $item->days,
                'reminder_id' => $item->reminderId,
                'key' => $item->canHide && $item->fingerprint !== null ? self::key($item) : null,
                'links' => ['fix' => self::link($item)],
            ],
            $this->attention->forVehicles($user, $vehicles)->items,
        );
    }

    /**
     * Names one hideable item as the page's *Hide* form does: vehicle,
     * kind, subject and the fingerprint of what was judged.
     */
    public static function key(AttentionItem $item): string
    {
        return sprintf('%d.%s.%d.%s', $item->vehicle->id, $item->kind->value, $item->subjectId, $item->fingerprint ?? '');
    }

    /**
     * The entry or list that fixes it, as a path under /api/v1, or null.
     */
    private static function link(AttentionItem $item): ?string
    {
        $vehicle = '/vehicles/' . $item->vehicle->id;

        return match ($item->kind) {
            AttentionKind::Reading => $vehicle . '/odometer/' . $item->subjectId,
            AttentionKind::FuelPrice => $vehicle . '/fuel/' . $item->subjectId,
            AttentionKind::MaintenanceCost => $vehicle . '/maintenance/' . $item->subjectId,
            AttentionKind::StalledClaim => $item->incident === null ? null : $vehicle . '/incidents/' . $item->incident->id,
            AttentionKind::ValuationStale => $vehicle . '/valuations',
            AttentionKind::MileageStale, AttentionKind::TripsExceed => $vehicle . '/odometer',
            AttentionKind::Economy, AttentionKind::DriftLiquid, AttentionKind::DriftElectric, AttentionKind::DriftGas
                => $vehicle . '/fuel',
            AttentionKind::FinanceMissed, AttentionKind::FinanceMileage => $vehicle . '/finance/agreements',
            AttentionKind::Overdue => '/reminders?vehicle=' . $item->vehicle->id,
            AttentionKind::IssueOpen, AttentionKind::IssueLookAgain => $vehicle . '/issues/' . $item->subjectId,
            AttentionKind::MotRecall => $vehicle . '/mot-tests',
        };
    }
}
