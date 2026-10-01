<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * The jobs a model is assigned to (spec.md §7.25 *Tasks*). Each has one
 * connection and model; there is no fallback.
 */
enum AiTaskName: string
{
    case Ask = 'ask';
    case ReadDocument = 'read_document';
    case ReadText = 'read_text';

    public function labelKey(): string
    {
        return 'ai.task.' . $this->value;
    }

    /**
     * Whether a model with these capabilities can do the job. Reading a
     * document takes images unless the documents are text only; either
     * way it needs JSON output.
     */
    public function accepts(AiModel $model): bool
    {
        return match ($this) {
            self::Ask => $model->tools,
            self::ReadDocument, self::ReadText => $model->json,
        };
    }

    /**
     * @return list<Capability> what a model needs for the job
     */
    public function needs(): array
    {
        return match ($this) {
            self::Ask => [Capability::Tools],
            self::ReadDocument, self::ReadText => [Capability::Json],
        };
    }
}
