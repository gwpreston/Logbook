<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings
 */
final readonly class SettingsAction
{
    public function __construct(private SettingsPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->render($request, $response);
    }
}
