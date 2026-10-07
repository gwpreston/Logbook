<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /settings/notifications — Settings → Account → Notifications
 * (spec.md §7.11 *Personal channels*): every signed-in user's own page.
 */
final readonly class NotificationsAction
{
    public function __construct(private NotificationsPage $page)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->render($request, $response);
    }
}
