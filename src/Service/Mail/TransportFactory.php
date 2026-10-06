<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use SensitiveParameter;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds a mail transport for a server (spec.md §7.11 *One transport*).
 * MailerFactory is the one implementation the app has; tests bind their own.
 */
interface TransportFactory
{
    public function build(SmtpServer $server, #[SensitiveParameter] ?string $password): TransportInterface;
}
