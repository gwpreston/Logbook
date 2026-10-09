<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Insights;

/**
 * One observation the model found (spec.md §7.26 *AI insights*): a title,
 * a body, the tool runs it came from (by their position in the set), the
 * figures the grounding check could not match, and (Phase 42, #358) its
 * topic and the vehicles it is about.
 */
final readonly class AiInsight
{
    /**
     * @param list<int> $sources indexes into the set's tool runs
     * @param list<string> $ungrounded
     * @param list<int> $vehicles
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $sources = [],
        public array $ungrounded = [],
        public AiInsightTopic $topic = AiInsightTopic::Other,
        public array $vehicles = [],
    ) {
    }

    /**
     * Whether a figure in it matched no tool result: such an insight is
     * never shown (Phase 42, #354).
     */
    public function isGrounded(): bool
    {
        return $this->ungrounded === [];
    }

    /**
     * @param list<int> $vehicles
     */
    public function withVehicles(array $vehicles): self
    {
        return new self($this->title, $this->body, $this->sources, $this->ungrounded, $this->topic, $vehicles);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'sources' => $this->sources,
            'ungrounded' => $this->ungrounded,
            'topic' => $this->topic->value,
            'vehicles' => $this->vehicles,
        ];
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
            AiInsightTopic::read($row['topic'] ?? null),
            array_values(array_filter(is_array($row['vehicles'] ?? null) ? $row['vehicles'] : [], is_int(...))),
        );
    }
}
