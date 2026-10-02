<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Jobs\JobsOverview;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /settings/jobs/url-token — a new token for the external URL,
 * shown once on the page that answers; the old one stops working
 * (spec.md §7.30).
 */
final readonly class JobUrlTokenAction
{
    public function __construct(
        private JobSettings $settings,
        private JobsPage $page,
        private JobsOverview $overview,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $this->settings->regenerateUrlToken();

        return $this->page->render($request, $response, [
            'new_token' => $token,
            'token_url' => $this->overview->urlFor($token),
        ]);
    }
}
