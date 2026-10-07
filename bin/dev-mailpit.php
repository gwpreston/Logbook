<?php

declare(strict_types=1);

/*
 * Development only (spec.md §10 *Development stack*, #259): point the email
 * server (Settings → Delivery) at the dev stack's Mailpit, so every email
 * the app sends lands in its inbox. Run by bin/dev-setup.sh.
 *
 * Does nothing when an email server is already saved (a developer's own
 * setup is never replaced), and refuses unless APP_ENV=development.
 *
 *   php bin/dev-mailpit.php
 *
 * Exit code: 0 set or already set, 1 failed, 2 not a development install.
 */

use Logbook\Repository\SettingRepository;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\SmtpServer;
use Logbook\Kernel;
use Logbook\Support\Config\AppEnvironment;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();
if ($settings->environment !== AppEnvironment::Development) {
    fwrite(STDERR, "logbook: dev-mailpit only runs with APP_ENV=development.\n");
    exit(2);
}

$container = Kernel::createApp($settings)->getContainer();
$mail = $container->get(MailConfig::class);
assert($mail instanceof MailConfig);
if ($mail->isConfigured()) {
    $host = $mail->effective()?->host ?? '?';
    fwrite(STDOUT, sprintf("logbook: an email server is already saved (%s); left as it is.\n", $host));
    exit(0);
}

try {
    $server = new SmtpServer('mailpit', 1025, MailEncryption::None, null, 'logbook@localhost');
    $repository = $container->get(SettingRepository::class);
    assert($repository instanceof SettingRepository);
    $repository->save(MailConfig::SETTING, $server->toStored(null, gmdate(DATE_ATOM)));
} catch (Throwable $e) {
    fwrite(STDERR, 'logbook: could not save the email server: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "logbook: email now goes to Mailpit (mailpit:1025).\n");
exit(0);
