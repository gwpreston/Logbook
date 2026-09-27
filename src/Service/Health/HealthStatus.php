<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Failing = 'failing';
}
