<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\FinanceAgreementNotFound;
use Logbook\Service\Finance\FinanceService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class FinanceRoute
{
    /**
     * The agreement named by the route, or a 404 (also for someone who may
     * not see finance, spec.md §7.32 *Access*).
     *
     * @param array<string, string> $args
     */
    public static function agreement(
        FinanceService $finance,
        User $user,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): FinanceAgreement {
        try {
            return $finance->get($user, $vehicle, (int) ($args['agreement'] ?? 0));
        } catch (FinanceAgreementNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * A 404 for someone who may not see the vehicle's finance.
     */
    public static function guard(FinanceService $finance, User $user, Vehicle $vehicle, ServerRequestInterface $request): void
    {
        if (!$finance->canSee($user, $vehicle)) {
            throw new HttpNotFoundException($request);
        }
    }
}
