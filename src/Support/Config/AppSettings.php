<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use DateTimeZone;
use InvalidArgumentException;
use Logbook\Support\Http\BasePath;
use Logbook\Support\Money\Currency;
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
        public string $currency,
        public DatabaseConfig $database,
        public string $sessionSecret,
        public bool $sessionSecure,
        public string $uploadPath,
        public int $maxUploadMb,
        public string $logPath,
        /** @var LogLevel::* */
        public string $logLevel,
        public string $cacheDir,
        /** Pre-restore safety backups and `bin/backup.php create` (spec.md §7.13). */
        public string $backupPath = '',
        /** Largest backup the restore form accepts, in megabytes. */
        public int $maxRestoreMb = 256,
        /**
         * The environment these were resolved from, for components that read
         * their own variables (notification channels; spec.md §7.11).
         */
        public Env $env = new Env([]),
        /** The REST API (spec.md §7.20); off makes every /api/v1 path a 404. */
        public bool $apiEnabled = true,
        /** @var list<string> origins a browser may call the API from (none: CORS off) */
        public array $apiCorsOrigins = [],
        /** Single sign-on (spec.md §7.9, Phase 23.1). */
        public OidcConfig $oidc = new OidcConfig(),
        /** Password sign-in (`AUTH_LOCAL_LOGIN`); setup and break-glass links work either way. */
        public bool $localLogin = true,
        /** Header sign-in behind a forward-auth proxy (spec.md §7.9, Phase 23.2). */
        public ProxyAuthConfig $proxy = new ProxyAuthConfig(),
        /** AI connections (spec.md §7.25, Phase 26.1). */
        public AiConfig $ai = new AiConfig(),
        /** The MCP server (spec.md §7.28, Phase 26.5); routed only while the API is on too. */
        public bool $mcpEnabled = true,
        /** Seconds between scheduler passes (Phase 28.1, §7.30). */
        public int $schedulerInterval = 900,
        /** Seconds a *Run now* may take (Phase 28.1). */
        public int $jobTimeLimit = 300,
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
            currency: self::currency($env->string('APP_CURRENCY', 'GBP')),
            database: $database,
            sessionSecret: $env->string('SESSION_SECRET'),
            sessionSecure: $env->bool('SESSION_SECURE', str_starts_with($url, 'https://')),
            uploadPath: self::path($env->string('UPLOAD_PATH', 'var/uploads'), $rootDir),
            maxUploadMb: $env->int('MAX_UPLOAD_MB', 10),
            logPath: self::path($env->string('LOG_PATH', 'php://stderr'), $rootDir),
            logLevel: self::logLevel($env->string('LOG_LEVEL', $isProduction ? LogLevel::INFO : LogLevel::DEBUG)),
            cacheDir: $rootDir . '/var/cache',
            backupPath: self::path($env->string('BACKUP_PATH', 'var/backups'), $rootDir),
            maxRestoreMb: $env->int('MAX_RESTORE_MB', 256),
            env: $env,
            apiEnabled: $env->bool('API_ENABLED', true),
            apiCorsOrigins: self::origins($env->string('API_CORS_ORIGINS')),
            oidc: OidcConfig::fromEnv($env),
            localLogin: $env->bool('AUTH_LOCAL_LOGIN', true),
            proxy: ProxyAuthConfig::fromEnv($env),
            ai: AiConfig::fromEnv($env),
            mcpEnabled: $env->bool('MCP_ENABLED', true),
            schedulerInterval: max(60, $env->int('SCHEDULER_INTERVAL', 900)),
            jobTimeLimit: max(30, $env->int('JOB_TIME_LIMIT', 300)),
        );
    }

    /**
     * Whether `/mcp` is routed: `MCP_ENABLED` and `API_ENABLED` both on.
     */
    public function mcpRouted(): bool
    {
        return $this->apiEnabled && $this->mcpEnabled;
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

    private static function currency(string $value): string
    {
        $code = strtoupper($value);
        if (!Currency::isSupported($code)) {
            throw new InvalidArgumentException(sprintf(
                'APP_CURRENCY "%s" is not supported (expected an ISO 4217 code such as GBP, EUR or USD).',
                $value,
            ));
        }

        return $code;
    }

    /**
     * "https://a.example, https://b.example:8123" → exact origins, without a
     * trailing slash; anything that is not an http(s) origin is refused.
     *
     * @return list<string>
     */
    private static function origins(string $value): array
    {
        $origins = [];
        foreach (explode(',', $value) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') {
                continue;
            }
            if (preg_match('~^https?://[^/\s?#]+$~i', $origin) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'API_CORS_ORIGINS entry "%s" is not an origin (expected e.g. https://dashboard.example:8123).',
                    $origin,
                ));
            }
            $origins[] = strtolower($origin);
        }

        return array_values(array_unique($origins));
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
