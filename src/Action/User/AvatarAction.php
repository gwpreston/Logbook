<?php

declare(strict_types=1);

namespace Logbook\Action\User;

use Logbook\Repository\UserRepository;
use Logbook\Service\User\AvatarService;
use Logbook\Support\Http\FileResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /users/{member}/avatar?v=… (spec.md §7.9 *Avatars*): any signed-in
 * user may see any avatar (#161). Cached for a year: a new upload changes
 * `v`. A user without one is a 404 (the page shows their initials).
 */
final readonly class AvatarAction
{
    public function __construct(
        private UserRepository $users,
        private AvatarService $avatars,
        private FileResponder $files,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->users->find((int) ($args['member'] ?? 0));
        $file = $user === null ? null : $this->avatars->file($user);
        if ($user === null || $file === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->files->send(
            $request,
            $response,
            $file['path'],
            $file['mime'],
            hash('sha256', (string) $user->avatarPath),
        );
    }
}
