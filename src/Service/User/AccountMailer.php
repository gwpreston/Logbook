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
use Twig\Environment;

/**
 * Sends account email (spec.md §7.9: reset links, address confirmations
 * and notices) in the recipient's language, as plain text and HTML.
 * Nothing is sent while email is not configured.
 */
final readonly class AccountMailer
{
    private EmailConfig $config;

    public function __construct(
        Env $env,
        private TransportInterface $transport,
        private UserDisplayScope $scope,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
        $this->config = EmailConfig::fromEnv($env);
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * @param callable(): AccountMail $compose builds the email, run in the user's language
     * @return bool whether it was handed to the mail server
     */
    public function send(User $user, string $address, callable $compose): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }

        return $this->scope->run($user, function () use ($user, $address, $compose): bool {
            $mail = $compose();
            $context = ['mail' => $mail];
            $email = (new Email())
                ->from(Address::create($this->config->from))
                ->to(new Address($address, $user->displayName))
                ->subject($mail->subject)
                ->text($this->twig->render('email/account.txt.twig', $context))
                ->html($this->twig->render('email/account.html.twig', $context));
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

            try {
                $this->transport->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Account email for "{username}" could not be sent: {error}', [
                    'username' => $user->username,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            return true;
        });
    }
}
