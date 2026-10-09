<?php

declare(strict_types=1);

namespace Logbook\Action\MotHistory;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\MotHistoryFetcher;
use Logbook\Service\MotHistory\MotHistoryUnavailable;
use Logbook\Service\MotHistory\MotReview;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/mot-history/fetch — *Fetch MOT history* and
 * *Refresh* (spec.md §7.38 *Fetching*), `Own` only (#321). The first
 * fetch carries the owner's confirmation (`confirm`), stored once. After a
 * fetch with anything new to decide, the review card.
 */
final readonly class FetchMotHistoryAction
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotHistoryFetcher $fetcher,
        private MotReview $review,
        private FeatureToggles $features,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);
        $form = RequestContext::form($request);
        $back = ['id' => (string) $vehicle->id];
        if (($form['confirm'] ?? null) === '1') {
            $this->fetcher->confirm($vehicle);
        }

        try {
            $outcome = $this->fetcher->fetch($vehicle);
        } catch (MotHistoryUnavailable $unavailable) {
            $session->flash('error', $unavailable->messageKey());

            return $this->redirect->toRoute('mot_history.show', $back);
        } catch (MotHistoryFailure $failure) {
            $error = $failure->error;
            $session->flash('error', $error->userMessageKey(), $error->isCredentials() ? [] : $failure->parameters);

            return $this->redirect->toRoute('mot_history.show', $back);
        }

        $plate = (string) $vehicle->data->registration;
        if (!$outcome->found) {
            $session->flash('warning', 'mot_history.fetch.not_found', ['registration' => $plate]);

            return $this->redirect->toRoute('mot_history.show', $back);
        }
        if ($outcome->refusedAs !== null) {
            $session->flash('error', 'mot_history.fetch.mismatch', [
                'registration' => $outcome->knownAs ?? $plate,
                'dvsa' => $outcome->refusedAs,
                'ours' => trim($vehicle->data->make . ' ' . $vehicle->data->model),
            ]);

            return $this->redirect->toRoute('mot_history.show', $back);
        }
        if ($this->features->isEnabled(Feature::Issues)) {
            $this->review->applyRepeats($vehicle, $user->preferences->timeZone());
        }
        $session->flash('success', 'mot_history.fetch.done', ['added' => $outcome->added, 'updated' => $outcome->updated]);
        if ($outcome->knownAs !== null) {
            $session->flash('info', 'mot_history.fetch.known_as', ['registration' => $outcome->knownAs]);
        }
        if ($outcome->modelAs !== null) {
            $session->flash('info', 'mot_history.fetch.model_as', ['model' => $outcome->modelAs]);
        }
        if ($outcome->undated > 0) {
            $session->flash('info', 'mot_history.fetch.undated', ['count' => $outcome->undated]);
        }

        return $this->review->card($vehicle, $this->features->isEnabled(Feature::Issues))->pending()
            ? $this->redirect->toRoute('mot_history.review', $back)
            : $this->redirect->toRoute('mot_history.show', $back);
    }
}
