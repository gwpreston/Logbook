<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Backup\BackupAge;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/instance/backup — how old the last backup is, for Uptime
 * Kuma or Home Assistant to alert on (spec.md §7.20, §7.13, #399). Only a
 * key whose user has `Backup` (an admin); anyone else gets 403.
 */
final readonly class InstanceBackupAction
{
    public function __construct(
        private InstanceAccess $access,
        private BackupAge $age,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        if (!$this->access->can($user, InstanceAbility::Backup)) {
            throw new ApiProblem(403, 'forbidden', 'Only an admin\'s key may read the backup status.');
        }
        $status = $this->age->for($user);

        return $this->responder->json([
            'last' => $status->last === null ? null : [
                'at' => Serializer::instant($status->last->at),
                'kind' => $status->last->kind->value,
                'size' => $status->last->size,
            ],
            'age_days' => $status->ageDays,
            'warn_after_days' => $status->warnAfterDays,
            'handled_elsewhere' => $status->elsewhere !== null,
            'warning' => $status->warning,
        ]);
    }
}
