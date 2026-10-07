<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Repository\AiThreadRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Ask page's old addresses (spec.md §7.26 *Where*, Phase 38): `GET
 * /ask` (keeping `?q=`) and `GET /ask/threads/{thread}` move for good to
 * the Insights page and the thread's page. A `POST /ask` from a tab opened
 * before the move is never asked: its question goes back in the box it
 * came from (the thread's follow-up box when the thread is still the
 * user's, else Insights') through the session, so it is neither lost nor
 * written into a URL. Routed with AI off too; where Ask isn't available the
 * pages it lands on say so. With AI off a thread's old address goes to
 * Insights for now only (302): once AI is on it must reach the thread.
 */
final readonly class AskRedirectAction
{
    public function __construct(
        private Redirector $redirect,
        private AppSettings $settings,
        private AiThreadRepository $threads,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $form = RequestContext::form($request);
            $question = trim(is_string($form['question'] ?? null) ? $form['question'] : '');
            $thread = $this->settings->ai->enabled && is_string($form['thread'] ?? null) && ctype_digit($form['thread'])
                ? $form['thread']
                : null;
            // A thread since deleted, or not theirs: the question goes to the Insights box instead.
            if ($thread !== null && $this->threads->find(RequestContext::requireUser($request)->id, (int) $thread) === null) {
                $thread = null;
            }
            if ($question !== '') {
                PendingQuestion::keep(RequestContext::session($request), $question);
            }
            $url = $thread === null
                ? $this->redirect->urlFor('insights') . '#ask'
                : $this->redirect->urlFor('insights.question', ['thread' => $thread]) . '#ask-question';

            return $this->redirect->to($url, 303);
        }

        if (isset($args['thread'])) {
            // The thread pages are routed only with AI on.
            return $this->settings->ai->enabled
                ? $this->redirect->to($this->redirect->urlFor('insights.question', ['thread' => $args['thread']]), 301)
                : $this->redirect->to($this->redirect->urlFor('insights') . '#ask', 302);
        }
        $q = $request->getQueryParams()['q'] ?? null;

        return $this->redirect->to(
            $this->redirect->urlFor('insights', [], is_string($q) && $q !== '' ? ['q' => $q] : []) . '#ask',
            301,
        );
    }
}
