<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Service\Ai\AiPreferences;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /ask/retention — how long the user's threads are kept: 1, 7, 30 or
 * 90 days (spec.md §7.26, decided #70).
 */
final readonly class AskRetentionAction
{
    public function __construct(
        private AskGuard $guard,
        private AiPreferences $preferences,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->guard->user($request);
        $days = RequestContext::form($request)['days'] ?? '';
        if (is_string($days) && ctype_digit($days)) {
            $this->preferences->setRetentionDays($user->id, (int) $days);
            RequestContext::session($request)->flash('success', 'ask.flash.retention');
        }

        return $this->redirect->toRoute('ask');
    }
}
