<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Jobs;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/jobs — the background jobs, their runs, the scheduler's
 * health and how jobs run (spec.md §7.30). Admins only; 404 to anyone
 * else.
 */
final readonly class JobsAction
{
    public function __construct(private JobsPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->render($request, $response);
    }
}
