<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Api\ApiReminders;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/reminders/{reminder}/done, /dismiss, /reopen — the Reminders
 * page's one-click forms (spec.md §7.20 *Reminder actions*). 200 with the
 * reminder; `"unchanged": true` when the action was already in effect.
 */
final readonly class ReminderActionAction
{
    public function __construct(
        private ApiReminders $reminders,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $action = $args['action'] ?? '';
        if (!in_array($action, ApiReminders::ACTIONS, true)) {
            throw new \LogicException(sprintf('The route names no reminder action ("%s").', $action));
        }

        $ability = VehicleAbility::tryFrom($args[VehicleAccessMiddleware::ABILITY] ?? '')
            ?? throw new \LogicException('A reminder route declares no vehicle ability.');

        return $this->responder->json($this->reminders->act(
            RequestContext::requireUser($request),
            (int) ($args['reminder'] ?? 0),
            $action,
            $ability,
        ));
    }
}
