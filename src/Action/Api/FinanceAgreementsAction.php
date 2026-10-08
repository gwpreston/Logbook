<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /api/v1/vehicles/{id}/finance/agreements — every agreement, the
 * active one first, then ended ones newest first, each as
 * `GET …/finance` shows one, with its payment events and settlement
 * quotes (spec.md §7.20 *Phase 39*, §7.32). Never the agreement number;
 * 404 without §7.32's access, as the pages.
 */
final readonly class FinanceAgreementsAction
{
    public function __construct(
        private FinanceService $finance,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        if (!$this->finance->canSee($user, $vehicle)) {
            throw new HttpNotFoundException($request);
        }

        return $this->responder->json(['items' => array_map(function (FinanceAgreement $agreement) use ($user, $vehicle): array {
            $view = $this->finance->view($user, $vehicle, $agreement);

            return Serializer::financeAgreement($view) + [
                'events' => array_map(static fn (PaymentEvent $event): array => [
                    'id' => $event->id,
                    'kind' => $event->kind->value,
                    'due_on' => Serializer::date($event->dueOn),
                    'amount' => Serializer::dec($event->amount, Serializer::QUANTITY_SCALE),
                    'paid_on' => Serializer::date($event->paidOn),
                    'notes' => $event->notes,
                ], $view->events),
                'quotes' => array_map(static fn (SettlementQuote $quote): array => [
                    'id' => $quote->id,
                    'quoted_on' => Serializer::date($quote->quotedOn),
                    'amount' => Serializer::dec($quote->amount, Serializer::QUANTITY_SCALE),
                    'valid_until' => Serializer::date($quote->validUntil),
                    'notes' => $quote->notes,
                ], $view->quotes),
            ];
        }, $this->finance->forVehicle($user, $vehicle))]);
    }
}
