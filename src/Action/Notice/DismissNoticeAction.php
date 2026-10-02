<?php

declare(strict_types=1);

namespace Logbook\Action\Notice;

use Logbook\Service\Jobs\AdminNotices;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /notices/{key}/dismiss — hides an admin notice on the dashboard
 * for 24 hours, for this admin (spec.md §7.30 *Admin notices*).
 */
final readonly class DismissNoticeAction
{
    public function __construct(
        private AdminNotices $notices,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->notices->dismiss(RequestContext::requireUser($request), $args['key'] ?? '');

        return $this->redirect->toRoute('home');
    }
}
