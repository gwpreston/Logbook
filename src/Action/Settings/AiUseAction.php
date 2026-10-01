<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Ai\AiStatus;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/ai-use — the signed-in user's *Use AI features* switch
 * (spec.md §7.25 *Users*). It exists only while AI is set up.
 */
final readonly class AiUseAction
{
    public function __construct(
        private AiPreferences $preferences,
        private AiStatus $status,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->status->isSetUp()) {
            throw new HttpNotFoundException($request);
        }
        $on = (RequestContext::form($request)['use_ai'] ?? '') === '1';
        $this->preferences->set(RequestContext::requireUser($request)->id, $on);
        RequestContext::session($request)->flash('success', $on ? 'ai.use.on' : 'ai.use.off');

        return $this->redirect->toRoute('settings');
    }
}
