<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

/**
 * One insight in the digest (spec.md §7.11 *The monthly briefing*),
 * already worded for the recipient: a computed one (§7.8) or an AI one
 * from their recent kept set (§7.26).
 */
final readonly class DigestInsight
{
    public const string COMPUTED = 'computed';
    public const string AI = 'ai';

    /**
     * @param list<int> $vehicleIds
     */
    public function __construct(
        /** The computed insight's kind, or the AI insight's topic. */
        public string $kind,
        /** computed | ai */
        public string $source,
        public array $vehicleIds,
        public string $title,
        public string $body,
    ) {
    }

    /**
     * @return array{kind: string, source: string, vehicle_ids: list<int>, title: string, body: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'source' => $this->source,
            'vehicle_ids' => $this->vehicleIds,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
