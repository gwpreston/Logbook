<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Api\ApiReader;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/reminders — open reminders, most urgent first, optionally of
 * one vehicle (`?vehicle=`) or one status (`?status=overdue|due|upcoming`)
 * (spec.md §7.20). Not paged.
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

        $status = null;
        $raw = $request->getQueryParams()['status'] ?? null;
        if ($raw !== null) {
            $status = is_string($raw) ? ReminderStatus::tryFrom($raw) : null;
            if ($status === null || !$status->isOpen()) {
                throw ApiProblem::invalidParameter('status', 'one of overdue, due or upcoming.');
            }
        }

        return $this->responder->json(['items' => $this->reader->reminders($user, $vehicle, $status)]);
    }
}
