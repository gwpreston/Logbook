<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Repository\AiThreadRepository;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /insights/questions/{thread}/delete and POST
 * /insights/questions/delete (every thread) — the user's own threads only
 * (spec.md §7.26 *Conversations*); back to *Your questions* on Insights.
 */
final readonly class AskThreadDeleteAction
{
    public function __construct(
        private AskGuard $guard,
        private AiThreadRepository $threads,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $user = $this->guard->user($request);
        if (isset($args['thread'])) {
            $thread = $this->guard->thread($request, $user, $args);
            $this->threads->delete($user->id, $thread->id);
            RequestContext::session($request)->flash('success', 'ask.flash.deleted');
        } else {
            $this->threads->deleteAll($user->id);
            RequestContext::session($request)->flash('success', 'ask.flash.deleted_all');
        }

        return $this->redirect->to($this->redirect->urlFor('insights') . '#your-questions');
    }
}
