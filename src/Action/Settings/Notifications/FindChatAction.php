<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Logbook\Service\Notification\Personal\TelegramSender;
use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/notifications/telegram/chats — Telegram *Find my chat*
 * (spec.md §7.11, #237): with the user's saved bot token, list the private
 * chats that wrote to the bot, each with a button that saves its ID.
 * Nothing from the answer is stored. Counted with the tests (5 per 10
 * minutes).
 */
final readonly class FindChatAction
{
    public function __construct(
        private NotificationsPage $page,
        private UserChannels $channels,
        private RateLimiter $limiter,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $sender = $this->channels->kinds()->get(TelegramSender::KEY);
        if (!$sender instanceof TelegramSender) {
            throw new HttpNotFoundException($request);
        }
        $kind = TelegramSender::KEY;
        $allowed = $this->limiter->attempt(
            ChannelAction::TEST_BUCKET,
            (string) $user->id,
            ChannelAction::TEST_MAX,
            ChannelAction::TEST_WINDOW,
        );
        if (!$allowed) {
            return $this->page->render($request, $response, $kind, null, 'notifications.test.throttled', 429);
        }

        $found = $this->channels->findChats($user, $sender);
        if ($found === null) {
            return $this->page->render($request, $response, $kind, null, 'notifications.telegram.find_needs_token', 422);
        }

        return $this->page->render($request, $response, $kind, null, null, 200, $found);
    }
}
