<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\Channel\EmailChannel;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Personal\ChannelForm;
use Logbook\Service\Notification\Personal\ChannelSettings;
use Logbook\Service\Notification\Personal\FieldType;
use Logbook\Service\Notification\Personal\GotifySender;
use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Service\Notification\Personal\Verification;
use Logbook\Service\Notification\Personal\VerifiesSettings;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /settings/notifications/{kind} — save a channel (`intent=save`) or
 * send a test with what was typed, unsaved (`intent=test`; spec.md §7.11
 * *Send test*, at most 5 per user in 10 minutes). The user is the signed-in
 * one and the kind comes from the path: there is no id to change. Email's
 * card only tests (its address is the account's) and saves what it
 * receives (`intent=receives`); a personal card saves what it receives
 * with its settings (Phase 36.4, at least one).
 */
final readonly class ChannelAction
{
    public const string TEST_BUCKET = 'notification-test';
    public const int TEST_MAX = 5;
    public const int TEST_WINDOW = 600;
    /** Checks with the service on saving (#261): past this, saved unchecked. */
    public const string CHECK_BUCKET = 'notification-check';
    public const int CHECK_MAX = 10;

    public function __construct(
        private NotificationsPage $page,
        private UserChannels $channels,
        private OutboundDestination $destinations,
        private NotificationSecrets $secrets,
        private NotificationComposer $composer,
        private EmailChannel $email,
        private ReminderSettingsStore $settings,
        private RateLimiter $limiter,
        private Redirector $redirect,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $input = RequestContext::form($request);
        $testing = ($input['intent'] ?? null) === 'test';
        $kind = $args['kind'] ?? '';

        // A form without the *Receives* boxes (Telegram's "use this chat") keeps what was saved.
        $receives = isset($input['receives_shown'])
            ? ChannelCategories::fromForm($input['receives'] ?? null, $user->isAdmin)
            : null;
        $receivesError = ['key' => 'notifications.receives.error_none', 'params' => []];

        if ($kind === 'email') {
            if (($input['intent'] ?? null) === 'receives') {
                $receives ??= ChannelCategories::of([]);
                if ($receives->isEmpty()) {
                    return $this->page->render($request, $response, 'email', null, null, 422, null, $receives, [
                        'receives-email' => $receivesError,
                    ]);
                }
                $preferences = $this->settings->notificationPreferences($user->id);
                $this->settings->saveNotificationPreferences($user->id, $preferences->withEmailCategories($receives));
                RequestContext::session($request)->flash('success', 'notifications.saved', [
                    'channel' => $this->translator->trans('notifications.channel.email'),
                ]);

                return $this->redirect->to($this->redirect->urlFor('settings.notifications') . '#channel-email');
            }
            if (!$testing) {
                throw new HttpNotFoundException($request);
            }
            if (!$this->limiter->attempt(self::TEST_BUCKET, (string) $user->id, self::TEST_MAX, self::TEST_WINDOW)) {
                return $this->page->render($request, $response, 'email', null, 'notifications.test.throttled', 429);
            }
            $result = $this->email->send($this->composer->test($user), Recipient::of($user));

            return $this->page->render($request, $response, 'email', null, $result);
        }

        $sender = $this->channels->kinds()->get($kind) ?? throw new HttpNotFoundException($request);
        $form = ChannelForm::parse($sender, $input);
        $errors = $form->errors;
        $definition = $sender->definition();
        if ($form->secrets !== [] && !$this->secrets->canSeal() && !$testing) {
            foreach (array_keys($form->secrets) as $field) {
                $errors[$definition->inputName($field)] ??= ['key' => 'notifications.error.no_key', 'params' => []];
            }
        }

        // Where it would send, checked now for a member (spec.md §7.11 *Where members' channels may send*).
        $url = $errors === [] ? $sender->destination(new ChannelSettings($form->settings(), $form->secrets)) : null;
        if ($url !== null) {
            $destination = $this->destinations->check($url, !$user->isAdmin);
            if (!$destination->isAllowed()) {
                $field = 'url';
                foreach ($definition->visibleFields() as $candidate) {
                    if ($candidate->type === FieldType::Url) {
                        $field = $candidate->name;
                        break;
                    }
                }
                $errors[$definition->inputName($field)] = [
                    'key' => 'notifications.error.destination.' . $destination->refusal,
                    'params' => ['host' => $destination->host],
                ];
            }
        }

        if (!$testing && $receives?->isEmpty() === true) {
            $errors['receives-' . $kind] = $receivesError;
        }

        if ($errors !== []) {
            return $this->page->render($request, $response, $kind, $form->withErrors($errors), null, 422, null, $receives);
        }

        if ($testing) {
            if (!$this->limiter->attempt(self::TEST_BUCKET, (string) $user->id, self::TEST_MAX, self::TEST_WINDOW)) {
                $throttled = 'notifications.test.throttled';

                return $this->page->render($request, $response, $kind, $form, $throttled, 429, null, $receives);
            }
            $result = $this->channels->test($user, $sender, $form, $this->composer->test($user));

            return $this->page->render($request, $response, $kind, $form, $result, 200, null, $receives);
        }

        // The service's own check (#261): a rejected token is not saved; an unreachable service is not a reason to refuse.
        $verification = null;
        if ($sender instanceof VerifiesSettings) {
            $verification = $this->limiter->attempt(self::CHECK_BUCKET, (string) $user->id, self::CHECK_MAX, self::TEST_WINDOW)
                ? $this->channels->verify($user, $sender, $form)
                : Verification::unreachable();
        }
        if ($verification !== null && $verification->isRejected()) {
            $field = $definition->secretFields()[0]->name ?? 'token';
            $errors[$definition->inputName($field)] = ['key' => (string) $verification->words, 'params' => []];

            return $this->page->render($request, $response, $kind, $form->withErrors($errors), null, 422, null, $receives);
        }

        $dropped = $this->channels->save($user, $sender, $form);
        if ($receives !== null) {
            $this->channels->setCategories($user, $kind, $receives);
        }
        if ($kind === GotifySender::KEY) {
            // A token the upgrade could not seal (#230) is replaced by this one.
            $preferences = $this->settings->notificationPreferences($user->id);
            if ($preferences->legacyGotifyToken !== null) {
                $this->settings->saveNotificationPreferences($user->id, $preferences->withoutLegacyGotifyToken());
            }
        }
        $session = RequestContext::session($request);
        $label = $this->translator->trans($definition->label);
        if ($verification?->name !== null) {
            $session->flash('success', 'notifications.saved_as', ['channel' => $label, 'name' => $verification->name]);
        } else {
            $session->flash('success', 'notifications.saved', ['channel' => $label]);
        }
        if ($verification !== null && $verification->isUnreachable()) {
            $session->flash('warning', 'notifications.unchecked', ['channel' => $label]);
        }
        if ($dropped !== []) {
            $session->flash('warning', 'notifications.secret_dropped');
        }

        return $this->redirect->to($this->redirect->urlFor('settings.notifications') . '#channel-' . $kind);
    }
}
