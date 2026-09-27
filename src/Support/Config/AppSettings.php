<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use DateTimeZone;
use InvalidArgumentException;
use Logbook\Support\Http\BasePath;
use Psr\Log\LogLevel;

/**
 * Application settings, resolved once from the environment at boot.
 * See `.env.example` and spec.md §9 for the variables and their defaults.
 */
final readonly class AppSettings
{
    public function __construct(
        public string $rootDir,
        public AppEnvironment $environment,
        public bool $debug,
        public string $url,
        public string $basePath,
        public string $timezone,
        public string $locale,
        public DatabaseConfig $database,
        public string $sessionSecret,
        public bool $sessionSecure,
        public string $uploadPath,
        public int $maxUploadMb,
        public string $logPath,
        /** @var LogLevel::* */
        public string $logLevel,
        public string $cacheDir,
    ) {
    }

    public static function fromEnv(Env $env, string $rootDir): self
    {
        $environment = AppEnvironment::fromEnv($env->string('APP_ENV', AppEnvironment::Production->value));
        $isProduction = $environment === AppEnvironment::Production;
        $url = rtrim($env->string('APP_URL', 'http://localhost:8080'), '/');

        $timezone = $env->string('APP_TIMEZONE', 'UTC');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('APP_TIMEZONE "%s" is not a valid timezone identifier.', $timezone));
        }

        $database = $environment === AppEnvironment::Testing
            ? DatabaseConfig::fromEnv($env, $rootDir, 'TEST_DB_', 'var/testing.sqlite')
            : DatabaseConfig::fromEnv($env, $rootDir);

        return new self(
            rootDir: $rootDir,
            environment: $environment,
            debug: $env->bool('APP_DEBUG', !$isProduction),
            url: $url,
            basePath: BasePath::normalise($env->string('APP_BASE_PATH')),
            timezone: $timezone,
            locale: $env->string('APP_LOCALE', 'en'),
            database: $database,
            sessionSecret: $env->string('SESSION_SECRET'),
            sessionSecure: $env->bool('SESSION_SECURE', str_starts_with($url, 'https://')),
            uploadPath: self::path($env->string('UPLOAD_PATH', 'var/uploads'), $rootDir),
            maxUploadMb: $env->int('MAX_UPLOAD_MB', 10),
            logPath: self::path($env->string('LOG_PATH', 'php://stderr'), $rootDir),
            logLevel: self::logLevel($env->string('LOG_LEVEL', $isProduction ? LogLevel::INFO : LogLevel::DEBUG)),
            cacheDir: $rootDir . '/var/cache',
        );
    }

    public function isProduction(): bool
    {
        return $this->environment === AppEnvironment::Production;
    }

    /**
     * @return LogLevel::*
     */
    private static function logLevel(string $value): string
    {
        return match (strtolower($value)) {
            LogLevel::DEBUG => LogLevel::DEBUG,
            LogLevel::INFO => LogLevel::INFO,
            LogLevel::NOTICE => LogLevel::NOTICE,
            LogLevel::WARNING => LogLevel::WARNING,
            LogLevel::ERROR => LogLevel::ERROR,
            LogLevel::CRITICAL => LogLevel::CRITICAL,
            LogLevel::ALERT => LogLevel::ALERT,
            LogLevel::EMERGENCY => LogLevel::EMERGENCY,
            default => throw new InvalidArgumentException(sprintf(
                'LOG_LEVEL "%s" is invalid (expected debug, info, notice, warning, error, critical, alert or emergency).',
                $value,
            )),
        };
    }

    private static function path(string $path, string $rootDir): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_contains($path, '://')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;

        return $isAbsolute
            ? $path
            : $rootDir . '/' . $path;
    }
}
