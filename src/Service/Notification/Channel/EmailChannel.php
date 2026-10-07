<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Mail\MailConfig;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email over SMTP (symfony/mailer) to the server saved in Settings →
 * Delivery (spec.md §7.11). Plain text: the subject is the notification
 * title, the body its message and a link to the app.
 */
final readonly class EmailChannel implements NotificationChannel
{
    public function __construct(private MailConfig $config, private TransportInterface $transport)
    {
    }

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'notifications.channel.email';
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function reaches(Recipient $recipient): bool
    {
        return $this->isConfigured() && $this->addressOf($recipient) !== null;
    }

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $to = $this->addressOf($recipient);
        if ($to === null) {
            return DeliveryResult::failed($this->key(), 'No email address (set one on your profile).');
        }
        $server = $this->config->effective();
        if ($server === null) {
            return DeliveryResult::failed($this->key(), 'Email is not set up.');
        }

        $email = (new Email())
            ->from($server->from())
            ->to(new Address($to, $recipient->name))
            ->subject($notification->title)
            ->text($notification->textWithLink());
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        try {
            $this->transport->send($email);
        } catch (TransportExceptionInterface $e) {
            return DeliveryResult::failed($this->key(), $e->getMessage());
        }

        return DeliveryResult::delivered($this->key());
    }

    /** Their own address; the default recipient for admins is an admin's only (spec.md §7.11). */
    private function addressOf(Recipient $recipient): ?string
    {
        return $recipient->email ?? ($recipient->isAdmin ? $this->config->adminRecipient() : null);
    }
}
