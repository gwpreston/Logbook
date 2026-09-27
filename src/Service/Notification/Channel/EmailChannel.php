<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Channel;

use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Recipient;
use Logbook\Support\Config\Env;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email over SMTP (symfony/mailer). Plain text: the subject is the
 * notification title, the body its message and a link to the app.
 */
final readonly class EmailChannel implements NotificationChannel
{
    private EmailConfig $config;

    public function __construct(Env $env, private TransportInterface $transport)
    {
        $this->config = EmailConfig::fromEnv($env);
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

    public function send(Notification $notification, Recipient $recipient): DeliveryResult
    {
        $to = $recipient->email ?? $this->config->to;
        if ($to === null) {
            return DeliveryResult::failed($this->key(), 'No email address (set one in Settings or MAIL_TO).');
        }

        $email = (new Email())
            ->from(Address::create($this->config->from))
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
}
