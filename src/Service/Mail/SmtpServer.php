<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use Symfony\Component\Mime\Address;

/**
 * The email server as saved in Settings → Delivery (the `email.smtp`
 * setting, spec.md §6): everything but the password, which is a
 * NotificationSecret. `hasPassword` records that one was saved, so a
 * restored install (secrets are never backed up) can say *Re-enter the
 * password* instead of quietly signing in without one.
 */
final readonly class SmtpServer
{
    public const string DEFAULT_FROM_NAME = 'Logbook';

    public function __construct(
        public string $host,
        public int $port,
        public MailEncryption $encryption,
        public ?string $username,
        public string $fromAddress,
        public string $fromName = self::DEFAULT_FROM_NAME,
        /** Reminders to an admin without a confirmed address go here (#225). */
        public ?string $adminRecipient = null,
        public bool $hasPassword = false,
    ) {
    }

    /**
     * From a stored setting; null when there is none or it is unusable.
     */
    public static function fromStored(mixed $value): ?self
    {
        if (!is_array($value) || !is_string($value['host'] ?? null) || $value['host'] === '') {
            return null;
        }
        $encryption = MailEncryption::tryFrom(is_string($value['encryption'] ?? null) ? $value['encryption'] : '')
            ?? MailEncryption::Tls;
        $port = is_int($value['port'] ?? null) && $value['port'] >= 1 && $value['port'] <= 65535
            ? $value['port']
            : $encryption->defaultPort();
        $text = static fn (string $key): ?string => is_string($value[$key] ?? null) && $value[$key] !== '' ? $value[$key] : null;

        return new self(
            host: $value['host'],
            port: $port,
            encryption: $encryption,
            username: $text('username'),
            fromAddress: $text('from_address') ?? 'logbook@localhost',
            fromName: $text('from_name') ?? self::DEFAULT_FROM_NAME,
            adminRecipient: $text('admin_recipient'),
            hasPassword: ($value['has_password'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed> the setting's value
     */
    public function toStored(?int $updatedBy, string $updatedAt): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption->value,
            'username' => $this->username,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'admin_recipient' => $this->adminRecipient,
            'has_password' => $this->hasPassword,
            'updated_at' => $updatedAt,
            'updated_by' => $updatedBy,
        ];
    }

    public function withPassword(bool $hasPassword): self
    {
        return new self(
            $this->host,
            $this->port,
            $this->encryption,
            $this->username,
            $this->fromAddress,
            $this->fromName,
            $this->adminRecipient,
            $hasPassword,
        );
    }

    public function from(): Address
    {
        return new Address($this->fromAddress, $this->fromName);
    }

    /**
     * The names of the fields that differ from another server's (for the
     * change log: names, never values).
     *
     * @return list<string>
     */
    public function changedFrom(?self $before): array
    {
        $fields = [
            'host' => [$this->host, $before?->host],
            'port' => [$this->port, $before?->port],
            'encryption' => [$this->encryption, $before?->encryption],
            'username' => [$this->username, $before?->username],
            'from_address' => [$this->fromAddress, $before?->fromAddress],
            'from_name' => [$this->fromName, $before?->fromName],
            'admin_recipient' => [$this->adminRecipient, $before?->adminRecipient],
        ];

        return array_keys(array_filter($fields, static fn (array $pair): bool => $pair[0] !== $pair[1]));
    }
}
