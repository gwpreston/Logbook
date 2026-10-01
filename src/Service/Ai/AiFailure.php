<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\ErrorCode;
use RuntimeException;

/**
 * An AI request that did not answer, as a feature shows it (spec.md §7.25
 * *Errors*): a code with a translated message for everyone, and the
 * provider's text, already redacted, for admins.
 */
final class AiFailure extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $error,
        /** The provider's or Logbook's own explanation, redacted. Admins only. */
        public readonly string $detail = '',
        public readonly ?string $connectionName = null,
        public readonly ?string $model = null,
        public readonly ?AdapterType $adapter = null,
        public readonly ?int $timeoutSeconds = null,
        /** For an unset `env:` secret: the variable to set. */
        public readonly ?string $variable = null,
    ) {
        parent::__construct($detail === '' ? $error->value : $error->value . ': ' . $detail);
    }

    /**
     * The message's translation key; Ollama's missing model gets a pull hint.
     */
    public function messageKey(): string
    {
        if ($this->error === ErrorCode::NotFound && $this->adapter === AdapterType::Ollama) {
            return 'ai.error.not_found_ollama';
        }
        if ($this->error === ErrorCode::SecretUnreadable && $this->variable !== null) {
            return 'ai.error.secret_unset';
        }

        return $this->error->messageKey();
    }

    /**
     * @return array<string, string|int>
     */
    public function messageParameters(): array
    {
        return [
            'connection' => $this->connectionName ?? '',
            'model' => $this->model ?? '',
            'seconds' => $this->timeoutSeconds ?? 0,
            'variable' => $this->variable ?? '',
        ];
    }
}
