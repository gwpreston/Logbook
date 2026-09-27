<?php

declare(strict_types=1);

namespace Logbook;

use DI\ContainerBuilder;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Boots the application from the files in `config/`. Used by the front
 * controller, the CLI scripts, Phinx and the test suite alike.
 */
final class Kernel
{
    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Resolve settings from the process environment layered over `.env`.
     *
     * @param array<string, string> $overrides applied on top (used by tests)
     */
    public static function settings(array $overrides = []): AppSettings
    {
        $root = self::rootDir();
        $env = Env::fromSystem($root . '/.env')->with($overrides);

        /** @var callable(Env, string): AppSettings $factory */
        $factory = self::load($root . '/config/settings.php');

        return $factory($env, $root);
    }

    public static function createContainer(AppSettings $settings): ContainerInterface
    {
        // Storage and all internal date maths are UTC regardless of php.ini;
        // APP_TIMEZONE / user preferences apply only when displaying.
        date_default_timezone_set('UTC');

        /** @var array<string, mixed> $definitions */
        $definitions = self::load($settings->rootDir . '/config/dependencies.php');

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([AppSettings::class => $settings]);
        $builder->addDefinitions($definitions);

        return $builder->build();
    }

    /**
     * @return App<ContainerInterface>
     */
    public static function createApp(AppSettings $settings): App
    {
        $container = self::createContainer($settings);

        /** @var App<ContainerInterface> $app */
        $app = $container->get(App::class);

        /** @var callable(App<ContainerInterface>): void $middleware */
        $middleware = self::load($settings->rootDir . '/config/middleware.php');
        /** @var callable(App<ContainerInterface>): void $routes */
        $routes = self::load($settings->rootDir . '/config/routes.php');

        $middleware($app);
        $routes($app);

        return $app;
    }

    /**
     * Include a config file in its own scope, so variables it declares can
     * never clobber the caller's.
     */
    private static function load(string $file): mixed
    {
        return require $file;
    }
}
