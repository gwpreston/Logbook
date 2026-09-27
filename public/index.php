<?php

declare(strict_types=1);

use Logbook\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

Kernel::createApp(Kernel::settings())->run();
