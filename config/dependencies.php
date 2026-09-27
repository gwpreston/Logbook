<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Logbook\Support\Clock\UtcClock;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\ConnectionFactory;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\I18n\LocaleResolver;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\View\AssetPackage;
use Logbook\Support\View\TwigExtension;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\get;

/*
 * DI definitions. Anything not listed here is autowired by PHP-DI.
 * Collaborators are always constructor-injected (CLAUDE.md §5).
 */

$settingsOf = static function (ContainerInterface $c): AppSettings {
    $settings = $c->get(AppSettings::class);
    assert($settings instanceof AppSettings);

    return $settings;
};

return [
    App::class => static function (ContainerInterface $c) use ($settingsOf): App {
        $app = AppFactory::createFromContainer($c);
        $app->setBasePath($settingsOf($c)->basePath);

        return $app;
    },

    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),
    StreamFactoryInterface::class => static fn (): StreamFactoryInterface => new StreamFactory(),

    RouteParserInterface::class => static function (ContainerInterface $c): RouteParserInterface {
        $app = $c->get(App::class);
        assert($app instanceof App);

        return $app->getRouteCollector()->getRouteParser();
    },

    ClockInterface::class => static fn (): ClockInterface => new UtcClock(),

    LoggerInterface::class => static function (ContainerInterface $c) use ($settingsOf): LoggerInterface {
        $config = $settingsOf($c);

        $handler = new StreamHandler($config->logPath, Level::fromName($config->logLevel));
        $formatter = new LineFormatter(null, 'Y-m-d\TH:i:s.uP', true, true);
        $formatter->includeStacktraces($config->debug);
        $handler->setFormatter($formatter);

        return new Logger('logbook', [$handler], [new PsrLogMessageProcessor()], new DateTimeZone('UTC'));
    },

    Connection::class => static fn (ContainerInterface $c): Connection
        => ConnectionFactory::create($settingsOf($c)->database),

    AvailableLocales::class => static fn (ContainerInterface $c): AvailableLocales
        => AvailableLocales::fromDirectory($settingsOf($c)->rootDir . '/translations'),

    LocaleResolver::class => static function (ContainerInterface $c) use ($settingsOf): LocaleResolver {
        $available = $c->get(AvailableLocales::class);
        assert($available instanceof AvailableLocales);

        return new LocaleResolver($available, $settingsOf($c)->locale);
    },

    Translator::class => static function (ContainerInterface $c) use ($settingsOf): Translator {
        $config = $settingsOf($c);
        $resolver = $c->get(LocaleResolver::class);
        assert($resolver instanceof LocaleResolver);

        return TranslatorFactory::create(
            $config->rootDir . '/translations',
            $resolver->resolve(null),
            $config->isProduction() ? $config->cacheDir . '/translations' : null,
            $config->debug,
        );
    },
    TranslatorInterface::class => get(Translator::class),
    LocaleAwareInterface::class => get(Translator::class),

    AssetPackage::class => static function (ContainerInterface $c) use ($settingsOf): AssetPackage {
        $config = $settingsOf($c);

        return new AssetPackage($config->basePath, $config->rootDir . '/public/assets');
    },

    TwigExtension::class => static function (ContainerInterface $c) use ($settingsOf): TwigExtension {
        $routeParser = $c->get(RouteParserInterface::class);
        $assets = $c->get(AssetPackage::class);
        $translator = $c->get(Translator::class);
        $formatter = $c->get(DisplayFormatter::class);
        $display = $c->get(DisplayContext::class);
        assert($routeParser instanceof RouteParserInterface);
        assert($assets instanceof AssetPackage);
        assert($translator instanceof Translator);
        assert($formatter instanceof DisplayFormatter);
        assert($display instanceof DisplayContext);

        return new TwigExtension($routeParser, $assets, $translator, $formatter, $display, $settingsOf($c)->basePath);
    },

    Environment::class => static function (ContainerInterface $c) use ($settingsOf): Environment {
        $config = $settingsOf($c);
        $extension = $c->get(TwigExtension::class);
        assert($extension instanceof TwigExtension);

        $twig = new Environment(new FilesystemLoader($config->rootDir . '/templates'), [
            'autoescape' => 'html',
            'cache' => $config->isProduction() ? $config->cacheDir . '/twig' : false,
            'auto_reload' => !$config->isProduction(),
            'strict_variables' => !$config->isProduction(),
            'debug' => $config->debug,
        ]);
        $twig->addExtension($extension);

        return $twig;
    },
];
