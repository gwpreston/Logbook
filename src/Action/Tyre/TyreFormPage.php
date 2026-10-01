<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use DateTimeImmutable;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreRetireReason;
use Logbook\Domain\Tyre\TyreSeason;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreFormContext;
use Logbook\Service\Tyre\TyreFormContexts;
use Logbook\Service\Tyre\TyreService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the tyre change forms (spec.md §7.17) — the six kinds and the
 * change edit form — and builds what they may choose from.
 */
final readonly class TyreFormPage
{
    public function __construct(
        private View $view,
        private TyreService $tyres,
        private OdometerService $odometer,
        private TyreFormContexts $contexts,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * @param DateTimeImmutable $on the change's date (for the link candidates)
     */
    public function context(
        Vehicle $vehicle,
        DateTimeImmutable $today,
        DateTimeImmutable $on,
        ?int $currentLink = null,
    ): TyreFormContext {
        return $this->contexts->for($vehicle, $today, $on, $currentLink);
    }

    /**
     * A new form's values beyond the date and odometer: the free road
     * positions ticked for *Tyres already on the vehicle*; each fitted
     * tyre's position for *Rotate*; for *Swap set*, the positions a stored
     * set had (the set in $fitSet, else the only one in storage); a worn
     * tyre retired by *Fit tyres*.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public function defaults(Vehicle $vehicle, TyreChangeKind $kind, TyreFormContext $context, array $values, ?int $fitSet): array
    {
        switch ($kind) {
            case TyreChangeKind::Existing:
                foreach ($context->rollingPositions() as $position) {
                    if (!isset($context->fitted[$position->value])) {
                        $values['pos_' . $position->value] = '1';
                    }
                }
                break;
            case TyreChangeKind::Fit:
                foreach ($context->fitted as $code => $tyre) {
                    $values['replace_' . $code] = TyreRetireReason::Worn->value;
                }
                break;
            case TyreChangeKind::Rotate:
                foreach ($context->fitted as $code => $tyre) {
                    $values['move_' . $tyre->id] = $code;
                }
                break;
            case TyreChangeKind::Swap:
                $sets = array_values(array_unique(array_map(static fn ($t): ?int => $t->setId, $context->stored)));
                $chosen = $fitSet ?? (count($sets) === 1 ? $sets[0] : false);
                if ($chosen !== false) {
                    $last = $this->tyres->lastPositions($vehicle);
                    foreach ($context->stored as $tyre) {
                        $position = $last[$tyre->id] ?? null;
                        if ($tyre->setId === $chosen && $position !== null && $position->isRolling()) {
                            $values['on_' . $tyre->id] = $position->value;
                        }
                    }
                }
                break;
            case TyreChangeKind::Repair:
            case TyreChangeKind::Remove:
            case TyreChangeKind::Check:
                break;
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        TyreChangeKind $kind,
        TyreFormContext $context,
        string $currency,
        array $values,
        ?TyreChange $change = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $on = $change?->data->doneOn ?? $context->today;
        $byId = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            $byId[$tyre->id] = $tyre;
        }

        return $this->view->render($request, $response, $change === null ? 'tyres/form.twig' : 'tyres/change_edit.twig', [
            'vehicle' => $vehicle,
            'kind' => $kind,
            'change' => $change,
            'context' => $context,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'seasons' => TyreSeason::cases(),
            'reasons' => TyreRetireReason::cases(),
            'sets' => $this->tyres->sets($vehicle),
            'links' => $context->maintenance
                ? $this->tyres->linkCandidates($vehicle, $on, $change?->data->maintenanceEntryId)
                : [],
            'latest' => $this->odometer->history($vehicle)->latest(),
            'tyres_by_id' => $byId,
        ], $status);
    }

    /**
     * A refusal as form errors, its dates in the owner's format.
     */
    public function errors(TyreChangeRefused $refused): ValidationErrors
    {
        $params = [];
        foreach ($refused->params as $name => $value) {
            $params[$name] = $value instanceof DateTimeImmutable ? $this->formatter->date($value) : $value;
        }
        $errors = new ValidationErrors();
        $errors->add($refused->field, $refused->key, $params);

        return $errors;
    }
}
