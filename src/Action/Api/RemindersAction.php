<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\QueryParams;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/reminders — open reminders, most urgent first, optionally of
 * one vehicle (`?vehicle=`) or one status (`?status=overdue|due|upcoming`)
 * (spec.md §7.20). From Phase 39.1 `?closed=1` (or `?status=done|dismissed`)
 * lists the closed ones instead, as the Reminders page does. Not paged.
 */
final readonly class RemindersAction
{
    public function __construct(
        private ApiReader $reader,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = VehicleFilter::fromRequest($request, $this->reader, $user);

        $status = QueryParams::code($request, 'status', ReminderStatus::class);
        $closed = QueryParams::flag($request, 'closed') || ($status !== null && !$status->isOpen());
        if ($closed && $status !== null && $status->isOpen()) {
            throw ApiProblem::invalidParameter('status', 'done or dismissed with closed=1.');
        }

        return $this->responder->json(['items' => $this->reader->reminders($user, $vehicle, $status, $closed)]);
    }
}
