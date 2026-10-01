<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Domain\Ai\Ask\AskThread;
use Logbook\Domain\User\User;
use Logbook\Repository\AiThreadRepository;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Every Ask page answers 404 unless Ask is available to the user (spec.md
 * §7.26 *Where*), and a thread that isn't theirs is not found.
 */
final readonly class AskGuard
{
    public function __construct(
        private AskAvailability $availability,
        private AiThreadRepository $threads,
    ) {
    }

    public function user(ServerRequestInterface $request): User
    {
        $user = RequestContext::requireUser($request);
        if (!$this->availability->isAvailable($user)) {
            throw new HttpNotFoundException($request);
        }

        return $user;
    }

    /**
     * @param array<string, string> $args
     */
    public function thread(ServerRequestInterface $request, User $user, array $args): AskThread
    {
        $id = $args['thread'] ?? '';

        return (ctype_digit($id) ? $this->threads->find($user->id, (int) $id) : null)
            ?? throw new HttpNotFoundException($request);
    }
}
