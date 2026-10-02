<?php

declare(strict_types=1);

use Logbook\Service\Incident\IncidentTwigExtension;
use Logbook\Service\Finance\FinanceTwigExtension;
use Doctrine\DBAL\Connection;
use Logbook\Service\Ai\Ask\AskTwigExtension;
use Logbook\Service\Ai\Scan\PdfRenderer;
use Logbook\Service\Ai\Scan\PdfRenderers;
use Logbook\Service\Ai\Scan\ScanTwigExtension;
use Logbook\Service\Ai\Ask\Tool;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Access\AccessTwigExtension;
use Logbook\Service\Access\AdminInstanceAccess;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Access\SharedVehicleAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Auth\Oidc\OidcCache;
use Logbook\Service\Feature\FeatureTwigExtension;
use Logbook\Service\Mcp\McpToolbox;
use Logbook\Service\Navigation\SidebarTwigExtension;
use Logbook\Service\Notification\Channel\EmailChannel;
use Logbook\Service\Notification\Channel\EmailConfig;
use Logbook\Service\Notification\Channel\GotifyChannel;
use Logbook\Service\Notification\Channel\NtfyChannel;
use Logbook\Service\Notification\Channel\WebhookChannel;
use Logbook\Service\Notification\ChannelRegistry;
use Logbook\Support\Clock\UtcClock;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\OidcConfig;
use Logbook\Support\Config\ProxyAuthConfig;
use Logbook\Support\Log\LogThrottle;
use Logbook\Support\Net\HostResolver;
use Logbook\Support\Net\SystemHostResolver;
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
use Logbook\Service\Jobs\BackupJob;
use Logbook\Service\Jobs\CleanupJob;
use Logbook\Service\Jobs\DigestJob;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobsTwigExtension;
use Logbook\Service\Jobs\RemindersJob;
use Logbook\Service\Jobs\RunCapture;
use Logbook\Kernel;
use Logbook\Service\Updates\UpdateCheckJob;
use Logbook\Support\Version\InstalledVersion;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Transport\TransportInterface as MailTransport;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\autowire;
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

    // Who may do what (spec.md §5 Access policy): owners, shares and admins (Phase 19).
    VehicleAccess::class => get(SharedVehicleAccess::class),
    InstanceAccess::class => get(AdminInstanceAccess::class),
    // Reading files (spec.md §7.27): Ghostscript, else Imagick, else none.
    PdfRenderer::class => get(PdfRenderers::class),

    LoggerInterface::class => static function (ContainerInterface $c) use ($settingsOf): LoggerInterface {
        $config = $settingsOf($c);

        $handler = new StreamHandler($config->logPath, Level::fromName($config->logLevel));
        $formatter = new LineFormatter(null, 'Y-m-d\TH:i:s.uP', true, true);
        $formatter->includeStacktraces($config->debug);
        $handler->setFormatter($formatter);

        // A job run's output takes a copy of every line logged while it runs (Phase 28.1).
        $capture = $c->get(RunCapture::class);
        assert($capture instanceof RunCapture);

        return new Logger('logbook', [$handler, $capture], [new PsrLogMessageProcessor()], new DateTimeZone('UTC'));
    },

    // Background jobs, in the order a scheduler pass runs them (spec.md §5 *Jobs*).
    JobRegistry::class => static fn (ContainerInterface $c): JobRegistry => new JobRegistry(array_map(
        static function (string $class) use ($c): Job {
            $job = $c->get($class);
            assert($job instanceof Job);

            return $job;
        },
        [
            RemindersJob::class,
            DigestJob::class,
            CleanupJob::class,
            BackupJob::class,
            // `UPDATE_CHECK_ALLOWED=false` leaves the job out entirely (spec.md §7.31).
            ...($settingsOf($c)->updateCheckAllowed ? [UpdateCheckJob::class] : []),
        ],
    )),
    InstalledVersion::class => static fn (): InstalledVersion => new InstalledVersion(Kernel::version()),

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
        $clock = $c->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return new TwigExtension($routeParser, $assets, $translator, $formatter, $display, $clock, $settingsOf($c)->basePath);
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
        $features = $c->get(FeatureTwigExtension::class);
        assert($features instanceof FeatureTwigExtension);
        $twig->addExtension($features);
        $sidebar = $c->get(SidebarTwigExtension::class);
        assert($sidebar instanceof SidebarTwigExtension);
        $twig->addExtension($sidebar);
        $access = $c->get(AccessTwigExtension::class);
        assert($access instanceof AccessTwigExtension);
        $twig->addExtension($access);
        $jobs = $c->get(JobsTwigExtension::class);
        assert($jobs instanceof JobsTwigExtension);
        $twig->addExtension($jobs);
        $ask = $c->get(AskTwigExtension::class);
        assert($ask instanceof AskTwigExtension);
        $twig->addExtension($ask);
        $scan = $c->get(ScanTwigExtension::class);
        assert($scan instanceof ScanTwigExtension);
        $twig->addExtension($scan);
        $incidents = $c->get(IncidentTwigExtension::class);
        assert($incidents instanceof IncidentTwigExtension);
        $twig->addExtension($incidents);
        $finance = $c->get(FinanceTwigExtension::class);
        assert($finance instanceof FinanceTwigExtension);
        $twig->addExtension($finance);

        return $twig;
    },

    /*
     * Notification channels (spec.md §7.11). The registry — and so the
     * dispatcher — knows only this list. To add a channel, implement
     * NotificationChannel and append it here (another definitions file can
     * use DI\add() instead); see docs/notification-channels.md.
     */
    'notification.channels' => [
        get(EmailChannel::class),
        get(NtfyChannel::class),
        get(GotifyChannel::class),
        get(WebhookChannel::class),
    ],
    ChannelRegistry::class => autowire()->constructorParameter('channels', get('notification.channels')),

    // PHP drops files past max_file_uploads silently, so the attachment limit
    // is kept at or under it (spec.md §7.12). A system-level ini setting.
    AttachmentService::class => autowire()->constructorParameter(
        'phpMaxFileUploads',
        (int) (ini_get('max_file_uploads') === false ? 20 : ini_get('max_file_uploads')),
    ),

    // Ask Logbook's read-only tools (spec.md §7.26, Phase 26.2), in the order offered.
    ToolRegistry::class => autowire()->constructorParameter('tools', [
        get(Tool\FindVehicles::class),
        get(Tool\Costs::class),
        get(Tool\CostPerDistance::class),
        get(Tool\Maintenance::class),
        get(Tool\VehicleSummary::class),
        get(Tool\FuelStats::class),
        get(Tool\LastDone::class),
        get(Tool\Mileage::class),
        get(Tool\Ownership::class),
        get(Tool\ComingUpTool::class),
        get(Tool\Documents::class),
        get(Tool\Tyres::class),
        get(Tool\TripsSummary::class),
        get(Tool\Incidents::class),
        get(Tool\NeedsAttention::class),
        // Drafting entries (Phase 26.3): validated cards for the user's Add, never a write.
        get(Tool\Draft\DraftFillUp::class),
        get(Tool\Draft\DraftReading::class),
        get(Tool\Draft\DraftServiceRecord::class),
        get(Tool\Draft\DraftDocument::class),
        get(Tool\Draft\DraftExpense::class),
        get(Tool\Draft\DraftTyreCheck::class),
        get(Tool\Draft\DraftReminder::class),
        get(Tool\Draft\DraftIncident::class),
    ]),

    // The MCP server (spec.md §7.28, Phase 26.5): the read tools come from the
    // registry; the draft tools back log_fill_up, add_reading and the drafts.
    McpToolbox::class => autowire()->constructorParameter('draftTools', [
        get(Tool\Draft\DraftFillUp::class),
        get(Tool\Draft\DraftReading::class),
        get(Tool\Draft\DraftServiceRecord::class),
        get(Tool\Draft\DraftDocument::class),
        get(Tool\Draft\DraftExpense::class),
        get(Tool\Draft\DraftTyreCheck::class),
        get(Tool\Draft\DraftReminder::class),
        get(Tool\Draft\DraftIncident::class),
    ]),

    // Single sign-on (spec.md §7.9, Phase 23.1).
    OidcConfig::class => static fn (ContainerInterface $c): OidcConfig => $settingsOf($c)->oidc,
    OidcCache::class => static fn (ContainerInterface $c): OidcCache => new OidcCache($settingsOf($c)->cacheDir . '/oidc'),
    // Header sign-in (spec.md §7.9, Phase 23.2).
    ProxyAuthConfig::class => static fn (ContainerInterface $c): ProxyAuthConfig => $settingsOf($c)->proxy,
    LogThrottle::class => static function (ContainerInterface $c) use ($settingsOf): LogThrottle {
        $clock = $c->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return new LogThrottle($settingsOf($c)->cacheDir . '/log-throttle', $clock);
    },

    // AI hosts are classed by what they resolve to (spec.md §7.25).
    HostResolver::class => get(SystemHostResolver::class),

    HttpClientInterface::class => static fn (): HttpClientInterface => HttpClient::create([
        'timeout' => 15,
        'max_redirects' => 3,
        'headers' => ['User-Agent' => 'Logbook'],
    ]),
    MailTransport::class => static function (ContainerInterface $c) use ($settingsOf): MailTransport {
        $logger = $c->get(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);

        return EmailConfig::fromEnv($settingsOf($c)->env)->createTransport($logger);
    },
];
