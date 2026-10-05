<?php

declare(strict_types=1);

use Logbook\Kernel;
use Logbook\Support\Http\AfterResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Kernel::createApp(Kernel::settings());
$app->run();

// Work deferred until the answer has gone (spec.md §7.9 *Forgotten password*).
$after = $app->getContainer()->get(AfterResponse::class);
if ($after instanceof AfterResponse) {
    $after->run(finishRequest: true);
}
