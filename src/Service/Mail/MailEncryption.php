<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

/**
 * How the app talks to the email server (spec.md §7.11 *The email server*).
 */
enum MailEncryption: string
{
    /** STARTTLS, required. */
    case Tls = 'tls';
    /** Implicit TLS from the first byte. */
    case Ssl = 'ssl';
    /** Plain text: a local relay or Mailpit. */
    case None = 'none';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Tls => 587,
            self::Ssl => 465,
            self::None => 25,
        };
    }

    public function labelKey(): string
    {
        return 'delivery.email.encryptions.' . $this->value;
    }
}
