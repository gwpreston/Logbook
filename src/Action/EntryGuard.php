<?php

declare(strict_types=1);

namespace Logbook\Action;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Support\Http\AccessDeniedException;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The own-entry rule for entry edit and delete routes (spec.md §5 *Access
 * policy*): those routes declare `Log`, and the Action asks here once it has
 * loaded the entry. With Manage any entry; with Log only one's own (403).
 */
final readonly class EntryGuard
{
    public function __construct(private EntryAccess $access)
    {
    }

    public function allowChange(ServerRequestInterface $request, Vehicle $vehicle, ?int $createdBy): void
    {
        if (!$this->access->canChange(RequestContext::requireUser($request), $vehicle, $createdBy)) {
            throw new AccessDeniedException($request);
        }
    }
}
