<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use DateTimeZone;
use Logbook\Domain\Ai\Ask\FeedbackMark;
use Logbook\Repository\AiFeedbackRepository;
use Logbook\Repository\AiThreadRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /insights/questions/messages/{message}/feedback — *Helpful* or *Not right* on one
 * of the user's answers (spec.md §7.26, decided #71): the mark goes on the
 * answer, and the month's count moves with it.
 */
final readonly class AskFeedbackAction
{
    public function __construct(
        private AskGuard $guard,
        private AiThreadRepository $threads,
        private AiFeedbackRepository $feedback,
        private AppSettings $settings,
        private ClockInterface $clock,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $value = RequestContext::form($request)['mark'] ?? null;
        $mark = is_string($value) ? FeedbackMark::tryFrom($value) : null;
        $id = $args['message'] ?? '';
        if ($mark === null || !ctype_digit($id)) {
            throw new HttpNotFoundException($request);
        }
        [$found, $before] = $this->threads->mark($user->id, (int) $id, $mark);
        if (!$found) {
            throw new HttpNotFoundException($request);
        }
        if ($before !== $mark) {
            $month = $this->clock->now()->setTimezone(new DateTimeZone($this->settings->timezone))->format('Y-m');
            $this->feedback->count($month, $mark);
            if ($before !== null) {
                $this->feedback->count($month, $before, -1);
            }
        }
        RequestContext::session($request)->flash('success', 'ask.flash.feedback');

        return $this->redirect->backOr($request, 'insights');
    }
}
