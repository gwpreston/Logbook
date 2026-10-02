<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\FinanceAgreementForm;
use Logbook\Service\Finance\FinanceCheck;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The agreement form (spec.md §7.32 *Form*), as a page and a desktop modal,
 * with the fields of its type.
 */
final readonly class FinanceFormPage
{
    public function __construct(
        private View $view,
        private VehicleService $vehicles,
        private FinanceService $finance,
    ) {
    }

    /**
     * @param array<string, string> $values
     * @param list<FinanceCheck> $checks
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        AgreementType $type,
        array $values,
        ?FinanceAgreement $agreement = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
        array $checks = [],
        ?string $refusal = null,
    ): ResponseInterface {
        $fields = [];
        foreach (FinanceAgreementForm::fieldsByType()[$type->value] as $field) {
            $fields[$field] = true;
        }

        return $this->view->render($request, $response, 'finance/form.twig', [
            'vehicle' => $vehicle,
            'agreement' => $agreement,
            'type' => $type,
            'types' => AgreementType::cases(),
            'fields' => $fields,
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'checks' => $checks,
            'refusal' => $refusal,
            'offers' => $this->finance->purchasePriceOffers($vehicle, $type),
        ], $status);
    }
}
