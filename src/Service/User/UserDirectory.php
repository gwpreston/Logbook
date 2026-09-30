<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;

/**
 * Other people's accounts as a page needs them (Phase 19): a shared
 * vehicle's owner (their currency, their name on the garage card) and the
 * names in "Added by". Each user is read once per request.
 */
final class UserDirectory
{
    /** @var array<int, User|null> */
    private array $users = [];

    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function find(int $id): ?User
    {
        if (!array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->repository->find($id);
        }

        return $this->users[$id];
    }

    /** Null for a deleted user ("a former user"). */
    public function displayName(int $id): ?string
    {
        return $this->find($id)?->displayName;
    }

    public function forget(): void
    {
        $this->users = [];
    }
}
