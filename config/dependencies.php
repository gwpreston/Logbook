<?php

declare(strict_types=1);

use Logbook\Service\Ai\Insights\AiInsightsJob;
use Logbook\Service\Demo\DemoGuardedHttpClient;
use Logbook\Service\Demo\DemoInstanceAccess;
use Logbook\Service\Demo\DemoGuardedTransport;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoResetJob;
use Logbook\Service\Demo\DemoSeeder;
use Logbook\Service\Demo\SampleData;
use Logbook\Service\Demo\DemoTwigExtension;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Incident\IncidentTwigExtension;
use Logbook\Service\Vehicle\PlateTwigExtension;
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
use Logbook\Service\Mail\MailerFactory;
use Logbook\Service\Mail\SettingsTransport;
use Logbook\Service\Mail\TransportFactory;
use Logbook\Service\Mcp\McpToolbox;
use Logbook\Service\Navigation\SidebarTwigExtension;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\Channel\EmailChannel;
use Logbook\Service\Notification\Channel\WebhookChannel;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Notification\Outbound\HostBreaker;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\ChannelRegistry;
use Logbook\Service\Notification\ChannelResults;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\Personal\DiscordSender;
use Logbook\Service\Notification\Personal\GotifySender;
use Logbook\Service\Notification\Personal\MattermostSender;
use Logbook\Service\Notification\Personal\NtfySender;
use Logbook\Service\Notification\Personal\PersonalKinds;
use Logbook\Service\Notification\Personal\PushoverSender;
use Logbook\Service\Notification\Personal\SlackSender;
use Logbook\Service\Notification\Personal\TelegramSender;
use Logbook\Service\Notification\Personal\UserChannels;
use Logbook\Service\Notification\Personal\WebhookSender;
use Logbook\Service\Notification\SwitchOffNotice;
use Logbook\Support\Clock\Sleeper;
use Logbook\Support\Clock\SystemSleeper;
use Logbook\Support\Clock\UtcClock;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\OidcConfig;
use Logbook\Support\Config\ProxyAuthConfig;
use Logbook\Support\Log\LogThrottle;
use Logbook\Support\Net\HostResolver;
use Logbook\Support\Net\CachingHostResolver;
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
use Logbook\Service\Jobs\AdminNotices;
use Logbook\Service\Jobs\BackupJob;
use Logbook\Service\Jobs\CleanupJob;
use Logbook\Service\Jobs\DigestJob;
use Logbook\Service\Jobs\Job;
use Logbook\Service\FuelPrices\Demo\DemoPriceProvider;
use Logbook\Service\FuelPrices\FuelPricesJob;
use Logbook\Service\FuelPrices\FuelPricesTwigExtension;
use Logbook\Service\FuelPrices\Pause;
use Logbook\Service\FuelPrices\ProviderRegistry;
use Logbook\Service\FuelPrices\SystemPause;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
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
    Sleeper::class => static fn (): Sleeper => new SystemSleeper(),

    // Who may do what (spec.md §5 Access policy): owners, shares and admins (Phase 19).
    VehicleAccess::class => get(SharedVehicleAccess::class),
    // The demo's sample garage (spec.md §7.36).
    SampleData::class => get(DemoSeeder::class),
    InstanceAccess::class => autowire(DemoInstanceAccess::class)->constructorParameter('inner', get(AdminInstanceAccess::class)),
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
            // Never due while no price provider is enabled (spec.md §7.34).
            FuelPricesJob::class,
            // The day's AI insights for those with AI on (spec.md §7.26, Phase 33.4).
            AiInsightsJob::class,
            // Listed only while the demo is active (spec.md §7.36).
            ...($settingsOf($c)->demo->enabled ? [DemoResetJob::class] : []),
        ],
    )),
    // Live fuel price providers (Phase 30.2, spec.md §7.34); one adapter per country.
    ProviderRegistry::class => static function (ContainerInterface $c) use ($settingsOf): ProviderRegistry {
        $ukFuelFinder = $c->get(FuelFinderProvider::class);
        assert($ukFuelFinder instanceof FuelFinderProvider);
        $providers = [$ukFuelFinder];
        // Sample prices for the demo data, never in production unless it is a demo (spec.md §7.34, §7.36).
        if (!$settingsOf($c)->isProduction() || $settingsOf($c)->demo->enabled) {
            $demo = $c->get(DemoPriceProvider::class);
            assert($demo instanceof DemoPriceProvider);
            $providers[] = $demo;
        }

        return new ProviderRegistry($providers);
    },
    Pause::class => autowire(SystemPause::class),
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
        $fuelPrices = $c->get(FuelPricesTwigExtension::class);
        assert($fuelPrices instanceof FuelPricesTwigExtension);
        $twig->addExtension($fuelPrices);
        $plates = $c->get(PlateTwigExtension::class);
        assert($plates instanceof PlateTwigExtension);
        $twig->addExtension($plates);
        $demo = $c->get(DemoTwigExtension::class);
        assert($demo instanceof DemoTwigExtension);
        $twig->addExtension($demo);

        return $twig;
    },

    /*
     * Notification channels (spec.md §7.11). The server's: email and the
     * server's webhook. Personal kinds (Phase 36.2): each a definition and a
     * sender, in the order Account → Notifications shows them; to add one,
     * implement PersonalSender and append it (another definitions file can
     * use DI\add() instead); see docs/notification-channels.md.
     */
    'notification.channels' => [
        get(EmailChannel::class),
        get(WebhookChannel::class),
    ],
    'notification.personal' => [
        get(NtfySender::class),
        get(GotifySender::class),
        get(WebhookSender::class),
        // Phase 36.3: services with their own limits, mention rules and error words.
        get(TelegramSender::class),
        get(DiscordSender::class),
        get(PushoverSender::class),
        get(MattermostSender::class),
        get(SlackSender::class),
    ],
    PersonalKinds::class => autowire()->constructorParameter('senders', get('notification.personal')),
    // The demo's guard is named, not autowired: PHP-DI leaves an optional parameter at its default.
    ChannelRegistry::class => autowire()
        ->constructorParameter('channels', get('notification.channels'))
        ->constructorParameter('demo', get(DemoMode::class))
        ->constructorParameter('personal', get(UserChannels::class)),
    EmailChannel::class => autowire()->constructorParameter('secrets', get(NotificationSecrets::class)),
    // One breaker per process, armed by the job runner for a run only (#264).
    OutboundHttp::class => autowire()->constructorParameter('breaker', get(HostBreaker::class)),
    WebhookChannel::class => autowire()->constructorParameter('breaker', get(HostBreaker::class)),
    JobRunner::class => autowire()->constructorParameter('breaker', get(HostBreaker::class)),
    NotificationDispatcher::class => autowire()
        ->constructorParameter('results', get(ChannelResults::class))
        ->constructorParameter('notice', get(SwitchOffNotice::class)),
    RemindersJob::class => autowire()->constructorParameter('demo', get(DemoMode::class)),
    AdminNotices::class => autowire()->constructorParameter('demo', get(DemoMode::class)),

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
        get(Tool\TrueCostTool::class),
        get(Tool\ComingUpTool::class),
        get(Tool\Documents::class),
        get(Tool\Tyres::class),
        get(Tool\TripsSummary::class),
        get(Tool\Incidents::class),
        get(Tool\Finance::class),
        get(Tool\Stations::class),
        get(Tool\CheapestFuel::class),
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
    // Importing from another app (spec.md §7.13, Phase 31): archives open in a private folder here.
    ArchiveReader::class => static fn (ContainerInterface $c): ArchiveReader
        => new ArchiveReader($settingsOf($c)->cacheDir . '/app-import'),
    LogThrottle::class => static function (ContainerInterface $c) use ($settingsOf): LogThrottle {
        $clock = $c->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return new LogThrottle($settingsOf($c)->cacheDir . '/log-throttle', $clock);
    },

    // AI hosts are classed by what they resolve to (spec.md §7.25).
    HostResolver::class => static function (ContainerInterface $c): HostResolver {
        $system = $c->get(SystemHostResolver::class);
        assert($system instanceof SystemHostResolver);
        $clock = $c->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        // Each name asked once a minute: a slow resolver must not stall a pass (Phase 36.2).
        return new CachingHostResolver($system, $clock);
    },

    // Every outbound request and every mail goes through the demo guard: an active demo sends nothing (spec.md §7.36).
    HttpClientInterface::class => static function (ContainerInterface $c): HttpClientInterface {
        $mode = $c->get(DemoMode::class);
        assert($mode instanceof DemoMode);

        return new DemoGuardedHttpClient(HttpClient::create([
            'timeout' => 15,
            'max_redirects' => 3,
            'headers' => ['User-Agent' => 'Logbook'],
        ]), $mode);
    },
    // The email server (spec.md §7.11, Phase 36.1): Settings → Delivery, read on every send.
    TransportFactory::class => get(MailerFactory::class),
    MailTransport::class => static function (ContainerInterface $c): MailTransport {
        $transport = $c->get(SettingsTransport::class);
        assert($transport instanceof SettingsTransport);
        $mode = $c->get(DemoMode::class);
        assert($mode instanceof DemoMode);

        return new DemoGuardedTransport($transport, $mode);
    },
];
