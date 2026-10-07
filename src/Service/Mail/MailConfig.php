<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use Logbook\Repository\SettingRepository;
use Throwable;

/**
 * The email server in use (spec.md §7.11 *The email server*): what an admin
 * saved in Settings → Delivery, or none. Settings are the only source; the
 * old `MAIL_*` variables are never read (#223). Read on every call, so a
 * change applies at once, in a long scheduler run too.
 */
final readonly class MailConfig
{
    public const string SETTING = 'email.smtp';
    public const string SOURCE_SETTINGS = 'settings';
    public const string SOURCE_NONE = 'none';

    public function __construct(private SettingRepository $settings)
    {
    }

    public function effective(): ?SmtpServer
    {
        try {
            return SmtpServer::fromStored($this->settings->find(self::SETTING)?->value);
        } catch (Throwable) {
            // No settings table yet (mid-upgrade): no email.
            return null;
        }
    }

    /**
     * @return self::SOURCE_* where the server comes from
     */
    public function source(): string
    {
        return $this->effective() === null ? self::SOURCE_NONE : self::SOURCE_SETTINGS;
    }

    public function isConfigured(): bool
    {
        return $this->effective() !== null;
    }

    /**
     * Where reminders to an admin without a confirmed address go (#225).
     */
    public function adminRecipient(): ?string
    {
        return $this->effective()?->adminRecipient;
    }
}
