<?php

declare(strict_types=1);

namespace Logbook\Domain\Webhook;

/**
 * What an entry webhook can be told about (spec.md §7.20 *Webhooks*).
 */
enum WebhookEvent: string
{
    case EntryCreated = 'entry.created';
    case EntryUpdated = 'entry.updated';
    case EntryDeleted = 'entry.deleted';
    /** A reminder's status changed, or a manual reminder was made, edited or deleted (#301). */
    case ReminderChanged = 'reminder.changed';

    /**
     * A stored `events` list ("entry.created,reminder.changed"), or null
     * for all of them.
     *
     * @return list<self>|null
     */
    public static function listFrom(?string $stored): ?array
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        $events = [];
        foreach (explode(',', $stored) as $value) {
            $event = self::tryFrom(trim($value));
            if ($event !== null && !in_array($event, $events, true)) {
                $events[] = $event;
            }
        }

        return $events === [] ? null : $events;
    }

    /**
     * What to store for a choice: null when it is every event (#271: a
     * later event reaches a webhook that took them all).
     *
     * @param list<self> $events
     */
    public static function store(array $events): ?string
    {
        $chosen = array_values(array_filter(self::cases(), static fn (self $e): bool => in_array($e, $events, true)));

        return $chosen === [] || count($chosen) === count(self::cases())
            ? null
            : implode(',', array_map(static fn (self $e): string => $e->value, $chosen));
    }
}
