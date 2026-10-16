<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Service\Part\InsurerList;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\Validator;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/{id}/parts/insurer — *Mark declared* and *Clear* (spec.md
 * §7.39 *For your insurer*, #444, #445): `Manage`. One button per row, or
 * *Mark all declared* for the undeclared ones; the date is today unless
 * the owner types one, not in the future and not before a modification's.
 */
final readonly class DeclareModificationsAction
{
    public function __construct(
        private InsurerList $list,
        private ModificationService $modifications,
        private Redirector $redirect,
        private DisplayFormatter $formatter,
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
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $session = RequestContext::session($request);
        $back = fn (): ResponseInterface => $this->redirect->toRoute('parts.insurer', ['id' => (string) $vehicle->id]);
        $input = RequestContext::form($request);
        $validator = new Validator($input, $user->preferences->locale);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        // The hidden first button: Enter in the date field submits that, and it changes nothing.
        if (isset($input['noop'])) {
            return $back();
        }
        $clear = is_string($input['clear'] ?? null) ? (int) $input['clear'] : 0;
        $only = is_string($input['only'] ?? null) ? $input['only'] : '';
        $rows = $this->list->forVehicle($vehicle);

        if ($clear > 0) {
            foreach ($rows as $row) {
                if ($row->modification->id === $clear && $row->isDeclared()) {
                    $this->modifications->declare($vehicle, $row->modification, null);
                    $session->flash('success', 'insurer.cleared', ['title' => $row->modification->data->title]);
                }
            }

            return $back();
        }

        $on = $validator->date('declared_on', true);
        if ($on !== null && $on > $today) {
            $validator->addError('declared_on', 'insurer.error.future');
        }
        if ($on === null || !$validator->errors()->isEmpty()) {
            $session->flash('error', 'insurer.error.date');

            return $back();
        }
        $targets = array_values(array_filter(
            $rows,
            static fn ($row): bool => ($only === 'all' && !$row->isDeclared()) || (string) $row->modification->id === $only,
        ));
        if ($targets === []) {
            return $back();
        }
        // All or none: a date before any chosen modification's own date refuses the lot.
        foreach ($targets as $row) {
            if ($on < $row->modification->data->doneOn) {
                $session->flash('error', 'modification.refused.declared_before', [
                    'title' => $row->modification->data->title,
                    'done' => $this->formatter->date($row->modification->data->doneOn),
                ]);

                return $back();
            }
        }
        try {
            foreach ($targets as $row) {
                $this->modifications->declare($vehicle, $row->modification, $on);
            }
        } catch (PartChangeRefused $refusal) {
            $session->flash('error', $refusal->key, PartRoute::params($refusal, $this->formatter));

            return $back();
        }
        $session->flash('success', 'insurer.declared_flash', ['count' => count($targets), 'date' => $this->formatter->date($on)]);

        return $back();
    }
}
