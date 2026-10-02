<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\EndAgreement;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Incident\TotalLoss;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/archive — hide a sold/retired vehicle from active
 * views, keeping its history. A POST without `disposal` archives in one
 * click. A vehicle with a settled write-off or a finance agreement gets the
 * confirm page (spec.md §7.29 *Total loss*, §7.32 *Archive page*):
 *
 * - *Written off*, with the incident and the settlement as the sale date
 *   and price;
 * - *Sold* (while an agreement is active, not for a lease), with the sale
 *   date and price; an active HP or PCP warns and offers *Settled from the
 *   sale*, which ends it as settled early on the sale date;
 * - *Returned to the lender* (PCP: the optional final payment as the sale
 *   price) or *Returned to the lessor* (lease: no sale price), ending an
 *   active agreement as handed back or lease ended on the sale date;
 * - *Just archive*, which leaves an active agreement as it is.
 */
final readonly class ArchiveVehicleAction
{
    public const string WRITTEN_OFF = 'written_off';
    private const int MONEY_SCALE = 3;
    private const int MONEY_WHOLE_DIGITS = 11;

    public function __construct(
        private VehicleService $vehicles,
        private TotalLoss $totalLoss,
        private FinanceService $finance,
        private View $view,
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
        $show = $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
        if ($vehicle->isArchived()) {
            return $show;
        }
        $candidates = $this->totalLoss->candidates($vehicle);
        $agreement = $this->finance->archiveAgreement($user, $vehicle);
        $finance = $agreement === null ? null : $this->finance->view($user, $vehicle, $agreement);
        $choices = self::choices($candidates, $agreement);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        if ($request->getMethod() !== 'POST') {
            if ($candidates === [] && $finance === null) {
                return $show;
            }
            $query = $request->getQueryParams();
            $asked = is_string($query['disposal'] ?? null) ? $query['disposal'] : '';
            $disposal = in_array($asked, $choices, true) && $asked !== '' ? $asked : $choices[0];
            // *Use its settlement* without JS: the chosen incident's date and price.
            $values = ['disposal' => $disposal] + $this->prefill($user, $vehicle, $candidates, $finance, $today, $query);

            return $this->render($request, $response, $user, $vehicle, $candidates, $finance, $choices, $values);
        }

        $input = RequestContext::form($request);
        $chosen = $input['disposal'] ?? '';
        if ($chosen === '' || !in_array($chosen, $choices, true)) {
            $this->vehicles->archive($user, $vehicle);
            RequestContext::session($request)->flash('success', 'vehicle.archived', ['name' => $vehicle->name()]);

            return $show;
        }

        $validator = new Validator($input, $user->preferences->locale);
        $incidentId = null;
        if ($chosen === self::WRITTEN_OFF) {
            $incidentId = $validator->choice(
                'incident_id',
                array_map(static fn (Incident $incident): string => (string) $incident->id, $candidates),
                true,
            );
        }
        $saleDate = $validator->date('sale_date', true);
        // Handed back is a sale at the optional final payment (#123); a lease returned has no sale price.
        $salePrice = match ($chosen) {
            Disposal::ReturnedLessor->value => null,
            Disposal::ReturnedLender->value => $agreement?->data->finalPayment ?? '0',
            default => $validator->decimal('sale_price', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS),
        };
        $purchased = $vehicle->data->purchaseDate;
        if ($purchased !== null && $saleDate !== null && $saleDate < $purchased) {
            $validator->addError('sale_date', 'vehicle.sale_before_purchase');
        }

        $end = null;
        if ($agreement !== null && $agreement->status->isActive() && $saleDate !== null) {
            $settleFromSale = ($input['settle_from_sale'] ?? '') === '1';
            $today = $this->finance->ownerToday($user, $vehicle);
            $end = $this->endFor($validator, $agreement, $chosen, $settleFromSale, $saleDate, $today);
        }

        $priceMissing = $salePrice === null && $chosen !== Disposal::ReturnedLessor->value;
        if (!$validator->errors()->isEmpty() || $saleDate === null || $priceMissing) {
            $values = RequestContext::formValues($request);

            $errors = $validator->errors();

            return $this->render($request, $response, $user, $vehicle, $candidates, $finance, $choices, $values, $errors, 422);
        }

        if ($chosen === self::WRITTEN_OFF) {
            $this->vehicles->archiveWrittenOff($user, $vehicle, (int) $incidentId, $saleDate, (string) $salePrice);
            RequestContext::session($request)->flash('success', 'vehicle.archived_written_off', ['name' => $vehicle->name()]);

            return $show;
        }

        if ($end !== null && $agreement !== null) {
            $this->finance->end($user, $vehicle, $agreement, $end);
        }
        $this->vehicles->archiveAs($user, $vehicle, Disposal::from($chosen), $saleDate, $salePrice);
        RequestContext::session($request)->flash('success', 'vehicle.archived_as.' . $chosen, ['name' => $vehicle->name()]);

        return $show;
    }

    /**
     * The page's choices in order, the first chosen by default, ending with
     * *Just archive* ('').
     *
     * @param list<Incident> $candidates
     * @return list<string>
     */
    private static function choices(array $candidates, ?FinanceAgreement $agreement): array
    {
        $choices = $candidates === [] ? [] : [self::WRITTEN_OFF];
        if ($agreement !== null) {
            $active = $agreement->status->isActive();
            $choices = [...$choices, ...match ($agreement->type()) {
                AgreementType::Pcp => $active
                    ? [Disposal::Sold->value, Disposal::ReturnedLender->value]
                    : [Disposal::ReturnedLender->value],
                AgreementType::Lease => [Disposal::ReturnedLessor->value],
                default => $active ? [Disposal::Sold->value] : [],
            }];
        }
        $choices[] = '';

        return $choices;
    }

    /**
     * What ending the active agreement this way means, checked: *Settled
     * from the sale* with its amount, or handed back or lease ended on the
     * sale date. Null when the agreement is left as it is.
     */
    private function endFor(
        Validator $validator,
        FinanceAgreement $agreement,
        string $chosen,
        bool $settleFromSale,
        DateTimeImmutable $saleDate,
        DateTimeImmutable $today,
    ): ?EndAgreement {
        $outcome = match ($chosen) {
            Disposal::Sold->value => $agreement->type()->hasCashPrice() && $settleFromSale
                ? AgreementStatus::Settled
                : null,
            Disposal::ReturnedLender->value => AgreementStatus::HandedBack,
            Disposal::ReturnedLessor->value => AgreementStatus::Ended,
            default => null,
        };
        if ($outcome === null) {
            return null;
        }
        if ($saleDate > $today) {
            $validator->addError('sale_date', 'finance.end.error_future');
        } elseif ($saleDate < $agreement->data->startedOn) {
            $validator->addError('sale_date', 'finance.end.error_before_start');
        }
        $settlement = null;
        if ($outcome === AgreementStatus::Settled) {
            $settlement = $validator->decimal('settlement', true, 2, '0', null, self::MONEY_WHOLE_DIGITS);
        }

        return new EndAgreement($outcome, $saleDate, $settlement);
    }

    /**
     * @param list<Incident> $candidates
     * @param array<mixed> $query
     * @return array<string, string>
     */
    private function prefill(
        User $user,
        Vehicle $vehicle,
        array $candidates,
        ?AgreementView $finance,
        DateTimeImmutable $today,
        array $query,
    ): array {
        $values = [];
        if ($finance !== null) {
            $agreement = $finance->agreement;
            $values['sale_date'] = ($agreement->endedOn ?? $today)->format('Y-m-d');
            $defaults = $this->finance->endDefaults($user, $vehicle, $finance);
            $values['settlement'] = $defaults['settlement'] ?? '';
            $values['settle_from_sale'] = '1';
        }
        if ($candidates !== []) {
            $chosen = $query['incident_id'] ?? null;
            $incident = $candidates[0];
            foreach ($candidates as $candidate) {
                $incident = (string) $candidate->id === $chosen ? $candidate : $incident;
            }
            $values = self::incidentPrefill($incident, $today) + $values;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function incidentPrefill(Incident $incident, DateTimeImmutable $today): array
    {
        return [
            'incident_id' => (string) $incident->id,
            'sale_date' => TotalLoss::saleDate($incident, $today)->format('Y-m-d'),
            'sale_price' => TotalLoss::salePrice($incident),
        ];
    }

    /**
     * @param list<Incident> $candidates
     * @param list<string> $choices
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        array $candidates,
        ?AgreementView $finance,
        array $choices,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $prefills = [];
        foreach ($candidates as $incident) {
            $prefills[$incident->id] = self::incidentPrefill($incident, $today);
        }

        return $this->view->render($request, $response, 'vehicles/archive.twig', [
            'vehicle' => $vehicle,
            'candidates' => $candidates,
            'prefills' => $prefills,
            'finance' => $finance,
            'choices' => $choices,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
        ], $status);
    }
}
