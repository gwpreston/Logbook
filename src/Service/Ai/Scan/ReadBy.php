<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * How a scanned file was read, for the prefilled form's notice (spec.md
 * §7.27 *How it was read*, Phase 53.2): by a model alone, or by OCR first
 * ($engine `tesseract` or `paddleocr`) and then a model. From Phase 53.4,
 * by rules without AI ($rules; $engine when OCR read it, none for a text
 * PDF), with no model or connection. Names are kept as they were when it
 * was read.
 */
final readonly class ReadBy
{
    private const array ENGINES = ['tesseract', 'paddleocr'];

    public function __construct(
        public string $model,
        public string $connection,
        public ?string $engine = null,
        public bool $rules = false,
    ) {
    }

    /**
     * Read without AI on this server (Phase 53.4).
     */
    public static function rules(?string $engine): self
    {
        return new self('', '', $engine, true);
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
        $array = $this->rules ? ['rules' => '1'] : ['model' => $this->model, 'connection' => $this->connection];

        return $this->engine === null ? $array : $array + ['engine' => $this->engine];
    }

    /**
     * @param array<mixed>|null $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        $engine = $stored['engine'] ?? null;
        $engine = in_array($engine, self::ENGINES, true) ? $engine : null;
        if (($stored['rules'] ?? null) === '1') {
            return self::rules($engine);
        }
        $model = $stored['model'] ?? null;
        $connection = $stored['connection'] ?? null;
        if (!is_string($model) || !is_string($connection)) {
            return null;
        }

        return new self($model, $connection, $engine);
    }
}
