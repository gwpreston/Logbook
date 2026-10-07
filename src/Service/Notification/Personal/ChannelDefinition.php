<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use InvalidArgumentException;

/**
 * What one kind of personal channel is (spec.md §7.11 *Definitions*): the
 * page's card, the form's validation and the registry read only this, so a
 * new kind is a definition and a sender (docs/notification-channels.md).
 */
final readonly class ChannelDefinition
{
    /** @var array<string, ChannelField> */
    private array $fields;

    /**
     * @param string $label a translation key or a product name ("ntfy")
     * @param string $hint translation key: one line under the title
     * @param list<ChannelField> $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $hint,
        array $fields,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Channel kind "%s" is invalid.', $key));
        }
        $byName = [];
        foreach ($fields as $field) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $field->name) !== 1 || isset($byName[$field->name])) {
                throw new InvalidArgumentException(sprintf('Channel field "%s.%s" is invalid or repeated.', $key, $field->name));
            }
            $byName[$field->name] = $field;
        }
        $this->fields = $byName;
    }

    /**
     * @return list<ChannelField>
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function field(string $name): ?ChannelField
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * @return list<ChannelField>
     */
    public function secretFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ChannelField $f): bool => $f->isSecret()));
    }

    /**
     * @return list<ChannelField>
     */
    public function visibleFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ChannelField $f): bool => !$f->isSecret()));
    }

    /** A form input's name: unique on the page, which has a card per kind. */
    public function inputName(string $field): string
    {
        return $this->key . '-' . $field;
    }

    /** The NotificationSecret holding a secret field. */
    public function secretName(string $field): string
    {
        return $this->key . '.' . $field;
    }
}
