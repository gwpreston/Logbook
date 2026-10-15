<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use RuntimeException;

/**
 * No such modification on the vehicle: a 404.
 */
final class ModificationNotFound extends RuntimeException
{
}
