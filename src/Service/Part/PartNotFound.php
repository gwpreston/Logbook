<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use RuntimeException;

/**
 * No such part (or fitting) on the vehicle: a 404.
 */
final class PartNotFound extends RuntimeException
{
}
