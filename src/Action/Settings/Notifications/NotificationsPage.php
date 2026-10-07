<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Notifications;

use DateTimeImmutable;
use Logbook\Domain\Notification\ChannelStatus;
use Logbook\Domain\User\User;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Personal\ChannelForm;
use Logbook\Service\Notification\Personal\ChannelState;
use Logbook\Service\Notification\Personal\FoundChats;
use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders Settings → Account → Notifications (spec.md §7.11 *Personal
 * channels*, §8): In-app, Email, then a card per personal kind, each from
 * its definition. Only the signed-in user's own channels are read; a
 * secret is never put back on the page, only whether one is saved.
 */
final readonly class NotificationsPage
{
    public function __construct(
        private View $view,
        private UserChannels $channels,
        private NotificationSecrets $secrets,
        private MailConfig $mail,
        private ReminderSettingsStore $settings,
    ) {
    }

    /**
     * @param string|null $kind the card the form, errors or test belong to
     * @param ChannelForm|null $form what was typed on that card (shown again, never its secrets)
     * @param DeliveryResult|string|null $test a test's result, or a translation key saying why none was sent
     * @param FoundChats|null $chats Telegram *Find my chat*'s answer, for the Telegram card
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $kind = null,
        ?ChannelForm $form = null,
        DeliveryResult|string|null $test = null,
        int $status = 200,
        ?FoundChats $chats = null,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $states = $this->channels->states($user->id, $user->isAdmin);

        $cards = [];
        foreach ($this->channels->kinds()->all() as $sender) {
            $definition = $sender->definition();
            $state = $states[$definition->key] ?? null;
            $own = $kind === $definition->key;
            $cards[] = [
                'definition' => $definition,
                'state' => $state,
                'status' => $state?->status,
                'location' => $state?->destination?->location,
                'values' => $own && $form !== null ? $form->inputValues() : ChannelForm::saved($definition, $state?->record),
                'errors' => $own && $form !== null ? $form->errors : [],
                'remove' => $own && $form !== null ? $form->remove : [],
                'secrets' => $this->secretStates($user, $state),
                'test' => $own && $test instanceof DeliveryResult ? $test : null,
                'refused' => $own && is_string($test) ? $test : null,
                'chats' => $own ? $chats : null,
            ];
        }

        $preferences = $this->settings->notificationPreferences($user->id);
        $address = $user->email ?? ($user->isAdmin ? $this->mail->adminRecipient() : null);
        $emailStatus = match (true) {
            !$this->mail->isConfigured() => ChannelStatus::NotAvailable,
            !$preferences->isEnabled('email') => ChannelStatus::Off,
            $address === null => ChannelStatus::NeedsSetup,
            default => ChannelStatus::On,
        };

        return $this->view->render($request, $response, 'settings/notifications/index.twig', [
            'cards' => $cards,
            'email' => [
                'status' => $emailStatus,
                'enabled' => $preferences->isEnabled('email'),
                'address' => $address,
                'own_address' => $user->email !== null,
                'result' => $this->emailResult($user->id),
                'test' => $kind === 'email' && $test instanceof DeliveryResult ? $test : null,
                'refused' => $kind === 'email' && is_string($test) ? $test : null,
            ],
            'can_seal' => $this->secrets->canSeal(),
            'is_admin' => $user->isAdmin,
        ], $status);
    }

    /**
     * Email's last result with its time as a date, for the card.
     *
     * @return array{status: string, at: DateTimeImmutable, error: ?string}|null
     */
    private function emailResult(int $userId): ?array
    {
        $result = $this->settings->emailResult($userId);
        $at = $result === null ? false : DateTimeImmutable::createFromFormat(DATE_ATOM, $result['at']);

        if ($result === null || $at === false) {
            return null;
        }

        return ['status' => $result['status'], 'at' => $at, 'error' => $result['error']];
    }

    /**
     * Each secret field's state, never its value: `saved`, `unreadable`
     * (expected but missing or unreadable: *Re-enter*), or null.
     *
     * @return array<string, string|null> by field
     */
    private function secretStates(User $user, ?ChannelState $state): array
    {
        if ($state === null) {
            return [];
        }
        $definition = $state->sender->definition();
        $states = [];
        foreach ($definition->secretFields() as $field) {
            if (!in_array($field->name, $state->record->secretFields(), true)) {
                $states[$field->name] = null;
                continue;
            }
            $stored = $this->secrets->state($user->id, $definition->secretName($field->name));
            $states[$field->name] = $stored !== null && $stored['state'] === 'saved' ? 'saved' : 'unreadable';
        }

        return $states;
    }
}
