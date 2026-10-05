<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Insights;

/**
 * One observation the model found (spec.md §7.26 *AI insights*): a title,
 * a body, the tool runs it came from (by their position in the set) and
 * the figures the grounding check could not match.
 */
final readonly class AiInsight
{
    /**
     * @param list<int> $sources indexes into the set's tool runs
     * @param list<string> $ungrounded
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $sources = [],
        public array $ungrounded = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'sources' => $this->sources, 'ungrounded' => $this->ungrounded];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['title'] ?? null) || !is_string($row['body'] ?? null)) {
            return null;
        }

        return new self(
            $row['title'],
            $row['body'],
            array_values(array_filter(is_array($row['sources'] ?? null) ? $row['sources'] : [], is_int(...))),
            array_values(array_filter(is_array($row['ungrounded'] ?? null) ? $row['ungrounded'] : [], is_string(...))),
        );
    }
}
