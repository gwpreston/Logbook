<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Service\Ai\Ask\AskPage;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /ask and GET /ask/threads/{thread} — the question box, the threads,
 * and the open thread's questions and answers (spec.md §7.26).
 */
final readonly class AskAction
{
    public function __construct(
        private AskGuard $guard,
        private AskPage $page,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $user = $this->guard->user($request);
        $thread = isset($args['thread']) ? $this->guard->thread($request, $user, $args) : null;
        $question = $request->getQueryParams()['q'] ?? null;

        return $this->view->render(
            $request,
            $response,
            'ask/index.twig',
            $this->page->context(
                $user,
                $thread,
                is_string($question) ? mb_substr($question, 0, AskPostAction::MAX_LENGTH) : null,
            ),
        );
    }
}
