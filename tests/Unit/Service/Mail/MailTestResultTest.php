<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Mail;

use Logbook\Service\Mail\MailTestResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * *Send test email* names the stage that failed (spec.md §7.11), from
 * symfony/mailer's own wording.
 */
final class MailTestResultTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'refused' => [
            'Connection could not be established with host "smtp.example.com:587": stream_socket_client(): Connection refused',
            MailTestResult::CONNECTION,
        ];
        yield 'unknown host' => [
            'Connection could not be established with host "nowhere:25": stream_socket_client(): '
                . 'php_network_getaddresses: getaddrinfo failed',
            MailTestResult::CONNECTION,
        ];
        yield 'timeout' => ['Connection to "smtp.example.com:587" timed out.', MailTestResult::CONNECTION];
        yield 'implicit TLS certificate' => [
            'Connection could not be established with host "ssl://smtp.example.com:465": stream_socket_client(): '
                . 'SSL operation failed with code 1. OpenSSL Error messages: certificate verify failed',
            MailTestResult::ENCRYPTION,
        ];
        yield 'STARTTLS' => ['Unable to connect with STARTTLS.', MailTestResult::ENCRYPTION];
        yield 'TLS required' => ['TLS required but neither TLS or STARTTLS are in use.', MailTestResult::ENCRYPTION];
        yield 'credentials' => [
            'Failed to authenticate on SMTP server with username "me" using the following authenticators: "LOGIN".',
            MailTestResult::SIGN_IN,
        ];
        yield 'no authenticator' => ['Failed to find an authenticator supported by the SMTP server', MailTestResult::SIGN_IN];
        yield 'rejected' => [
            'Expected response code "250" but got code "550", with message "550 Relay denied".',
            MailTestResult::SEND,
        ];
    }

    #[DataProvider('messages')]
    public function testTheStageOfAMailerError(string $message, string $stage): void
    {
        self::assertSame($stage, MailTestResult::stageOf($message));
    }

    public function testResults(): void
    {
        self::assertTrue(MailTestResult::sent()->sent);
        $failed = MailTestResult::failed(MailTestResult::SEND, '550 no');
        self::assertFalse($failed->sent);
        self::assertSame('send', $failed->stage);
        self::assertSame('550 no', $failed->reply);
    }
}
