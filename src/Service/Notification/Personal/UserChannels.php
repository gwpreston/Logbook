<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Domain\Notification\ChannelStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Ai\Redactor;
use Logbook\Service\Ai\SecretUnreadable;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationChannel;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Service\Notification\Recipient;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A user's personal channels (spec.md §7.11 *Personal channels*): their
 * status, the usable ones for the dispatcher, and saving, switching,
 * removing and testing them. Everything is by the user and the kind, never
 * by a row id. A member's channels obey the admin's policy; an admin's do
 * not. Changes are logged by kind and field name, never by value.
 */
final readonly class UserChannels
{
    public function __construct(
        private NotificationChannelRepository $records,
        private NotificationSecrets $secrets,
        private PersonalKinds $kinds,
        private OutboundDestination $destinations,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function kinds(): PersonalKinds
    {
        return $this->kinds;
    }

    /**
     * Every saved channel of the user's, by kind, as it stands.
     *
     * @return array<string, ChannelState>
     */
    public function states(int $userId, bool $isAdmin, bool $classify = true): array
    {
        $states = [];
        foreach ($this->records->forUser($userId) as $kind => $record) {
            $sender = $this->kinds->get($kind);
            if ($sender !== null) {
                $states[$kind] = $this->state($sender, $record, !$isAdmin, $classify);
            }
        }

        return $states;
    }

    public function find(int $userId, string $kind): ?ChannelRecord
    {
        return $this->records->find($userId, $kind);
    }

    /**
     * The personal channels that can reach this recipient now: on,
     * configured and allowed.
     *
     * @return list<NotificationChannel>
     */
    public function usable(Recipient $recipient): array
    {
        $usable = [];
        // No lookup here: each send checks its destination (and a refusal is never counted),
        // so a slow resolver costs one lookup per channel and send, not three.
        foreach ($this->states($recipient->userId, $recipient->isAdmin, false) as $state) {
            if ($state->status === ChannelStatus::On && $state->settings !== null) {
                $usable[] = new BoundChannel($state->sender, $state->settings, !$recipient->isAdmin);
            }
        }

        return $usable;
    }

    /**
     * @param bool $classify resolve the host (for the policy and the badge); not needed for an admin's send
     */
    public function state(PersonalSender $sender, ChannelRecord $record, bool $restricted, bool $classify = true): ChannelState
    {
        $settings = $this->open($sender->definition(), $record);
        $url = $settings === null ? null : $sender->destination($settings);
        $destination = $url === null || !$classify ? null : $this->destinations->check($url, $restricted);

        $status = match (true) {
            $record->switchedOff() => ChannelStatus::SwitchedOff,
            !$record->enabled => ChannelStatus::Off,
            $settings === null || $url === null => ChannelStatus::NeedsSetup,
            $destination !== null && $destination->isBlocked() => ChannelStatus::Blocked,
            default => ChannelStatus::On,
        };

        return new ChannelState($sender, $record, $status, $settings, $destination);
    }

    /**
     * Save a channel from its form (already valid). A typed secret
     * replaces the saved one; *Remove* removes it; an empty field keeps it,
     * unless the host changed: a saved secret only goes to the host it was
     * saved for. Saving clears a switch-off and switches the channel on
     * unless the user had switched it off.
     *
     * @return list<string> secret fields dropped because the host changed
     */
    public function save(User $user, PersonalSender $sender, ChannelForm $form): array
    {
        $definition = $sender->definition();
        $existing = $this->records->find($user->id, $definition->key);
        $settings = $form->settings();
        $saved = $existing?->secretFields() ?? [];
        $dropped = [];

        $sameHost = $existing === null || $this->hostOf($sender, $existing->values()) === $this->hostOf($sender, $settings);
        foreach ($definition->secretFields() as $field) {
            $name = $definition->secretName($field->name);
            if (isset($form->secrets[$field->name])) {
                $this->secrets->store($user->id, $name, $form->secrets[$field->name]);
                $saved[] = $field->name;
            } elseif (in_array($field->name, $form->remove, true) || (!$sameHost && in_array($field->name, $saved, true))) {
                if (!in_array($field->name, $form->remove, true)) {
                    $dropped[] = $field->name;
                }
                $this->secrets->remove($user->id, $name);
                $saved = array_values(array_diff($saved, [$field->name]));
            }
        }
        $saved = array_values(array_unique($saved));
        if ($saved !== []) {
            $settings['_secrets'] = $saved;
        }

        $enabled = $existing === null || $existing->enabled || $existing->switchedOff();
        $this->records->save($user->id, $definition->key, $settings, $enabled, $this->clock->now());
        $this->logger->notice('Notification channel {kind} saved by user {user}: {fields}.', [
            'kind' => $definition->key,
            'user' => $user->id,
            'fields' => implode(', ', array_merge(array_keys($form->settings()), array_keys($form->secrets))),
        ]);

        return $dropped;
    }

    public function setEnabled(User $user, string $kind, bool $enabled): void
    {
        $this->records->setEnabled($user->id, $kind, $enabled, $this->clock->now());
        $this->logger->info('Notification channel {kind} switched {state} by user {user}.', [
            'kind' => $kind,
            'state' => $enabled ? 'on' : 'off',
            'user' => $user->id,
        ]);
    }

    public function remove(User $user, PersonalSender $sender): void
    {
        $definition = $sender->definition();
        foreach ($definition->secretFields() as $field) {
            $this->secrets->remove($user->id, $definition->secretName($field->name));
        }
        $this->records->delete($user->id, $definition->key);
        $this->logger->notice('Notification channel {kind} removed by user {user}.', [
            'kind' => $definition->key,
            'user' => $user->id,
        ]);
    }

    /**
     * Send one notification through this channel with the typed values,
     * unsaved (spec.md §7.11 *Send test*). An empty secret field uses the
     * saved secret, if the host is the one it was saved for.
     */
    public function test(User $user, PersonalSender $sender, ChannelForm $form, Notification $notification): DeliveryResult
    {
        $definition = $sender->definition();
        $existing = $this->records->find($user->id, $definition->key);
        $values = $form->settings();
        $secrets = $form->secrets;
        $sameHost = $existing !== null && $this->hostOf($sender, $existing->values()) === $this->hostOf($sender, $values);

        foreach ($definition->secretFields() as $field) {
            if (isset($secrets[$field->name]) || in_array($field->name, $form->remove, true)) {
                continue;
            }
            if ($sameHost && in_array($field->name, $existing->secretFields(), true)) {
                try {
                    $saved = $this->secrets->open($user->id, $definition->secretName($field->name));
                } catch (SecretUnreadable) {
                    $saved = null;
                }
                if ($saved !== null) {
                    $secrets[$field->name] = $saved;
                }
            }
            if ($field->required && !isset($secrets[$field->name])) {
                return DeliveryResult::failed($definition->key, 'missing_secret');
            }
        }

        $settings = new ChannelSettings($values, $secrets);
        $result = $sender->send($notification, Recipient::of($user), $settings, !$user->isAdmin);
        if (!$result->delivered && $result->error !== null) {
            $result = DeliveryResult::failed($result->channel, Redactor::redact($result->error, $settings->secretValues()));
        }
        $this->logger->info('Test through notification channel {kind} by user {user}: {outcome}.', [
            'kind' => $definition->key,
            'user' => $user->id,
            'outcome' => $result->delivered ? 'sent' : 'failed',
        ]);

        return $result;
    }

    /**
     * A saved channel's settings with its secrets opened, or null when
     * something it needs is missing or can't be read (*Needs setup*).
     */
    private function open(ChannelDefinition $definition, ChannelRecord $record): ?ChannelSettings
    {
        foreach ($definition->visibleFields() as $field) {
            if ($field->required && $record->value($field->name) === null) {
                return null;
            }
        }

        $secrets = [];
        $saved = $record->secretFields();
        foreach ($definition->secretFields() as $field) {
            if (!in_array($field->name, $saved, true)) {
                if ($field->required) {
                    return null;
                }
                continue;
            }
            try {
                $value = $this->secrets->open($record->userId, $definition->secretName($field->name));
            } catch (SecretUnreadable) {
                $value = null;
            }
            if ($value === null) {
                return null;
            }
            $secrets[$field->name] = $value;
        }

        return new ChannelSettings($record->values(), $secrets);
    }

    /**
     * @param array<string, scalar> $values
     */
    private function hostOf(PersonalSender $sender, array $values): ?string
    {
        // Scheme, host and port: a saved token never goes to plain http or another service on the host.
        $url = $sender->destination(new ChannelSettings($values));
        $parts = $url === null ? false : parse_url($url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }
}
