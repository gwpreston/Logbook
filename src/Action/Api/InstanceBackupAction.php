<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Backup\BackupAge;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/instance/backup — how old the last backup is, for Uptime
 * Kuma or Home Assistant to alert on (spec.md §7.20, §7.13, #399). The
 * route declares `Backup` (an admin), so any other key gets 403.
 */
final readonly class InstanceBackupAction
{
    public function __construct(
        private BackupAge $age,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $this->age->for(RequestContext::requireUser($request));

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
