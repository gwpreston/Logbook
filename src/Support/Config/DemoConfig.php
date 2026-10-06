<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

/**
 * Demo mode from the environment (spec.md §9, §7.36). `DEMO_MODE` alone
 * does nothing destructive: the guard (Service\Demo\DemoMode) decides what
 * the switch means for the database it finds.
 */
final readonly class DemoConfig
{
    public const int MIN_PASSWORD = 8;
    public const int MAX_PASSWORD = 1024;
    public const int DEFAULT_RESET_HOURS = 24;
    public const int MAX_RESET_HOURS = 168;

    public function __construct(
        /** `DEMO_MODE`. */
        public bool $enabled = false,
        /** `DEMO_PASSWORD`: the demo owner's, shown on the sign-in page by design. */
        public string $password = '',
        /** `DEMO_RESET_HOURS`, 1 to 168. */
        public int $resetHours = self::DEFAULT_RESET_HOURS,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            enabled: $env->bool('DEMO_MODE', false),
            password: $env->string('DEMO_PASSWORD'),
            resetHours: min(self::MAX_RESET_HOURS, max(1, $env->int('DEMO_RESET_HOURS', self::DEFAULT_RESET_HOURS))),
        );
    }

    /** Whether `DEMO_PASSWORD` is usable (8 to 1024 characters). */
    public function passwordUsable(): bool
    {
        $length = mb_strlen($this->password);

        return $length >= self::MIN_PASSWORD && $length <= self::MAX_PASSWORD;
    }
}
