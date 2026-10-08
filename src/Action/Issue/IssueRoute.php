<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Issue\IssueNotFound;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared by the issue actions (spec.md §7.37).
 */
final class IssueRoute
{
    /**
     * The issue named by the route, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function issue(IssueService $issues, Vehicle $vehicle, ServerRequestInterface $request, array $args): Issue
    {
        try {
            return $issues->get($vehicle, (int) ($args['issue'] ?? 0));
        } catch (IssueNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The update named by the route with its issue, or a 404.
     *
     * @param array<string, string> $args
     * @return array{0: Issue, 1: IssueUpdate}
     */
    public static function update(IssueService $issues, Vehicle $vehicle, ServerRequestInterface $request, array $args): array
    {
        try {
            return $issues->updateOnVehicle($vehicle, (int) ($args['update'] ?? 0));
        } catch (IssueNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The form's values as posted, strings only.
     *
     * @return array<string, string>
     */
    public static function formValues(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $values = [];
        foreach (is_array($body) ? $body : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * An archived vehicle keeps its issues read-only (spec.md §7.37 *Pages*):
     * a change is refused with a message, back to the issues.
     */
    public static function refuseArchived(
        ServerRequestInterface $request,
        Vehicle $vehicle,
        Redirector $redirect,
    ): ?ResponseInterface {
        if (!$vehicle->isArchived()) {
            return null;
        }
        RequestContext::session($request)->flash('error', 'issue.error.archived');

        return $redirect->toRoute('issues.index', ['id' => (string) $vehicle->id]);
    }
}
