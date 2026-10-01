<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;

/**
 * A tool call's arguments as the model sent them, read defensively: small
 * models send ids as strings, a single id where a list is asked for, or
 * empty strings for "not given". Anything unusable is a ToolError the
 * model can act on.
 */
final readonly class ToolArguments
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public array $values)
    {
    }

    public function has(string $key): bool
    {
        $value = $this->values[$key] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    public function string(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->values[$key];
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new ToolError(sprintf('"%s" must be a string.', $key));
    }

    public function int(string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->values[$key];
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new ToolError(sprintf('"%s" must be a whole number.', $key));
        }
        if ($min !== null && $value < $min) {
            return $min;
        }

        return $max !== null && $value > $max ? $max : $value;
    }

    /**
     * A list of ids; a single id is taken as a list of one.
     *
     * @return list<int>|null null when not given
     */
    public function ints(string $key): ?array
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->values[$key];
        $items = is_array($value) ? array_values($value) : [$value];
        $ids = [];
        foreach ($items as $index => $item) {
            $ids[] = (new self(['id' => $item]))->int('id')
                ?? throw new ToolError(sprintf('"%s[%d]" must be a vehicle id.', $key, $index));
        }

        return array_values(array_unique($ids));
    }

    public function date(string $key): ?DateTimeImmutable
    {
        $value = $this->string($key);
        if ($value === null) {
            return null;
        }

        return LocalTime::parseDate($value)
            ?? throw new ToolError(sprintf('"%s" must be a date as YYYY-MM-DD.', $key));
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, array $allowed): ?string
    {
        $value = $this->string($key);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (!in_array($value, $allowed, true)) {
            throw new ToolError(sprintf('"%s" must be one of: %s.', $key, implode(', ', $allowed)));
        }

        return $value;
    }
}
