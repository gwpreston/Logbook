<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Service\Mail\SmtpServer;
use Logbook\Service\Mail\TransportFactory;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands in for MailerFactory: records which server and password each
 * transport was built with, and sends into a RecordingMailTransport, or
 * fails with the server's message set in $failWith.
 */
final class RecordingTransportFactory implements TransportFactory
{
    /** @var list<array{server: SmtpServer, password: ?string}> */
    public array $built = [];
    public ?string $failWith = null;

    public function __construct(public readonly RecordingMailTransport $mail = new RecordingMailTransport())
    {
    }

    public function build(SmtpServer $server, #[SensitiveParameter] ?string $password): TransportInterface
    {
        $this->built[] = ['server' => $server, 'password' => $password];
        $failWith = $this->failWith;
        $mail = $this->mail;

        return new class ($mail, $failWith) implements TransportInterface {
            public function __construct(private RecordingMailTransport $mail, private ?string $failWith)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
            {
                if ($this->failWith !== null) {
                    throw new TransportException($this->failWith);
                }

                return $this->mail->send($message, $envelope);
            }

            public function __toString(): string
            {
                return 'recording-factory://';
            }
        };
    }
}
