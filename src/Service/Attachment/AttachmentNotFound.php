<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use RuntimeException;

/**
 * No such attachment on this vehicle.
 */
final class AttachmentNotFound extends RuntimeException
{
}
