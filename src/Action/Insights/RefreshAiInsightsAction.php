<?php

declare(strict_types=1);

namespace Logbook\Action\Insights;

use Logbook\Domain\Ai\ErrorCode;
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
        // What fills the message's {connection}, {model}, … (as AiFailure::messageParameters()).
        $parameters = [];
        try {
            $set = $this->insights->generate($user);
            $error = $set->error?->messageKey();
            $parameters = [
                'connection' => $set->connectionName ?? '',
                'model' => $set->model ?? '',
                'seconds' => 0,
                'variable' => '',
            ];
        } catch (AiFailure $failure) {
            // Busy is another AI request of the user's, not "your last question".
            $error = $refused = $failure->error === ErrorCode::Busy ? 'ai_insights.busy' : $failure->messageKey();
            $parameters = $failure->messageParameters();
        }

        if ($background) {
            // Kept (even as the day's failure): the page reloads. Not kept (busy): it says why and stops.
            $response->getBody()->write(json_encode(
                [
                    'saved' => $refused === null,
                    'error' => $refused === null ? null : $this->translator->trans($refused, $parameters),
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }
        if ($error !== null) {
            RequestContext::session($request)->flash('error', $error, $parameters);
        }

        return $this->redirect->to($this->redirect->urlFor('insights') . '#ai-insights');
    }
}
