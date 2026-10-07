<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Service\Ai\Ask\AskPage;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /insights/questions/{thread} — one of the user's threads on its own
 * page under Insights (spec.md §7.26, Phase 38, #274): the questions and
 * answers with their sources, and the follow-up box.
 */
final readonly class AskThreadAction
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
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $thread = $this->guard->thread($request, $user, $args);

        return $this->view->render(
            $request,
            $response,
            'insights/question.twig',
            $this->page->thread($user, $thread, PendingQuestion::take(RequestContext::session($request))),
        );
    }
}
