<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Firebase\JWT\JWT;
use Logbook\Support\Log\LogThrottle;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;

/**
 * An app with header sign-in configured (spec.md §7.9), its log captured
 * and its throttle in a fresh directory, behind a "proxy" at 10.0.0.5.
 * For AppTestCase subclasses.
 */
trait HeaderSignIn
{
    protected const string PROXY_ADDRESS = '10.0.0.5';
    protected const string JWT_SECRET = 'a-proxy-provider-client-secret-of-128-characters-or-so-0123456789';
    protected const string JWT_ISSUER = 'https://auth.example.com/application/o/logbook/';
    protected const string JWT_AUDIENCE = 'logbook-proxy';

    /** @var list<string> */
    private array $throttleDirs = [];

    /**
     * @param array<string, string> $env more AUTH_PROXY_* (or other) variables
     * @return array{App<ContainerInterface>, TestHandler, MutableClock}
     */
    protected function proxyApp(array $env = []): array
    {
        $app = $this->createApp($env + [
            'AUTH_PROXY_HEADER' => 'Remote-User',
            'AUTH_PROXY_TRUSTED' => '10.0.0.0/24, fd00:1::/64, 198.51.100.7',
        ]);
        $clock = $this->pinClock($app, '2026-10-01T09:00:00Z');
        $container = $app->getContainer();
        Assert::assertInstanceOf(Container::class, $container);
        $log = new TestHandler();
        $container->set(LoggerInterface::class, new Logger('test', [$log], [new PsrLogMessageProcessor()]));
        $dir = sys_get_temp_dir() . '/logbook-throttle-' . bin2hex(random_bytes(4));
        $this->throttleDirs[] = $dir;
        $container->set(LogThrottle::class, new LogThrottle($dir, $clock));
        $this->resetDatabase($app);

        return [$app, $log, $clock];
    }

    /**
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, TestHandler, MutableClock}
     */
    protected function jwtApp(array $env = []): array
    {
        return $this->proxyApp($env + [
            'AUTH_PROXY_HEADER' => '',
            'AUTH_PROXY_TRUSTED' => '',
            'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
            'AUTH_PROXY_JWT_SECRET' => self::JWT_SECRET,
            'AUTH_PROXY_JWT_ISSUER' => self::JWT_ISSUER,
            'AUTH_PROXY_JWT_AUDIENCE' => self::JWT_AUDIENCE,
        ]);
    }

    /**
     * A browser behind the proxy: connecting from it, sending these headers.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, string> $headers
     */
    protected function viaProxy(App $app, array $headers, string $address = self::PROXY_ADDRESS): TestBrowser
    {
        return (new TestBrowser($app))->from($address)->sending($headers);
    }

    /**
     * An X-authentik-jwt as an Authentik proxy provider signs it.
     *
     * @param array<string, mixed> $claims overrides
     */
    protected static function proxyJwt(
        ClockInterface $clock,
        array $claims = [],
        string $secret = self::JWT_SECRET,
        string $alg = 'HS256',
    ): string {
        $now = $clock->now()->getTimestamp();

        return JWT::encode($claims + [
            'iss' => self::JWT_ISSUER,
            'sub' => 'c0ffee-owner',
            'aud' => self::JWT_AUDIENCE,
            'exp' => $now + 86400,
            'iat' => $now,
            'auth_time' => $now,
            'preferred_username' => 'owner',
            'name' => 'Pat Owner',
            'email' => 'pat@example.com',
            'groups' => ['logbook'],
        ], $secret, $alg);
    }

    /**
     * @return list<string>
     */
    protected static function logLines(TestHandler $log, string $containing): array
    {
        $lines = [];
        foreach ($log->getRecords() as $record) {
            if (str_contains($record->message, $containing)) {
                $lines[] = $record->message;
            }
        }

        return $lines;
    }

    protected function removeThrottleDirs(): void
    {
        foreach ($this->throttleDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->throttleDirs = [];
    }
}
