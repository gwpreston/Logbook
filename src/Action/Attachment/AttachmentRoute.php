<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentNotFound;
use Logbook\Service\Attachment\AttachmentService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared request plumbing for the /vehicles/{id}/attachments/{attachment} routes.
 */
final class AttachmentRoute
{
    /**
     * The attachment named by the route on this vehicle, or a 404.
     *
     * @param array<string, string> $args
     */
    public static function attachment(
        AttachmentService $attachments,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): Attachment {
        try {
            return $attachments->get($vehicle, (int) ($args['attachment'] ?? 0));
        } catch (AttachmentNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The edit page of the entry an attachment belongs to.
     *
     * @return array{string, array<string, string>} route name and parameters
     */
    public static function ownerPage(Attachment $attachment): array
    {
        $vehicle = (string) $attachment->vehicleId;
        $owner = (string) $attachment->ownerId;

        return match ($attachment->ownerType) {
            AttachmentOwner::Fuel => ['fuel.edit', ['id' => $vehicle, 'entry' => $owner]],
            AttachmentOwner::Maintenance => ['maintenance.edit', ['id' => $vehicle, 'entry' => $owner]],
            AttachmentOwner::Compliance => ['compliance.edit', ['id' => $vehicle, 'document' => $owner]],
            AttachmentOwner::Expense => ['expenses.edit', ['id' => $vehicle, 'entry' => $owner]],
            AttachmentOwner::Odometer => ['odometer.edit', ['id' => $vehicle, 'reading' => $owner]],
        };
    }
}
