<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Logbook\Domain\User\User;
use Logbook\Repository\SettingRepository;
use Psr\Clock\ClockInterface;

/**
 * Settings → Backup's warning (spec.md §6 `backup.warning`, §7.13): *Warn
 * me after* (7, 14, 30 or 90 days, or *Never*; 30 by default, #396) and
 * *I back up another way*, with who ticked it and when.
 */
final readonly class BackupWarning
{
    public const string SETTING = 'backup.warning';
    public const int DEFAULT_DAYS = 30;
    /** The choices, in the order the form lists them; null is *Never*. */
    public const array CHOICES = [7, 14, 30, 90];

    public function __construct(
        private SettingRepository $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The threshold in days, or null for *Never*.
     */
    public function days(): ?int
    {
        $value = $this->value();
        if (!array_key_exists('days', $value)) {
            return self::DEFAULT_DAYS;
        }
        $days = $value['days'];

        return $days === null ? null : (in_array($days, self::CHOICES, true) ? $days : self::DEFAULT_DAYS);
    }

    /**
     * Who said backups are handled outside Logbook, and when; null when
     * nobody has.
     *
     * @return array{by: int, at: DateTimeImmutable}|null
     */
    public function elsewhere(): ?array
    {
        $elsewhere = $this->value()['elsewhere'] ?? null;
        if (!is_array($elsewhere) || !is_int($elsewhere['by'] ?? null) || !is_string($elsewhere['at'] ?? null)) {
            return null;
        }
        try {
            return ['by' => $elsewhere['by'], 'at' => new DateTimeImmutable($elsewhere['at'])];
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Save the form. Ticking keeps an earlier tick's who and when; unticking
     * clears them.
     */
    public function save(?int $days, bool $elsewhere, User $by): void
    {
        if ($days !== null && !in_array($days, self::CHOICES, true)) {
            $days = self::DEFAULT_DAYS;
        }
        $stored = null;
        if ($elsewhere) {
            $current = $this->value()['elsewhere'] ?? null;
            $stored = $this->elsewhere() !== null && is_array($current) ? $current : [
                'by' => $by->id,
                'at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            ];
        }
        $this->settings->save(self::SETTING, ['days' => $days, 'elsewhere' => $stored]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function value(): array
    {
        $value = $this->settings->find(self::SETTING)?->value;

        return is_array($value) ? $value : [];
    }
}
