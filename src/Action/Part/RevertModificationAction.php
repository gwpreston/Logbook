<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\EntryGuard;
use Logbook\Domain\Part\PartOrigin;
use Logbook\Domain\Part\PartStatus;
use Logbook\Service\Part\ModificationForm;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/modifications/{modification}/revert, and POST
 * …/unrevert (`mode` undo): put the car back to standard, keeping the
 * modification in the record (spec.md §7.39 *Reverting a modification*).
 */
final readonly class RevertModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private PartService $parts,
        private CostInput $costs,
        private EntryGuard $guard,
        private View $view,
        private Redirector $redirect,
        private ClockInterface $clock,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        // Reverting is logging what happened to the car: `Log`, as *Fit* and *Remove* (spec.md §7.39 *Access*).
        $modification = PartRoute::modification($this->modifications, $vehicle, $request, $args);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $back = ['id' => (string) $vehicle->id, 'modification' => (string) $modification->id];
        $session = RequestContext::session($request);

        if (($args['mode'] ?? '') === 'undo') {
            // Undoing rewrites the record (and can take back a sale price someone else typed):
            // `EntryAccess`, as editing (spec.md §7.39 *Access*, #430).
            $this->guard->allowChange($request, $vehicle, $modification->createdBy);
            try {
                $this->modifications->undoRevert($vehicle, $modification);
                $session->flash('success', 'modification.unreverted', ['title' => $modification->data->title]);
            } catch (PartChangeRefused $refusal) {
                $session->flash('error', $refusal->key, PartRoute::params($refusal, $this->formatter));
            }

            return $this->redirect->toRoute('modifications.show', $back);
        }
        if ($modification->isReverted()) {
            return $this->redirect->toRoute('modifications.show', $back);
        }

        $removable = [];
        $refittable = [];
        $made = $this->parts->byRecord($vehicle, PartService::MODIFICATION, $modification->id);
        foreach ($made as ['part' => $part, 'fitting' => $fitting]) {
            if ($fitting->fittedModificationId === $modification->id && $fitting->isOpen()) {
                $removable[$part->id] = $part;
            }
            if (
                $fitting->removedModificationId === $modification->id
                && $part->status === PartStatus::Stored && $part->data->origin === PartOrigin::Original
            ) {
                $refittable[$part->id] = $part;
            }
        }
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $render = fn (array $values, ?ValidationErrors $errors = null, int $status = 200): ResponseInterface
            => $this->view->render($request, $response, 'parts/revert.twig', [
                'vehicle' => $vehicle,
                'modification' => $modification,
                'removable' => array_values($removable),
                'refittable' => array_values($refittable),
                'values' => $values,
                'errors' => $errors?->all() ?? [],
            ], $status);

        if ($request->getMethod() !== 'POST') {
            $values = ['reverted_on' => $today->format('Y-m-d')];
            foreach ($removable as $id => $part) {
                $values['remove_' . $id] = '1';
                $values['remove_' . $id . '_outcome'] = 'kept';
            }
            foreach ($refittable as $id => $part) {
                $values['refit_' . $id] = '1';
            }

            return $render($values);
        }

        $parsed = ModificationForm::parseRevert(
            $this->costs->guard($request, $vehicle, RequestContext::form($request), withCost: false),
            $user->preferences,
            $today,
            $modification,
            array_keys($removable),
            array_keys($refittable),
        );
        if ($parsed instanceof ValidationErrors) {
            return $render(RequestContext::formValues($request), $parsed, 422);
        }
        try {
            $this->modifications->revert(
                $vehicle,
                $modification,
                $parsed['on'],
                $parsed['km'],
                $parsed['note'],
                $parsed['remove'],
                $parsed['refit'],
                $zone,
            );
        } catch (PartChangeRefused $refusal) {
            return $render(RequestContext::formValues($request), PartRoute::refusal($refusal, $this->formatter), 422);
        }
        $session->flash('success', 'modification.reverted_flash', ['title' => $modification->data->title]);

        return $this->redirect->toRoute('modifications.show', $back);
    }
}
