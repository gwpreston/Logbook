<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\User;
use Logbook\Service\Notification\Channel\EmailConfig;
use Logbook\Support\Config\Env;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends an invitation link by email, when email is configured (spec.md
 * §7.9), in the inviting admin's language. The address is used once and
 * never stored.
 */
final readonly class InvitationMailer
{
    private EmailConfig $config;

    public function __construct(
        Env $env,
        private TransportInterface $transport,
        private TranslatorInterface $translator,
        private UserDisplayScope $scope,
        private LoggerInterface $logger,
    ) {
        $this->config = EmailConfig::fromEnv($env);
    }

    public function send(CreatedLink $link, string $address, User $admin): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }

        return $this->scope->run($admin, function () use ($link, $address, $admin): bool {
            $params = ['admin' => $admin->displayName, 'username' => $link->invitation->username];
            $email = (new Email())
                ->from(Address::create($this->config->from))
                ->to(new Address($address, $link->invitation->displayName))
                ->subject($this->translator->trans('users.email.subject', $params))
                ->text($this->translator->trans('users.email.body', $params) . "\n\n" . $link->url . "\n");
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

            try {
                $this->transport->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Invitation email could not be sent: {error}', ['error' => $e->getMessage()]);

                return false;
            }

            return true;
        });
    }
}
