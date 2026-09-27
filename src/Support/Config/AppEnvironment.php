<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;

enum AppEnvironment: string
{
    case Production = 'production';
    case Development = 'development';
    case Testing = 'testing';

    public static function fromEnv(string $value): self
    {
        return match (strtolower(trim($value))) {
            'production', 'prod' => self::Production,
            'development', 'dev', 'local' => self::Development,
            'testing', 'test' => self::Testing,
            default => throw new InvalidArgumentException(
                sprintf('Unsupported APP_ENV "%s" (expected production, development or testing).', $value),
            ),
        };
    }
}
