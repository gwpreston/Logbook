<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\Ask\AskPage;
use Logbook\Service\Ai\Ask\Conversation;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /ask — ask a question, new or a follow-up (`thread`), then show
 * the thread at its answer. Without JS this is the whole flow; with JS the
 * page posts in the background (`X-Ask: 1`), polls the progress token, and
 * gets JSON back with where to go.
 */
final readonly class AskPostAction
{
    public const int MAX_LENGTH = 1000;

    public function __construct(
        private AskGuard $guard,
        private AskPage $page,
        private Conversation $conversation,
        private Redirector $redirect,
        private View $view,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->guard->user($request);
        $form = RequestContext::form($request);
        $question = trim(is_string($form['question'] ?? null) ? $form['question'] : '');
        $threadId = is_string($form['thread'] ?? null) ? $form['thread'] : '';
        $thread = $threadId === '' ? null : $this->guard->thread($request, $user, ['thread' => $threadId]);
        $token = is_string($form['progress'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $form['progress']) === 1
            ? $form['progress']
            : null;
        $background = $request->getHeaderLine('X-Ask') === '1';

        $error = match (true) {
            $question === '' => $this->translator->trans('ask.validation.required'),
            mb_strlen($question) > self::MAX_LENGTH => $this->translator->trans(
                'ask.validation.too_long',
                ['max' => self::MAX_LENGTH],
            ),
            default => null,
        };
        if ($error === null) {
            // Every tool call and model call, then the answer.
            set_time_limit(Conversation::DEADLINE_SECONDS + 660);
            try {
                $outcome = $this->conversation->ask($user, $thread, $question, $token);
                $url = $this->redirect->urlFor('ask.thread', ['thread' => (string) $outcome->thread->id])
                    . '#answer-' . $outcome->answer->id;
                if ($background) {
                    return self::json($response, ['url' => $url]);
                }

                return $this->redirect->to($url);
            } catch (AiFailure $failure) {
                $error = $this->translator->trans($failure->messageKey(), $failure->messageParameters());
            }
        }

        if ($background) {
            return self::json($response, ['error' => $error], 422);
        }

        return $this->view->render(
            $request,
            $response,
            'ask/index.twig',
            $this->page->context($user, $thread, mb_substr($question, 0, self::MAX_LENGTH), $error),
            422,
        );
    }

    /**
     * @param array<string, string> $body
     */
    private static function json(ResponseInterface $response, array $body, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
