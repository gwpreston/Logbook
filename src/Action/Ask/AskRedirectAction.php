<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

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
 * came from (the thread's follow-up box, or Insights') through the
 * session, so it is neither lost nor written into a URL. Routed with AI
 * off too; where Ask isn't available the pages it lands on say so.
 */
final readonly class AskRedirectAction
{
    public function __construct(
        private Redirector $redirect,
        private AppSettings $settings,
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
            if ($question !== '') {
                PendingQuestion::keep(RequestContext::session($request), $question);
            }
            $url = $thread === null
                ? $this->redirect->urlFor('insights') . '#ask'
                : $this->redirect->urlFor('insights.question', ['thread' => $thread]) . '#ask-question';

            return $this->redirect->to($url, 303);
        }

        // The thread pages are routed only with AI on.
        if (isset($args['thread']) && $this->settings->ai->enabled) {
            return $this->redirect->to($this->redirect->urlFor('insights.question', ['thread' => $args['thread']]), 301);
        }
        $q = $request->getQueryParams()['q'] ?? null;

        return $this->redirect->to(
            $this->redirect->urlFor('insights', [], is_string($q) && $q !== '' ? ['q' => $q] : []) . '#ask',
            301,
        );
    }
}
