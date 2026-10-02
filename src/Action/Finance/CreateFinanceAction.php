<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\AgreementType;
use Logbook\Service\Finance\AgreementAlreadyActive;
use Logbook\Service\Finance\FinanceAgreementForm;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/finance/new?type=pcp — add an agreement from its
 * paperwork (spec.md §7.32 *Form*). A vehicle has at most one active
 * agreement.
 */
final readonly class CreateFinanceAction
{
    public function __construct(
        private FinanceService $finance,
        private FinanceFormPage $page,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        FinanceRoute::guard($this->finance, $user, $vehicle, $request);

        if ($vehicle->isArchived()) {
            RequestContext::session($request)->flash('error', 'finance.error.archived');

            return $this->redirect->toRoute('finance.index', ['id' => (string) $vehicle->id]);
        }
        if ($this->finance->active($vehicle) !== null) {
            RequestContext::session($request)->flash('error', 'finance.error.already_active');

            return $this->redirect->toRoute('finance.index', ['id' => (string) $vehicle->id]);
        }

        $form = $request->getMethod() === 'POST' ? RequestContext::form($request) : $request->getQueryParams();
        $chosen = $form['type'] ?? null;
        $type = AgreementType::tryFrom(is_string($chosen) ? $chosen : '') ?? AgreementType::Pcp;

        if ($request->getMethod() !== 'POST') {
            $today = LocalTime::today($this->clock, $user->preferences->timeZone());
            $values = FinanceAgreementForm::defaults($type, $today, $user->preferences);

            return $this->page->render($request, $response, $user, $vehicle, $type, $values);
        }

        $input = FinanceAgreementForm::parse(RequestContext::form($request), $user->preferences);
        if ($input instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $user, $vehicle, $type, $values, null, $input, 422);
        }

        try {
            $id = $this->finance->create($user, $vehicle, $input);
        } catch (AgreementAlreadyActive) {
            RequestContext::session($request)->flash('error', 'finance.error.already_active');

            return $this->redirect->toRoute('finance.index', ['id' => (string) $vehicle->id]);
        }
        RequestContext::session($request)->flash('success', 'finance.created');

        return $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $id]);
    }
}
