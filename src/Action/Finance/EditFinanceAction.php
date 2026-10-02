<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceAgreementForm;
use Logbook\Service\Finance\FinanceCheck;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/finance/{agreement}/edit — correct an agreement's
 * figures (spec.md §7.32 *Form*). The type stays as it was; the agreement
 * number shows in full here only.
 */
final readonly class EditFinanceAction
{
    public function __construct(
        private FinanceService $finance,
        private FinanceFormPage $page,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $agreement = FinanceRoute::agreement($this->finance, $user, $vehicle, $request, $args);
        $type = $agreement->type();

        if ($request->getMethod() !== 'POST') {
            return $this->page->render(
                $request,
                $response,
                $user,
                $vehicle,
                $type,
                FinanceAgreementForm::values($agreement, $user->preferences),
                $agreement,
                checks: FinanceCheck::of($agreement->data),
            );
        }

        $input = FinanceAgreementForm::parse(['type' => $type->value] + RequestContext::form($request), $user->preferences);
        if ($input instanceof ValidationErrors) {
            $values = ['type' => $type->value] + RequestContext::formValues($request);

            return $this->page->render($request, $response, $user, $vehicle, $type, $values, $agreement, $input, 422);
        }

        $this->finance->update($user, $vehicle, $agreement, $input);
        RequestContext::session($request)->flash('success', 'finance.updated');

        return $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $agreement->id]);
    }
}
