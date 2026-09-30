<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Service\Api\ApiKeyService;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * API keys and a client for them (spec.md §7.20), for AppTestCase subclasses.
 */
trait ApiFixtures
{
    /**
     * @param App<ContainerInterface> $app
     */
    protected function apiKey(
        App $app,
        User $user,
        ApiScope $scope = ApiScope::ReadWrite,
        string $name = 'Home Assistant',
    ): string {
        return $this->service($app, ApiKeyService::class)->create($user, $name, $scope)->token;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function api(App $app, ?string $token, string $prefix = '/api/v1'): ApiClient
    {
        return new ApiClient($app, $token, $prefix);
    }

    /**
     * Forget the failed-key counters (one file per address under var/cache).
     */
    protected static function clearThrottle(): void
    {
        foreach (glob(Kernel::rootDir() . '/var/cache/api-throttle/*.json') ?: [] as $file) {
            unlink($file);
        }
    }
}
