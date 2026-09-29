<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

/**
 * What the Fuel tab's trend card plots (spec.md §7.3): economy (the
 * default) or fuel cost per distance, chosen with `?trend=`.
 */
enum TrendMode: string
{
    case Economy = 'economy';
    case Cost = 'cost';

    /**
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $value = $query['trend'] ?? null;

        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Economy;
    }

    /**
     * The query that selects it: none for the default, so plain links stay plain.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this === self::Economy ? [] : ['trend' => $this->value];
    }
}
