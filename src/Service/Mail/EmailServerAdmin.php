<?php

declare(strict_types=1);

namespace Logbook\Service\Mail;

use Logbook\Domain\User\User;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Ai\Redactor;
use Logbook\Service\Ai\SecretUnreadable;
use Logbook\Support\Config\Env;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Delivery → *Email server* (spec.md §7.11): saving the server
 * and its password, removing them, and *Send test email* with what was
 * typed, unsaved. Changes are logged by field name, never by value.
 */
final readonly class EmailServerAdmin
{
    /** The variables Phase 36.1 removed: the page says so while any is still set. */
    public const array OLD_VARIABLES = [
        'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM', 'MAIL_TO',
    ];

    public function __construct(
        private MailConfig $config,
        private NotificationSecrets $secrets,
        private SettingRepository $settings,
        private TransportFactory $transports,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Env $env,
    ) {
    }

    /**
     * The saved password's state (never its value), or null when none is
     * saved. A server that had one but lost it (a restore: secrets are not
     * backed up) is `unreadable`, so the page says *Re-enter the password*.
     *
     * @return array{state: string, variable: ?string}|null
     */
    public function passwordState(): ?array
    {
        $state = $this->secrets->state(null, NotificationSecrets::SMTP_PASSWORD);
        if ($state === null && $this->config->effective()?->hasPassword === true) {
            return ['state' => 'unreadable', 'variable' => null];
        }

        return $state;
    }

    public function canSeal(): bool
    {
        return $this->secrets->canSeal();
    }

    public function canStore(string $password): bool
    {
        return $this->secrets->canStore($password);
    }

    /**
     * The removed `MAIL_*` variables that are still set in the environment.
     *
     * @return list<string>
     */
    public function oldVariables(): array
    {
        return array_values(array_filter(self::OLD_VARIABLES, fn (string $name): bool => $this->env->string($name) !== ''));
    }

    /**
     * Save the server; a typed password replaces the saved one, *Remove*
     * removes it, and an empty field keeps it.
     */
    public function save(SmtpServer $server, #[SensitiveParameter] ?string $password, bool $removePassword, User $admin): void
    {
        $before = $this->config->effective();
        $changed = $server->changedFrom($before);
        $hadPassword = $before?->hasPassword === true;

        if ($password !== null) {
            $this->secrets->store(null, NotificationSecrets::SMTP_PASSWORD, $password);
            $hasPassword = true;
            $changed[] = 'password';
        } elseif ($removePassword) {
            $this->secrets->remove(null, NotificationSecrets::SMTP_PASSWORD);
            $hasPassword = false;
            if ($hadPassword) {
                $changed[] = 'password';
            }
        } else {
            $hasPassword = $hadPassword;
        }

        $now = $this->clock->now()->format(DATE_ATOM);
        $this->settings->save(MailConfig::SETTING, $server->withPassword($hasPassword)->toStored($admin->id, $now));
        $this->logger->notice('Email server settings changed by user {user}: {fields}.', [
            'user' => $admin->id,
            'fields' => $changed === [] ? 'nothing' : implode(', ', $changed),
        ]);
    }

    /**
     * Remove the server and its password: email is off.
     */
    public function remove(User $admin): void
    {
        $this->settings->delete(MailConfig::SETTING);
        $this->secrets->remove(null, NotificationSecrets::SMTP_PASSWORD);
        $this->logger->notice('Email server removed by user {user}.', ['user' => $admin->id]);
    }

    /**
     * Whether a typed server is the saved one as far as the password goes:
     * the same host, port and username.
     */
    private function sameServer(SmtpServer $typed): bool
    {
        $saved = $this->config->effective();

        return $saved !== null && $saved->host === $typed->host && $saved->port === $typed->port
            && $saved->username === $typed->username;
    }

    /**
     * Send one message to $to with the typed server, unsaved. An empty
     * password field uses the saved password (unless *Remove* is ticked).
     */
    public function test(
        SmtpServer $server,
        #[SensitiveParameter] ?string $password,
        bool $removePassword,
        string $to,
        User $admin,
    ): MailTestResult {
        // No username: no sign-in. A typed `env:NAME` reads the variable now, as saving would.
        if ($server->username === null) {
            $password = null;
        } elseif ($password === null && !$this->sameServer($server)) {
            // The saved password goes only to the saved server: another host has to be typed with its own.
            $needed = $this->translator->trans('delivery.email.test.password_needed');

            return MailTestResult::failed(MailTestResult::SIGN_IN, $needed);
        } elseif ($password !== null || !$removePassword) {
            try {
                $password = $password === null
                    ? $this->secrets->open(null, NotificationSecrets::SMTP_PASSWORD)
                    : $this->secrets->resolve(NotificationSecrets::SMTP_PASSWORD, $password);
            } catch (SecretUnreadable $e) {
                return MailTestResult::failed(MailTestResult::SIGN_IN, $e->variable === null
                    ? $this->translator->trans('delivery.email.password_state.unreadable')
                    : $this->translator->trans('delivery.email.password_state.unset', ['variable' => $e->variable]));
            }
        }

        $email = (new Email())
            ->from($server->from())
            ->to(new Address($to, $admin->displayName))
            ->subject($this->translator->trans('delivery.email.test.subject'))
            ->text($this->translator->trans('delivery.email.test.body', ['host' => $server->host]) . "\n");
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        try {
            $this->transports->build($server, $password)->send($email);
        } catch (TransportExceptionInterface $e) {
            $secrets = array_merge($password === null ? [] : [$password], $this->secrets->openAll());
            $reply = Redactor::redact($e->getMessage(), $secrets);
            $this->logger->warning('Test email from Settings → Delivery failed: {error}', ['error' => $reply]);

            return MailTestResult::failed(MailTestResult::stageOf($e->getMessage(), $server->host), $reply);
        }
        $this->logger->info('Test email sent from Settings → Delivery by user {user}.', ['user' => $admin->id]);

        return MailTestResult::sent();
    }
}
