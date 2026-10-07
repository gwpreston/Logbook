<?php

declare(strict_types=1);

namespace Logbook\Action\Insights;

use Logbook\Action\Ask\AskPostAction;
use Logbook\Action\Ask\PendingQuestion;
use Logbook\Service\Insights\InsightsPage;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /insights — the Insights page (spec.md §7.26 *Ask and the Insights
 * page*, Phases 33.4 and 38). `?q=` (a suggestion) fills the *Ask
 * Logbook* box, as does a question an old `POST /ask` carried.
 */
final readonly class InsightsPageAction
{
    public function __construct(
        private InsightsPage $page,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams()['q'] ?? null;
        $question = PendingQuestion::take(RequestContext::session($request))
            ?? (is_string($query) ? mb_substr($query, 0, AskPostAction::MAX_LENGTH) : '');

        return $this->view->render($request, $response, 'insights/index.twig', $this->page->context($user, $question));
    }
}
