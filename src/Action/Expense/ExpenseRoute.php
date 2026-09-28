<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\ExpenseEntryNotFound;
use Logbook\Service\Expense\ExpenseService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/expenses routes.
 */
final class ExpenseRoute
{
    /**
     * The expense named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function entry(
        ExpenseService $expenses,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): ExpenseEntry {
        try {
            return $expenses->get($vehicle, (int) ($args['entry'] ?? 0));
        } catch (ExpenseEntryNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
