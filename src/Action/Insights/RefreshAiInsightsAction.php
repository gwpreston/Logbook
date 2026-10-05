<?php

declare(strict_types=1);

namespace Logbook\Action\Insights;

use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\Ask\Conversation;
use Logbook\Service\Ai\Insights\AiInsightService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /insights/refresh — make today's AI insights now (spec.md §7.26 *AI
 * insights*): *Refresh*, or the page's first view of the day, which js/app.js
 * posts in the background (`X-Insights: 1`, JSON back). One at a time: the
 * user's AI lock refuses a second while one runs.
 */
final readonly class RefreshAiInsightsAction
{
    public function __construct(
        private AiInsightService $insights,
        private Redirector $redirect,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        if (!$this->insights->isAvailable($user)) {
            throw new HttpNotFoundException($request);
        }
        $background = $request->getHeaderLine('X-Insights') === '1';

        set_time_limit(Conversation::DEADLINE_SECONDS + 660);
        ignore_user_abort(true);
        $error = null;
        $refused = null;
        try {
            $error = $this->insights->generate($user)->error?->messageKey();
        } catch (AiFailure $failure) {
            $error = $refused = $failure->messageKey();
        }

        if ($background) {
            // Kept (even as the day's failure): the page reloads. Not kept (busy): it says why and stops.
            $response->getBody()->write(json_encode(
                ['saved' => $refused === null, 'error' => $refused === null ? null : $this->translator->trans($refused)],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }
        if ($error !== null) {
            RequestContext::session($request)->flash('error', $error);
        }

        return $this->redirect->to($this->redirect->urlFor('insights') . '#ai-insights');
    }
}
