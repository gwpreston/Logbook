<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /profile (spec.md §8 *Profile page*).
 */
final readonly class ProfileAction
{
    public function __construct(private ProfilePage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->render($request, $response);
    }
}
