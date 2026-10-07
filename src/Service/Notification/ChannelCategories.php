<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * The categories one channel receives (spec.md §7.11 *What each channel
 * receives*): all of them until the user saves a choice, then exactly the
 * ones they ticked (every offered one ticked is still all, #268). Stored as a comma list (`due,overdue`), null for all;
 * unknown values are ignored.
 */
final readonly class ChannelCategories
{
    /**
     * @param list<NotificationCategory>|null $categories null for all
     */
    private function __construct(private ?array $categories)
    {
    }

    public static function all(): self
    {
        return new self(null);
    }

    /**
     * @param list<NotificationCategory> $categories
     */
    public static function of(array $categories): self
    {
        $chosen = [];
        // In the enum's order, once each, so the stored list is stable.
        foreach (NotificationCategory::cases() as $category) {
            if (in_array($category, $categories, true)) {
                $chosen[] = $category;
            }
        }

        return new self($chosen);
    }

    public static function fromStored(mixed $value): self
    {
        if (is_array($value)) {
            $value = implode(',', array_filter($value, is_string(...)));
        }
        if (!is_string($value)) {
            return self::all();
        }

        return self::of(array_values(array_filter(array_map(
            static fn (string $v): ?NotificationCategory => NotificationCategory::tryFrom(trim($v)),
            explode(',', $value),
        ))));
    }

    /**
     * The ticked boxes of a card's *Receives* (`receives[]`): only the
     * categories offered to this user, so a member's never holds job
     * failures. Every offered box ticked is all, so a category added later
     * reaches the channel too (Phase 37, #268).
     */
    public static function fromForm(mixed $values, bool $isAdmin): self
    {
        $values = is_array($values) ? $values : [];
        $offered = NotificationCategory::offered($isAdmin);
        $ticked = array_values(array_filter(
            $offered,
            static fn (NotificationCategory $c): bool => in_array($c->value, $values, true),
        ));

        return count($ticked) === count($offered) ? self::all() : self::of($ticked);
    }

    public function isEmpty(): bool
    {
        return $this->categories === [];
    }

    /**
     * The categories shown ticked on a card.
     *
     * @return list<string>
     */
    public function values(bool $isAdmin): array
    {
        return array_values(array_map(
            static fn (NotificationCategory $c): string => $c->value,
            array_filter(NotificationCategory::offered($isAdmin), $this->takes(...)),
        ));
    }

    public function toStored(): ?string
    {
        return $this->categories === null
            ? null
            : implode(',', array_map(static fn (NotificationCategory $c): string => $c->value, $this->categories));
    }

    /**
     * What a channel receives for a person: its own choice, or everything
     * for a channel without one (the server's webhook).
     */
    public static function forChannel(NotificationChannel $channel, NotificationPreferences $preferences): self
    {
        return $channel instanceof ReceivesCategories ? $channel->categories($preferences) : self::all();
    }

    public function isAll(): bool
    {
        return $this->categories === null;
    }

    public function takes(NotificationCategory $category): bool
    {
        return $this->categories === null || in_array($category, $this->categories, true);
    }
}
