<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

/**
 * What *Send test email* found (spec.md §7.11): sent, or the stage that
 * failed with the server's reply, already redacted.
 */
final readonly class MailTestResult
{
    public const string CONNECTION = 'connection';
    public const string ENCRYPTION = 'encryption';
    public const string SIGN_IN = 'sign_in';
    public const string SEND = 'send';

    private function __construct(
        public bool $sent,
        /** @var self::CONNECTION|self::ENCRYPTION|self::SIGN_IN|self::SEND|null */
        public ?string $stage,
        public string $reply,
    ) {
    }

    public static function sent(): self
    {
        return new self(true, null, '');
    }

    /**
     * @param self::CONNECTION|self::ENCRYPTION|self::SIGN_IN|self::SEND $stage
     */
    public static function failed(string $stage, string $reply): self
    {
        return new self(false, $stage, $reply);
    }

    /**
     * The stage a mailer error belongs to, from symfony/mailer's wording.
     *
     * @return self::CONNECTION|self::ENCRYPTION|self::SIGN_IN|self::SEND
     */
    public static function stageOf(string $message): string
    {
        $message = strtolower($message);

        // A certificate failure on implicit TLS also says the connection failed: encryption first.
        return match (true) {
            self::any($message, ['authenticat']) => self::SIGN_IN,
            self::any($message, ['starttls', 'tls required', 'certificate', 'ssl operation', 'crypto']) => self::ENCRYPTION,
            self::any($message, ['connection could not be established', 'connection refused', 'timed out', 'getaddrinfo',
                'name or service not known', 'unable to connect', 'connection to']) => self::CONNECTION,
            default => self::SEND,
        };
    }

    /**
     * @param list<string> $needles
     */
    private static function any(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
