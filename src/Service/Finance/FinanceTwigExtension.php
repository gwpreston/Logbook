<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Service\Access\AccessContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `finance_menu(vehicle)`: what the vehicle header offers (spec.md §7.32
 * *Module*): `view` (the finance page) once the vehicle has an agreement,
 * `add` (*Add finance*) while it has none and isn't archived, or null for
 * someone who may not see finance.
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
        ];
    }
}
