<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Support\Session\Session;

/**
 * A question a stale `POST /ask` carried (spec.md §7.26 *Where*, Phase 38):
 * kept in the session, not the URL, and put back in the box on the page
 * the redirect lands on, once. Never asked.
 */
final class PendingQuestion
{
    private const string KEY = 'ask.pending_question';

    public static function keep(Session $session, string $question): void
    {
        $session->set(self::KEY, mb_substr($question, 0, AskPostAction::MAX_LENGTH));
    }

    public static function take(Session $session): ?string
    {
        $question = $session->get(self::KEY);
        if ($question === null) {
            return null;
        }
        $session->remove(self::KEY);

        return is_string($question) ? $question : null;
    }
}
