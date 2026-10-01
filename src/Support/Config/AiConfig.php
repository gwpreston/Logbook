<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

/**
 * AI settings from the environment (spec.md §9, §7.25). Everything else
 * about AI is set by an admin on Settings → AI.
 */
final readonly class AiConfig
{
    public function __construct(
        /** `AI_ENABLED`: false hides every AI page, switch and entry point and sends nothing. */
        public bool $enabled = true,
        /** `AI_LOG_CONTENT`: store prompts and answers in the usage log (debugging only). */
        public bool $logContent = false,
        /** `AI_ALLOW_INSECURE_TLS`: false forces TLS verification on every connection. */
        public bool $allowInsecureTls = true,
        /** `GHOSTSCRIPT_BINARY`: renders scanned PDFs for reading (spec.md §7.27); '' (from `off`) turns it off. */
        public string $ghostscriptBinary = 'gs',
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            enabled: $env->bool('AI_ENABLED', true),
            logContent: $env->bool('AI_LOG_CONTENT', false),
            allowInsecureTls: $env->bool('AI_ALLOW_INSECURE_TLS', true),
            ghostscriptBinary: self::ghostscript($env->string('GHOSTSCRIPT_BINARY', 'gs')),
        );
    }

    /**
     * An empty variable counts as unset (Env), so `off` (or `none`, `false`) turns Ghostscript off.
     */
    private static function ghostscript(string $value): string
    {
        return in_array(strtolower(trim($value)), ['off', 'none', 'false', '0'], true) ? '' : trim($value);
    }
}
