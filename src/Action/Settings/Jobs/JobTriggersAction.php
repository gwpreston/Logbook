<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Jobs\JobsOverview;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/jobs/triggers — *How jobs run*: the page-visit and
 * external-URL fallbacks on or off (spec.md §7.30). Turning the URL on
 * for the first time makes its token, shown once.
 */
final readonly class JobTriggersAction
{
    public function __construct(
        private JobSettings $settings,
        private JobsPage $page,
        private JobsOverview $overview,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = RequestContext::form($request);
        $url = ($form['url'] ?? '') === '1';
        $this->settings->setTriggers(($form['page_visit'] ?? '') === '1', $url);

        if ($url && !$this->settings->hasUrlToken()) {
            $token = $this->settings->regenerateUrlToken();

            return $this->page->render($request, $response, [
                'new_token' => $token,
                'token_url' => $this->overview->urlFor($token),
                'saved' => 'jobs.triggers.saved',
            ]);
        }
        RequestContext::session($request)->flash('success', 'jobs.triggers.saved');

        return $this->redirect->toRoute('settings.jobs');
    }
}
