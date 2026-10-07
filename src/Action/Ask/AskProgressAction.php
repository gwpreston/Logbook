<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Repository\AiProgressRepository;
use Logbook\Support\Http\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET /insights/questions/progress/{token} — the progress lines of the user's running
 * question, as JSON, polled by the page about once a second (spec.md §7.26,
 * decided #73). An unknown token is "thinking", not an error: the
 * question may not have started yet. Once done, `url` is the thread the
 * answer went to.
 */
final readonly class AskProgressAction
{
    public function __construct(
        private AskGuard $guard,
        private AiProgressRepository $progress,
        private TranslatorInterface $translator,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $progress = $this->progress->find($user->id, $args['token'] ?? '');
        $tools = $progress->tools ?? [];
        $lines = array_values(array_unique(array_map(
            fn (string $tool): string => $this->translator->trans('ask.progress.' . $tool),
            array_filter($tools, static fn (string $tool): bool => preg_match('/^[a-z_]{1,40}$/', $tool) === 1),
        )));

        $threadId = $progress?->threadId;
        $response->getBody()->write(json_encode([
            'done' => $progress->done ?? false,
            // Once answered: where, so the page gets there even if a proxy
            // gave up on the POST that asked.
            'url' => $threadId === null ? null : $this->redirect->urlFor('insights.question', ['thread' => (string) $threadId]),
            'line' => $lines === [] ? $this->translator->trans('ask.progress.thinking') : $lines[array_key_last($lines)],
            'lines' => $lines,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
