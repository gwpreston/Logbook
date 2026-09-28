<?php

declare(strict_types=1);

namespace Logbook\Support\Validation;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Validation failures keyed by form field. Messages are translation keys
 * plus ICU parameters, translated when the form is rendered.
 */
final class ValidationErrors
{
    /** @var array<string, array{key: string, params: array<string, int|string|TranslatableInterface>}> */
    private array $errors = [];

    /**
     * Record an error; only the first error per field is kept.
     *
     * @param array<string, int|string|TranslatableInterface> $params
     */
    public function add(string $field, string $key, array $params = []): void
    {
        $this->errors[$field] ??= ['key' => $key, 'params' => $params];
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, array{key: string, params: array<string, int|string|TranslatableInterface>}>
     */
    public function all(): array
    {
        return $this->errors;
    }
}
