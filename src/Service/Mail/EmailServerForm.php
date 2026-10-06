<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

/**
 * Settings → Delivery → *Email server*: the form's fields, and what was
 * typed checked and turned into a server (spec.md §7.11). The password is
 * kept apart and never put back into the form's values.
 */
final readonly class EmailServerForm
{
    public const array FIELDS = ['host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'admin_recipient'];
    public const int PASSWORD_MAX = 1000;

    /**
     * @param array<string, string> $values what to show in the form (never the password)
     * @param array<string, string> $errors translation keys by field
     * @param list<string> $warnings translation keys
     */
    private function __construct(
        public array $values,
        public ?SmtpServer $server,
        public ?string $password,
        public bool $removePassword,
        public array $errors,
        public array $warnings,
    ) {
    }

    /**
     * The form for a saved server (or an empty one).
     *
     * @return array<string, string>
     */
    public static function values(?SmtpServer $server): array
    {
        return [
            'host' => $server->host ?? '',
            'port' => $server === null ? '' : (string) $server->port,
            'encryption' => ($server->encryption ?? MailEncryption::Tls)->value,
            'username' => $server->username ?? '',
            'from_address' => $server->fromAddress ?? '',
            'from_name' => $server->fromName ?? SmtpServer::DEFAULT_FROM_NAME,
            'admin_recipient' => $server->adminRecipient ?? '',
        ];
    }

    /**
     * @param array<mixed> $input the posted form
     */
    public static function parse(array $input): self
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
        $password = is_string($input['password'] ?? null) ? trim($input['password']) : '';
        $errors = [];

        foreach ($values + ['password' => $password] as $field => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                $errors[$field] = 'delivery.email.error.line_break';
            }
        }

        $host = $values['host'];
        if (!isset($errors['host']) && !self::validHost($host)) {
            $errors['host'] = $host === '' ? 'delivery.email.error.host_required' : 'delivery.email.error.host';
        }
        $encryption = MailEncryption::tryFrom($values['encryption']);
        if ($encryption === null) {
            $errors['encryption'] = 'delivery.email.error.encryption';
        }
        $port = $encryption?->defaultPort() ?? 587;
        if ($values['port'] !== '') {
            $port = ctype_digit($values['port']) ? (int) $values['port'] : 0;
            if ($port < 1 || $port > 65535) {
                $errors['port'] = 'delivery.email.error.port';
            }
        }
        if (!isset($errors['username']) && mb_strlen($values['username']) > 254) {
            $errors['username'] = 'delivery.email.error.username';
        }
        if (!isset($errors['password']) && mb_strlen($password) > self::PASSWORD_MAX) {
            $errors['password'] = 'delivery.email.error.password_length';
        }
        if (!isset($errors['from_address']) && !self::validAddress($values['from_address'])) {
            $errors['from_address'] = $values['from_address'] === ''
                ? 'delivery.email.error.from_required'
                : 'delivery.email.error.address';
        }
        if (!isset($errors['from_name']) && mb_strlen($values['from_name']) > 100) {
            $errors['from_name'] = 'delivery.email.error.from_name';
        }
        $recipient = $values['admin_recipient'];
        if (!isset($errors['admin_recipient']) && $recipient !== '' && !self::validAddress($recipient)) {
            $errors['admin_recipient'] = 'delivery.email.error.address';
        }

        $warnings = $encryption === MailEncryption::None && $values['username'] !== ''
            ? ['delivery.email.warning.unencrypted']
            : [];

        $server = $errors === []
            ? new SmtpServer(
                host: strtolower($host),
                port: $port,
                encryption: $encryption,
                username: $values['username'] === '' ? null : $values['username'],
                fromAddress: $values['from_address'],
                fromName: $values['from_name'] === '' ? SmtpServer::DEFAULT_FROM_NAME : $values['from_name'],
                adminRecipient: $values['admin_recipient'] === '' ? null : $values['admin_recipient'],
            )
            : null;

        return new self(
            $values,
            $server,
            $password === '' ? null : $password,
            ($input['remove_password'] ?? null) === '1',
            $errors,
            $warnings,
        );
    }

    /**
     * A host name or an IP address: no scheme, path, port or space.
     */
    public static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return preg_match('/^(?=.*[a-z])' . $label . '(?:\.' . $label . ')*$/i', $host) === 1;
    }

    public static function validAddress(string $address): bool
    {
        return $address !== '' && mb_strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
