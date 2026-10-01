<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\Scan\ScanAvailability;
use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Every Scan page answers 404 unless scanning is available to the user
 * (spec.md §7.27 *Available*), and a scan that isn't theirs is not found.
 */
final readonly class ScanGuard
{
    public function __construct(
        private ScanAvailability $availability,
        private ScanReader $reader,
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
    public function upload(ServerRequestInterface $request, User $user, array $args): ScanUpload
    {
        return $this->reader->find($user, $args['token'] ?? '') ?? throw new HttpNotFoundException($request);
    }
}
