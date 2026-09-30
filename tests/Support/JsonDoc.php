<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Countable;
use PHPUnit\Framework\Assert;

/**
 * A decoded JSON response, read by path: `get('errors', 'volume', 'key')`
 * is the value or null, `doc('items')` the part below as another JsonDoc,
 * `column('filled_at', 'items')` one field of every item. Typed access, so
 * tests stay clear of `mixed`.
 */
final readonly class JsonDoc implements Countable
{
    public function __construct(private mixed $data)
    {
    }

    /**
     * The value at the path, or null when there is none.
     */
    public function get(string|int ...$path): mixed
    {
        $value = $this->data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function string(string|int ...$path): string
    {
        $value = $this->get(...$path);
        Assert::assertIsString($value, implode('.', $path) . ' is not a string');

        return $value;
    }

    public function int(string|int ...$path): int
    {
        $value = $this->get(...$path);
        Assert::assertIsInt($value, implode('.', $path) . ' is not an integer');

        return $value;
    }

    /**
     * The object or list at the path.
     */
    public function doc(string|int ...$path): self
    {
        $value = $this->get(...$path);
        Assert::assertIsArray($value, implode('.', $path) . ' is not an object or a list');

        return new self($value);
    }

    public function has(string|int ...$path): bool
    {
        $value = $this->data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * One field of every item of the list at the path.
     *
     * @return list<mixed>
     */
    public function column(string $field, string|int ...$path): array
    {
        return array_values(array_map(
            static fn (mixed $item): mixed => is_array($item) ? ($item[$field] ?? null) : null,
            $this->doc(...$path)->toArray(),
        ));
    }

    /**
     * @return list<array-key>
     */
    public function keys(string|int ...$path): array
    {
        return array_keys($this->doc(...$path)->toArray());
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        Assert::assertIsArray($this->data);

        return $this->data;
    }

    public function count(): int
    {
        return count($this->toArray());
    }
}
