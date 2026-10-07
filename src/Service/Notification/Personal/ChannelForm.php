<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Service\Ai\SecretBox;
use SensitiveParameter;

/**
 * A channel card's form (spec.md §7.11 *Personal channels*), read from the
 * definition alone. Inputs are named `{kind}-{field}`, so every card on
 * the page has its own ids; a secret's *Remove* box is `{kind}-{field}-remove`.
 *
 * URLs are http or https with a host, no credentials and no fragment;
 * secrets have no spaces and are never an `env:` reference (a user's
 * secret may not read the server's environment).
 */
final readonly class ChannelForm
{
    /**
     * @param array<string, string> $values typed visible values, by field
     * @param array<string, string> $secrets typed secrets (only those typed), by field
     * @param list<string> $remove secret fields whose *Remove* box is ticked
     * @param array<string, array{key: string, params: array<string, int|string>}> $errors by input name
     */
    private function __construct(
        public ChannelDefinition $definition,
        public array $values,
        #[SensitiveParameter] public array $secrets,
        public array $remove,
        public array $errors,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(PersonalSender $sender, #[SensitiveParameter] array $input): self
    {
        $definition = $sender->definition();
        $values = [];
        $secrets = [];
        $remove = [];
        $errors = [];

        foreach ($definition->fields() as $field) {
            $name = $definition->inputName($field->name);
            $raw = $input[$name] ?? '';
            $typed = is_string($raw) ? trim($raw) : '';

            if ($field->isSecret()) {
                if (($input[$name . '-remove'] ?? null) === '1') {
                    $remove[] = $field->name;
                }
                if ($typed === '') {
                    continue;
                }
                if (SecretBox::isReference($typed)) {
                    self::addError($errors, $name, 'notifications.error.env_reference');
                } elseif (mb_strlen($typed) > $field->maxLength) {
                    self::addError($errors, $name, 'notifications.error.too_long', ['max' => $field->maxLength]);
                } elseif (preg_match('/\s/u', $typed) === 1) {
                    self::addError($errors, $name, 'notifications.error.secret_spaces');
                } else {
                    $secrets[$field->name] = $typed;
                }
                continue;
            }

            $values[$field->name] = $typed;
            if ($typed === '') {
                if ($field->required) {
                    self::addError($errors, $name, 'notifications.error.required');
                }
                continue;
            }
            if (mb_strlen($typed) > $field->maxLength) {
                self::addError($errors, $name, 'notifications.error.too_long', ['max' => $field->maxLength]);
                continue;
            }
            if ($field->type === FieldType::Url && ($problem = self::url($typed)) !== null) {
                self::addError($errors, $name, $problem);
            } elseif ($field->type === FieldType::Integer) {
                $int = filter_var($typed, FILTER_VALIDATE_INT);
                if (!is_int($int) || $int < $field->min || $int > $field->max) {
                    self::addError($errors, $name, 'notifications.error.integer', ['min' => $field->min, 'max' => $field->max]);
                }
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $typed) === 1) {
                self::addError($errors, $name, 'notifications.error.line_break');
            }
        }

        foreach ($sender->validate(array_filter($values, static fn (string $v): bool => $v !== '')) as $field => $key) {
            self::addError($errors, $definition->inputName($field), $key);
        }

        return new self($definition, $values, $secrets, $remove, $errors);
    }

    /**
     * The same form with more errors (the destination check, a missing key).
     *
     * @param array<string, array{key: string, params: array<string, int|string>}> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($this->definition, $this->values, $this->secrets, $this->remove, $errors);
    }

    /**
     * The first error for an input wins.
     *
     * @param array<string, array{key: string, params: array<string, int|string>}> $errors
     * @param array<string, int|string> $params
     */
    private static function addError(array &$errors, string $input, string $key, array $params = []): void
    {
        $errors[$input] ??= ['key' => $key, 'params' => $params];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * The visible values to store: integers as integers, an empty integer
     * as its default, empty text left out.
     *
     * @return array<string, scalar>
     */
    public function settings(): array
    {
        $settings = [];
        foreach ($this->definition->visibleFields() as $field) {
            $value = $this->values[$field->name] ?? '';
            if ($field->type === FieldType::Integer) {
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if (is_int($int)) {
                    $settings[$field->name] = $int;
                } elseif ($field->default !== null) {
                    $settings[$field->name] = $field->default;
                }
            } elseif ($value !== '') {
                $settings[$field->name] = $value;
            }
        }

        return $settings;
    }

    /**
     * The form's values by input name: what was typed (never a secret).
     *
     * @return array<string, string>
     */
    public function inputValues(): array
    {
        $out = [];
        foreach ($this->values as $field => $value) {
            $out[$this->definition->inputName($field)] = $value;
        }

        return $out;
    }

    /**
     * A saved channel's values by input name, for the card (never a secret).
     *
     * @return array<string, string>
     */
    public static function saved(ChannelDefinition $definition, ?ChannelRecord $record): array
    {
        $out = [];
        foreach ($definition->visibleFields() as $field) {
            $value = $record?->settings[$field->name] ?? $field->default;
            $out[$definition->inputName($field->name)] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * Why a URL can't be used, as a translation key; null when it can.
     */
    public static function url(string $url): ?string
    {
        if (preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return 'notifications.error.url';
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            return 'notifications.error.url';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'notifications.error.url_credentials';
        }
        if (isset($parts['fragment']) || str_contains($url, '#')) {
            return 'notifications.error.url_fragment';
        }

        return null;
    }
}
