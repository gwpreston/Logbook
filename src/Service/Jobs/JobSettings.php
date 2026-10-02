<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\AppSettings;

/**
 * The scheduler's settings (spec.md §6 Setting `jobs.*`, §7.30): which
 * fallback triggers are on, the external URL's token, and scheduled
 * backups.
 */
final readonly class JobSettings
{
    public const string TRIGGERS = 'jobs.triggers';
    public const string URL_TOKEN = 'jobs.url_token';
    public const string URL_LAST_CALL = 'jobs.url_last_call';
    public const string BACKUP = 'jobs.backup';

    public function __construct(
        private SettingRepository $settings,
        private AppSettings $app,
    ) {
    }

    public function pageVisits(): bool
    {
        return $this->triggers()['page_visit'];
    }

    public function url(): bool
    {
        return $this->triggers()['url'];
    }

    public function setTriggers(bool $pageVisits, bool $url): void
    {
        $this->settings->save(self::TRIGGERS, ['page_visit' => $pageVisits, 'url' => $url]);
    }

    public function hasUrlToken(): bool
    {
        return $this->storedHash() !== null;
    }

    /**
     * A new URL token, replacing any old one: returned once, stored only as
     * its HMAC.
     */
    public function regenerateUrlToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->settings->save(self::URL_TOKEN, $this->hash($token));

        return $token;
    }

    public function urlTokenMatches(string $token): bool
    {
        $stored = $this->storedHash();

        return $stored !== null && hash_equals($stored, $this->hash($token));
    }

    public function urlLastCall(): ?DateTimeImmutable
    {
        $value = $this->settings->find(self::URL_LAST_CALL)?->value;

        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
    }

    public function markUrlCall(DateTimeImmutable $now): void
    {
        $this->settings->save(self::URL_LAST_CALL, $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function backupSchedule(): BackupSchedule
    {
        $value = $this->backup()['schedule'] ?? null;

        return is_string($value) ? (BackupSchedule::tryFrom($value) ?? BackupSchedule::Off) : BackupSchedule::Off;
    }

    public function backupKeep(): int
    {
        $keep = $this->backup()['keep'] ?? null;

        return is_int($keep) && $keep >= BackupSchedule::KEEP_MIN && $keep <= BackupSchedule::KEEP_MAX
            ? $keep
            : BackupSchedule::KEEP_DEFAULT;
    }

    public function setBackup(BackupSchedule $schedule, int $keep): void
    {
        $this->settings->save(self::BACKUP, ['schedule' => $schedule->value, 'keep' => $keep]);
    }

    /**
     * @return array{page_visit: bool, url: bool}
     */
    private function triggers(): array
    {
        $value = $this->settings->find(self::TRIGGERS)?->value;
        $value = is_array($value) ? $value : [];

        return [
            'page_visit' => ($value['page_visit'] ?? false) === true,
            'url' => ($value['url'] ?? false) === true,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function backup(): array
    {
        $value = $this->settings->find(self::BACKUP)?->value;

        return is_array($value) ? $value : [];
    }

    private function storedHash(): ?string
    {
        $value = $this->settings->find(self::URL_TOKEN)?->value;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->app->sessionSecret);
    }
}
