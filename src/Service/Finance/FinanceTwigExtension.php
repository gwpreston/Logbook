<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Service\Access\AccessContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `finance_menu(vehicle)`: whether the vehicle header shows the Finance tab
 * (spec.md §7.32 *Finance tab*; any non-null value does): `view` once the
 * vehicle has an agreement, `add` while it has none and isn't archived, or
 * null for someone who may not see finance. `finance_archive_offered(vehicle)`:
 * whether *Archive* opens the confirm page for its agreement (#126).
 */
final class FinanceTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly FinanceService $finance,
        private readonly FinanceAgreementRepository $agreements,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('finance_menu', function (Vehicle $vehicle): ?string {
                $user = $this->context->user();
                if ($user === null || !$this->finance->canSee($user, $vehicle)) {
                    return null;
                }
                if ($this->agreements->listForVehicle($vehicle->id) !== []) {
                    return 'view';
                }

                return $vehicle->isArchived() ? null : 'add';
            }),
            // Whether *Archive* opens the confirm page for the vehicle's agreement (#126).
            new TwigFunction('finance_archive_offered', function (Vehicle $vehicle): bool {
                $user = $this->context->user();

                return $user !== null && $this->finance->archiveAgreement($user, $vehicle) !== null;
            }),
        ];
    }
}
