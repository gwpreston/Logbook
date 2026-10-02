<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Support\Config\AppEnvironment;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\TestCase;

final class AppSettingsTest extends TestCase
{
    public function testProductionDefaults(): void
    {
        $settings = AppSettings::fromEnv(new Env([]), '/app');

        self::assertSame(AppEnvironment::Production, $settings->environment);
        self::assertFalse($settings->debug);
        self::assertSame('', $settings->basePath);
        self::assertSame('UTC', $settings->timezone);
        self::assertSame('en', $settings->locale);
        self::assertSame('php://stderr', $settings->logPath);
        self::assertSame('/app/var/uploads', $settings->uploadPath);
        self::assertFalse($settings->sessionSecure);
    }

    public function testDevelopmentEnablesDebugByDefault(): void
    {
        $settings = AppSettings::fromEnv(new Env(['APP_ENV' => 'development']), '/app');

        self::assertTrue($settings->debug);
        self::assertSame('debug', $settings->logLevel);
    }

    public function testBasePathIsNormalised(): void
    {
        $settings = AppSettings::fromEnv(new Env(['APP_BASE_PATH' => 'logbook/']), '/app');

        self::assertSame('/logbook', $settings->basePath);
    }

    public function testHttpsAppUrlMakesSessionCookieSecure(): void
    {
        $settings = AppSettings::fromEnv(new Env(['APP_URL' => 'https://garage.example/']), '/app');

        self::assertSame('https://garage.example', $settings->url);
        self::assertTrue($settings->sessionSecure);
    }

    public function testLogLevelIsValidatedAndCaseInsensitive(): void
    {
        self::assertSame('warning', AppSettings::fromEnv(new Env(['LOG_LEVEL' => 'WARNING']), '/app')->logLevel);

        $this->expectException(InvalidArgumentException::class);
        AppSettings::fromEnv(new Env(['LOG_LEVEL' => 'verbose']), '/app');
    }

    public function testRejectsUnknownTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AppSettings::fromEnv(new Env(['APP_TIMEZONE' => 'Mars/Olympus']), '/app');
    }

    public function testTestingEnvironmentNeverUsesTheAppDatabase(): void
    {
        $settings = AppSettings::fromEnv(new Env([
            'APP_ENV' => 'testing',
            'DB_DRIVER' => 'pgsql',
            'DB_NAME' => 'production_data',
        ]), '/app');

        self::assertSame(DatabaseDriver::Sqlite, $settings->database->driver);
        self::assertSame('/app/var/testing.sqlite', $settings->database->name);
    }

    public function testTheApiIsOnAndCorsOffByDefault(): void
    {
        $settings = AppSettings::fromEnv(new Env([]), '/app');

        self::assertTrue($settings->apiEnabled);
        self::assertSame([], $settings->apiCorsOrigins);
        self::assertFalse(AppSettings::fromEnv(new Env(['API_ENABLED' => 'false']), '/app')->apiEnabled);
    }

    public function testCorsOriginsAreExactOrigins(): void
    {
        $settings = AppSettings::fromEnv(
            new Env(['API_CORS_ORIGINS' => ' https://HA.example:8123/ ,http://grafana.lan,,https://ha.example:8123']),
            '/app',
        );

        self::assertSame(['https://ha.example:8123', 'http://grafana.lan'], $settings->apiCorsOrigins);
    }

    public function testACorsEntryThatIsNotAnOriginIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API_CORS_ORIGINS');

        AppSettings::fromEnv(new Env(['API_CORS_ORIGINS' => 'https://ha.example/lovelace']), '/app');
    }

    public function testTheUpdateCheckIsAllowedForTheUpstreamRepositoryByDefault(): void
    {
        $settings = AppSettings::fromEnv(new Env([]), '/app');

        self::assertSame('gwpreston16/Logbook', $settings->updateCheckRepo);
        self::assertTrue($settings->updateCheckAllowed);
        self::assertFalse($settings->docker);

        $fork = AppSettings::fromEnv(new Env([
            'UPDATE_CHECK_REPO' => 'someone/logbook-fork',
            'UPDATE_CHECK_ALLOWED' => 'false',
            'LOGBOOK_DOCKER' => '1',
        ]), '/app');
        self::assertSame('someone/logbook-fork', $fork->updateCheckRepo);
        self::assertFalse($fork->updateCheckAllowed);
        self::assertTrue($fork->docker);
    }

    public function testAnUpdateRepositoryThatIsNotOwnerSlashNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UPDATE_CHECK_REPO');

        AppSettings::fromEnv(new Env(['UPDATE_CHECK_REPO' => 'https://github.com/gwpreston16/Logbook']), '/app');
    }
}
