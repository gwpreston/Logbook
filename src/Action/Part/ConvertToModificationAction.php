<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\EntryGuard;
use Logbook\Action\Expense\ExpenseRoute;
use Logbook\Action\Maintenance\MaintenanceRoute;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Part\ModificationForm;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET/POST /vehicles/{id}/maintenance/{entry}/modification and
 * /vehicles/{id}/expenses/{entry}/modification (`from`): *Make this a
 * modification* (spec.md §7.39, #423). One record at a time, never
 * automatic: the form is prefilled from it, and saving creates the
 * modification, moves the files and deletes the record together.
 */
final readonly class ConvertToModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private MaintenanceService $maintenance,
        private ExpenseService $expenses,
        private VehicleService $vehicles,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
        private CostInput $costs,
        private DeclaredInput $declared,
        private ClockInterface $clock,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $fromExpense = ($args['from'] ?? '') === 'expense';
        $entry = $fromExpense
            ? ExpenseRoute::entry($this->expenses, $vehicle, $request, $args)
            : MaintenanceRoute::entry($this->maintenance, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $entry->createdBy);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $refusal = $entry instanceof MaintenanceEntry ? $this->modifications->refusalFor($vehicle, $entry) : null;
        $render = fn (array $values, ?ValidationErrors $errors = null, int $status = 200): ResponseInterface
            => $this->view->render($request, $response, 'parts/convert.twig', [
                'vehicle' => $vehicle,
                'entry' => $entry,
                'from' => $fromExpense ? 'expense' : 'maintenance',
                'refusal' => $refusal,
                'categories' => ModificationCategory::cases(),
                'currency' => $this->vehicles->currencyFor($user, $vehicle),
                'values' => $values,
                'errors' => $errors?->all() ?? [],
            ], $status);

        if ($request->getMethod() !== 'POST' || $refusal !== null) {
            return $render($this->prefill($entry, $user->preferences), null, $refusal === null ? 200 : 409);
        }

        $data = ModificationForm::parse(
            $this->declared->guard(
                $request,
                $vehicle,
                $this->costs->guard(
                    $request,
                    $vehicle,
                    RequestContext::form($request),
                    $this->prefill($entry, $user->preferences),
                ),
            ),
            $user->preferences,
            $today,
        );
        if ($data instanceof ValidationErrors) {
            return $render(RequestContext::formValues($request), $data, 422);
        }
        try {
            $modification = $entry instanceof ExpenseEntry
                ? $this->modifications->fromExpense($vehicle, $entry, $data, $zone)
                : $this->modifications->fromMaintenance($vehicle, $entry, $data, $zone);
        } catch (PartChangeRefused $refused) {
            return $render(RequestContext::formValues($request), PartRoute::refusal($refused, $this->formatter), 409);
        }
        RequestContext::session($request)->flash('success', 'modification.converted', ['title' => $modification->data->title]);

        return $this->redirect->toRoute('modifications.show', [
            'id' => (string) $vehicle->id,
            'modification' => (string) $modification->id,
        ]);
    }

    /**
     * The record's date, mileage, cost and words as a modification's form.
     *
     * @return array<string, string>
     */
    private function prefill(MaintenanceEntry|ExpenseEntry $entry, DisplayPreferences $preferences): array
    {
        if ($entry instanceof ExpenseEntry) {
            $data = $entry->data;
            $text = $data->note ?? $this->translator->trans('expense.category.' . $data->category->value);

            return [
                'title' => self::title($text),
                'category' => ModificationCategory::Other->value,
                'done_on' => $data->spentOn->format('Y-m-d'),
                'cost' => Decimal::trim($data->amount),
                'notes' => self::overflow($text, null),
            ];
        }
        $data = $entry->data;

        return [
            'title' => self::title($data->title),
            'category' => ModificationCategory::Other->value,
            'done_on' => $data->performedOn->format('Y-m-d'),
            'odometer' => $data->odometerKm === null ? '' : OdometerReadingForm::distanceForDisplay(
                $data->odometerKm,
                $preferences,
            ),
            'cost' => Decimal::trim($data->cost),
            'fitted_by' => $data->vendor ?? '',
            'notes' => self::overflow($data->title, $data->description),
        ];
    }

    /**
     * A title or note longer than a modification's title: its first 120
     * characters (spec.md §7.39, #431).
     */
    private static function title(string $text): string
    {
        return mb_substr($text, 0, ModificationForm::TITLE_MAX);
    }

    /**
     * The notes, with the whole text first when the title had to be cut, so
     * nothing is lost when the record is deleted (#431).
     */
    private static function overflow(string $text, ?string $notes): string
    {
        if (mb_strlen($text) <= ModificationForm::TITLE_MAX) {
            return $notes ?? '';
        }

        return $notes === null || $notes === '' ? $text : $text . "\n\n" . $notes;
    }
}
