<?php

declare(strict_types=1);

namespace Logbook\Domain\Notification;

/**
 * What a channel card says about a channel (spec.md §7.11 *Personal
 * channels*): in words with an icon, never colour alone.
 */
enum ChannelStatus: string
{
    case On = 'on';
    case Off = 'off';
    case NeedsSetup = 'needs_setup';
    case Blocked = 'blocked';
    case SwitchedOff = 'switched_off';
    case NotAvailable = 'not_available';

    public function labelKey(): string
    {
        return 'notifications.status.' . $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::On => 'check_circle',
            self::Off => 'radio_button_unchecked',
            self::NeedsSetup => 'build',
            self::Blocked => 'block',
            self::SwitchedOff => 'error',
            self::NotAvailable => 'cloud_off',
        };
    }

    /** The tone of the status badge (with its words and icon). */
    public function tone(): string
    {
        return match ($this) {
            self::On => 'ok',
            self::Off, self::NotAvailable => 'neutral',
            self::NeedsSetup, self::Blocked, self::SwitchedOff => 'warn',
        };
    }
}
