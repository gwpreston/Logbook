<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * How a scanned file was read, for the prefilled form's notice (spec.md
 * §7.27 *How it was read*, Phase 53.2): by a model alone, or by OCR first
 * ($engine `tesseract` or `paddleocr`) and then a model. Names are kept as
 * they were when it was read.
 */
final readonly class ReadBy
{
    public function __construct(
        public string $model,
        public string $connection,
        public ?string $engine = null,
    ) {
    }

    public function viaOcr(): bool
    {
        return $this->engine !== null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $array = ['model' => $this->model, 'connection' => $this->connection];

        return $this->engine === null ? $array : $array + ['engine' => $this->engine];
    }

    /**
     * @param array<mixed>|null $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        $model = $stored['model'] ?? null;
        $connection = $stored['connection'] ?? null;
        if (!is_string($model) || !is_string($connection)) {
            return null;
        }
        $engine = $stored['engine'] ?? null;

        return new self($model, $connection, in_array($engine, ['tesseract', 'paddleocr'], true) ? $engine : null);
    }
}
