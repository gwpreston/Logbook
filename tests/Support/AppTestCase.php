<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Doctrine\DBAL\Connection;
use Logbook\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base class for tests that boot the real application (container, middleware,
 * routes) against the TEST_DB_* database and drive it with PSR-7 requests.
 */
abstract class AppTestCase extends TestCase
{
    /**
     * @param array<string, string> $env environment overrides
     * @return App<ContainerInterface>
     */
    protected function createApp(array $env = []): App
    {
        return Kernel::createApp(Kernel::settings($env));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $headers
     */
    protected function get(App $app, string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /**
     * @template T of object
     * @param App<ContainerInterface> $app
     * @param class-string<T> $id
     * @return T
     */
    protected function service(App $app, string $id): object
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function connection(App $app): Connection
    {
        return $this->service($app, Connection::class);
    }

    protected static function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
