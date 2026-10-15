<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartForm;
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
 * GET/POST /vehicles/{id}/parts/{part}/fit and …/remove (`mode`): *Fit* /
 * *Fit again* and *Remove* without a record (spec.md §7.39, #418), each
 * with a reading of its own.
 */
final readonly class FitPartAction
{
    public function __construct(
        private PartService $parts,
        private CostInput $costs,
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
        $part = PartRoute::part($this->parts, $vehicle, $request, $args);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $remove = ($args['mode'] ?? 'fit') === 'remove';
        $back = ['id' => (string) $vehicle->id, 'part' => (string) $part->id];
        if ($part->status !== ($remove ? PartStatus::Fitted : PartStatus::Stored)) {
            return $this->redirect->toRoute('parts.show', $back);
        }
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $render = fn (array $values, ?ValidationErrors $errors = null, int $status = 200): ResponseInterface
            => $this->view->render($request, $response, 'parts/fit.twig', [
                'vehicle' => $vehicle,
                'part' => $part,
                'remove' => $remove,
                'outcomes' => RemovalOutcome::cases(),
                'values' => $values,
                'errors' => $errors?->all() ?? [],
            ], $status);

        if ($request->getMethod() !== 'POST') {
            return $render($remove
                ? ['removed_on' => $today->format('Y-m-d'), 'outcome' => RemovalOutcome::Kept->value]
                : ['fit_on' => $today->format('Y-m-d')]);
        }

        $input = $this->costs->guard($request, $vehicle, RequestContext::form($request));
        try {
            if ($remove) {
                $parsed = PartForm::parseRemove($input, $user->preferences, $today);
                if ($parsed instanceof ValidationErrors) {
                    return $render(RequestContext::formValues($request), $parsed, 422);
                }
                $this->parts->remove($vehicle, $part, $parsed['on'], $parsed['km'], $parsed['removal'], $zone);
            } else {
                $parsed = PartForm::parseFit($input, $user->preferences, $today);
                if ($parsed instanceof ValidationErrors) {
                    return $render(RequestContext::formValues($request), $parsed, 422);
                }
                $this->parts->fit($vehicle, $part, $parsed['on'], $parsed['km'], $zone);
            }
        } catch (PartChangeRefused $refusal) {
            return $render(RequestContext::formValues($request), PartRoute::refusal($refusal, $this->formatter), 422);
        }
        RequestContext::session($request)->flash('success', $remove ? 'part.removed' : 'part.fitted', ['name' => $part->label()]);

        return $this->redirect->backOr($request, 'parts.show', $back);
    }
}
