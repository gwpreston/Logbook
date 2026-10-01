<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Psr\Clock\ClockInterface;

/**
 * What a draft card shows (spec.md §7.26 *Draft card*), wherever it is
 * listed: in an Ask answer, or under *Drafts to review* (§7.28). Each card
 * has what Logbook formatted, where the draft stands, and the routes behind
 * *View* and *Edit*.
 */
final readonly class DraftCards
{
    public function __construct(
        private VehicleService $vehicles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param iterable<AiDraft> $drafts
     * @return array<int, array<string, mixed>> draft id → card
     */
    public function cards(User $user, iterable $drafts): array
    {
        $now = $this->clock->now();
        $cards = [];
        foreach ($drafts as $draft) {
            try {
                $vehicle = $this->vehicles->get($user, $draft->vehicleId);
            } catch (VehicleNotFound) {
                $vehicle = null;
            }
            $vehicleArgs = ['id' => (string) $draft->vehicleId];
            [$view, $edit, $editQuery] = match ($draft->kind) {
                DraftKind::Fuel => [['fuel.index', $vehicleArgs], ['fuel.create', $vehicleArgs], []],
                DraftKind::Odometer => [['odometer.index', $vehicleArgs], ['odometer.create', $vehicleArgs], []],
                DraftKind::Maintenance => [['maintenance.index', $vehicleArgs], ['maintenance.create', $vehicleArgs], []],
                DraftKind::Document => [['compliance.index', $vehicleArgs], ['compliance.create', $vehicleArgs], []],
                DraftKind::Expense => [['expenses.index', $vehicleArgs], ['expenses.create', $vehicleArgs], []],
                DraftKind::Incident => [['incidents.index', $vehicleArgs], ['incidents.create', $vehicleArgs], []],
                DraftKind::TyreCheck => [
                    ['tyres.index', $vehicleArgs],
                    ['tyres.change', $vehicleArgs + ['kind' => 'check']],
                    [],
                ],
                DraftKind::Reminder => [['reminders.index', []], ['reminders.create', []], ['vehicle' => $draft->vehicleId]],
            };
            $cards[$draft->id] = [
                'draft' => $draft,
                'state' => $draft->state($now)->value,
                'card' => $draft->card,
                'vehicle' => $vehicle,
                'can_undo' => $draft->canUndo($now),
                'undo_seconds' => max(
                    0,
                    AiDraft::UNDO_SECONDS - ($now->getTimestamp() - ($draft->appliedAt?->getTimestamp() ?? 0)),
                ),
                'view' => $view,
                'edit' => [$edit[0], $edit[1], $editQuery + ['draft' => $draft->id]],
            ];
        }

        return $cards;
    }
}
