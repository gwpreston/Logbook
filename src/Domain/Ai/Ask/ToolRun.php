<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

/**
 * One tool call as it ran: what the model asked for and what it got
 * back, a result or an error. Stored on the answer (`ai_messages.tool_calls`)
 * and carried into follow-ups.
 */
final readonly class ToolRun
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<int> $vehicleIds vehicles the result is about, so a follow-up can drop it once one is no longer visible
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?ToolResult $result,
        public ?string $error = null,
        public array $vehicleIds = [],
    ) {
    }

    /**
     * What the model is sent back.
     */
    public function content(): string
    {
        $payload = $this->result === null
            ? ['error' => $this->error ?? 'The tool failed.']
            : $this->result->data + ($this->result->link === null ? [] : ['link' => $this->result->link]);

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'error' => $this->error,
            'vehicle_ids' => $this->vehicleIds,
            'result' => $this->result === null ? null : [
                'data' => $this->result->data,
                'source' => $this->result->source,
                'figures' => $this->result->figures,
                'link' => $this->result->link,
            ],
        ];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
            return null;
        }
        $result = is_array($row['result'] ?? null) ? $row['result'] : null;
        $data = is_array($result['data'] ?? null) ? $result['data'] : null;
        $figures = is_array($result['figures'] ?? null) ? $result['figures'] : [];

        return new self(
            id: $row['id'],
            name: $row['name'],
            arguments: self::stringKeys(is_array($row['arguments'] ?? null) ? $row['arguments'] : []),
            result: $data === null ? null : new ToolResult(
                self::stringKeys($data),
                is_string($result['source'] ?? null) ? $result['source'] : '',
                array_values(array_filter($figures, is_string(...))),
                is_string($result['link'] ?? null) ? $result['link'] : null,
            ),
            error: is_string($row['error'] ?? null) ? $row['error'] : null,
            vehicleIds: array_values(array_filter(is_array($row['vehicle_ids'] ?? null) ? $row['vehicle_ids'] : [], is_int(...))),
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
