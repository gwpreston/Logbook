<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\ComplianceDocumentNotFound;
use Logbook\Service\Compliance\ComplianceService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/documents/{document} routes.
 */
final class ComplianceRoute
{
    /**
     * The document named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function document(
        ComplianceService $compliance,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): ComplianceDocument {
        try {
            return $compliance->get($vehicle, (int) ($args['document'] ?? 0));
        } catch (ComplianceDocumentNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
