<?php

declare(strict_types=1);

use Logbook\Action\Backup\DownloadScheduledBackupAction;
use Logbook\Action\Notice\DismissNoticeAction;
use Logbook\Action\Scheduler\SchedulerTickAction;
use Logbook\Action\Scheduler\SchedulerUrlAction;
use Logbook\Action\Settings\Updates\UpdatesAction;
use Logbook\Action\Api\FuelPricesNearAction;
use Logbook\Action\Dashboard\CheapestFuelPlaceAction;
use Logbook\Action\Settings\FuelPrices\FuelPricesAction;
use Logbook\Action\Station\CreateStationAction;
use Logbook\Action\Station\DuplicatesAction as StationDuplicatesAction;
use Logbook\Action\Station\EditStationAction;
use Logbook\Action\Station\FavouriteStationAction;
use Logbook\Action\Station\MergeStationAction;
use Logbook\Action\Station\Prices\AddProviderStationAction;
use Logbook\Action\Station\Prices\CheapestNearAction;
use Logbook\Action\Station\Prices\LinkStationAction;
use Logbook\Action\Station\Prices\PriceAlertAction;
use Logbook\Action\Station\SearchStationsAction;
use Logbook\Action\Station\ShowStationAction;
use Logbook\Action\Station\StationsIndexAction;
use Logbook\Action\Settings\Places\PlaceDeleteAction;
use Logbook\Action\Settings\Places\PlaceFormAction;
use Logbook\Action\Settings\Places\PlacesAction;
use Logbook\Action\Settings\Jobs\JobBackupScheduleAction;
use Logbook\Action\Settings\Jobs\JobRunAction;
use Logbook\Action\Settings\Jobs\JobRunStatusAction;
use Logbook\Action\Settings\Jobs\JobsAction;
use Logbook\Action\Settings\Jobs\JobStartedAction;
use Logbook\Action\Settings\Jobs\JobTriggersAction;
use Logbook\Action\Settings\Jobs\JobUrlTokenAction;
use Logbook\Action\Settings\Jobs\RunJobAction;
use Logbook\Action\Api\ListDocumentsAction as ApiDocumentsAction;
use Logbook\Action\Api\ListExpensesAction as ApiExpensesAction;
use Logbook\Action\Api\TrueCostAction as ApiTrueCostAction;
use Logbook\Action\Api\ListFuelAction as ApiFuelAction;
use Logbook\Action\Api\ListStationsAction as ApiStationsAction;
use Logbook\Action\Api\ShowStationAction as ApiStationAction;
use Logbook\Action\Api\FinanceAction as ApiFinanceAction;
use Logbook\Action\Api\IncidentHistoryAction as ApiIncidentHistoryAction;
use Logbook\Action\Api\ListIncidentsAction as ApiIncidentsAction;
use Logbook\Action\Api\ListJourneysAction as ApiJourneysAction;
use Logbook\Action\Api\LogIncidentAction as ApiLogIncidentAction;
use Logbook\Action\Api\ListMaintenanceAction as ApiMaintenanceAction;
use Logbook\Action\Api\ListOdometerAction as ApiOdometerAction;
use Logbook\Action\Api\ListTripsAction as ApiTripsAction;
use Logbook\Action\Api\ListTyresAction as ApiTyresAction;
use Logbook\Action\Api\ListVehiclesAction as ApiVehiclesAction;
use Logbook\Action\Api\LogDocumentAction as ApiLogDocumentAction;
use Logbook\Action\Api\LogExpenseAction as ApiLogExpenseAction;
use Logbook\Action\Api\LogFuelAction as ApiLogFuelAction;
use Logbook\Action\Api\LogMaintenanceAction as ApiLogMaintenanceAction;
use Logbook\Action\Api\LogReadingAction as ApiLogReadingAction;
use Logbook\Action\Api\LogReminderAction as ApiLogReminderAction;
use Logbook\Action\Api\LogTreadCheckAction as ApiLogTreadCheckAction;
use Logbook\Action\Api\LogTripAction as ApiLogTripAction;
use Logbook\Action\Api\MeAction as ApiMeAction;
use Logbook\Action\Api\OpenApiAction;
use Logbook\Action\Api\RemindersAction as ApiRemindersAction;
use Logbook\Action\Api\ShowVehicleAction as ApiVehicleAction;
use Logbook\Action\Api\TripClaimAction as ApiTripClaimAction;
use Logbook\Action\Api\UpcomingAction as ApiUpcomingAction;
use Logbook\Action\Api\VehicleSummaryAction as ApiSummaryAction;
use Logbook\Action\Attachment\DeleteAttachmentAction;
use Logbook\Action\Attachment\ShowAttachmentAction;
use Logbook\Action\Attention\HideAttentionAction;
use Logbook\Action\Auth\ConfirmEmailAction;
use Logbook\Action\Auth\ForgotPasswordAction;
use Logbook\Action\Auth\InviteAction;
use Logbook\Action\Auth\LoginAction;
use Logbook\Action\Auth\LoginLinkAction;
use Logbook\Action\Auth\LogoutAction;
use Logbook\Action\Auth\OidcCallbackAction;
use Logbook\Action\Auth\OidcStartAction;
use Logbook\Action\Auth\ProxyLinkAction;
use Logbook\Action\Auth\SetupAction;
use Logbook\Action\Auth\WelcomeAction;
use Logbook\Action\Backup\BackupPageAction;
use Logbook\Action\Backup\ConfirmRestoreAction;
use Logbook\Action\Backup\DownloadBackupAction;
use Logbook\Action\Backup\UploadRestoreAction;
use Logbook\Action\Compliance\ComplianceListAction;
use Logbook\Action\Compliance\CreateComplianceDocumentAction;
use Logbook\Action\Compliance\DeleteComplianceDocumentAction;
use Logbook\Action\Compliance\EditComplianceDocumentAction;
use Logbook\Action\Dashboard\SaveDashboardLayoutAction;
use Logbook\Action\DeepLinkCheckAction;
use Logbook\Action\Expense\CreateExpenseAction;
use Logbook\Action\Expense\DeleteExpenseAction;
use Logbook\Action\Expense\EditExpenseAction;
use Logbook\Action\Expense\VehicleExpensesAction;
use Logbook\Action\Export\ExportModuleAction;
use Logbook\Action\Finance\CreateFinanceAction;
use Logbook\Action\Finance\DeleteFinanceAction;
use Logbook\Action\Finance\DeleteFinanceEventAction;
use Logbook\Action\Finance\EditFinanceAction;
use Logbook\Action\Finance\EndFinanceAction;
use Logbook\Action\Finance\FinanceIndexAction;
use Logbook\Action\Finance\FinancePaymentAction;
use Logbook\Action\Finance\FinanceScheduleExportAction;
use Logbook\Action\Finance\SettlementQuoteAction;
use Logbook\Action\Finance\ShowFinanceAction;
use Logbook\Action\Forecast\ComingUpAction;
use Logbook\Action\Forecast\ComingUpExportAction;
use Logbook\Action\Fuel\ConfirmEconomyAction;
use Logbook\Action\Fuel\CreateFuelEntryAction;
use Logbook\Action\Fuel\DeleteFuelEntryAction;
use Logbook\Action\Fuel\EditFuelEntryAction;
use Logbook\Action\Fuel\FuelLogAction;
use Logbook\Action\Fuel\QuickFuelAction;
use Logbook\Action\Garage\GarageAction;
use Logbook\Action\HealthAction;
use Logbook\Action\History\FleetHistoryAction;
use Logbook\Action\History\HistoryPrintAction;
use Logbook\Action\History\VehicleHistoryAction;
use Logbook\Action\HomeAction;
use Logbook\Action\Import\ImportAction;
use Logbook\Action\Import\ImportUploadAction;
use Logbook\Action\ImportApp\ImportAppAction;
use Logbook\Action\ImportApp\ImportAppUploadAction;
use Logbook\Action\Log\LogEntryAction;
use Logbook\Action\Log\LogPickVehicleAction;
use Logbook\Action\Maintenance\CreateMaintenanceEntryAction;
use Logbook\Action\Maintenance\CreateScheduleAction;
use Logbook\Action\Maintenance\DeleteMaintenanceEntryAction;
use Logbook\Action\Maintenance\DeleteScheduleAction;
use Logbook\Action\Maintenance\EditMaintenanceEntryAction;
use Logbook\Action\Maintenance\EditScheduleAction;
use Logbook\Action\Maintenance\MaintenanceLogAction;
use Logbook\Action\Mcp\EndpointAction as McpEndpointAction;
use Logbook\Action\Odometer\CreateOdometerReadingAction;
use Logbook\Action\Odometer\DeleteOdometerReadingAction;
use Logbook\Action\Odometer\EditOdometerReadingAction;
use Logbook\Action\Odometer\OdometerLogAction;
use Logbook\Action\Pwa\OfflineAction;
use Logbook\Action\Pwa\ServiceWorkerAction;
use Logbook\Action\Pwa\WebManifestAction;
use Logbook\Action\Reminder\CalendarFeedAction;
use Logbook\Action\Reminder\CreateReminderAction;
use Logbook\Action\Reminder\DeleteReminderAction;
use Logbook\Action\Reminder\EditReminderAction;
use Logbook\Action\Reminder\ReminderCalendarAction;
use Logbook\Action\Reminder\ReminderListAction;
use Logbook\Action\Reminder\ReminderStatusAction;
use Logbook\Action\Report\OwnershipExportAction;
use Logbook\Action\Report\TrueCostExportAction;
use Logbook\Action\Report\TrueCostReportAction;
use Logbook\Action\Report\OwnershipReportAction;
use Logbook\Action\Report\ReportAction;
use Logbook\Action\Report\ReportExportAction;
use Logbook\Action\SalePack\DownloadPaperworkAction;
use Logbook\Action\SalePack\ShowSalePackAction;
use Logbook\Action\Settings\AdminTransferAction;
use Logbook\Action\Ask\AskAction;
use Logbook\Action\Scan\ScanAction;
use Logbook\Action\Scan\ScanFileAction;
use Logbook\Action\Scan\ScanRemindersAction;
use Logbook\Action\Scan\ScanResultAction;
use Logbook\Action\Scan\ScanVehicleAction;
use Logbook\Action\Ask\AskFeedbackAction;
use Logbook\Action\Ask\DraftAction as AskDraftAction;
use Logbook\Action\Ask\AskPostAction;
use Logbook\Action\Ask\AskProgressAction;
use Logbook\Action\Ask\AskRetentionAction;
use Logbook\Action\Ask\AskThreadDeleteAction;
use Logbook\Action\Settings\Ai\AiAcknowledgeAction;
use Logbook\Action\Settings\Ai\AiConnectionAction;
use Logbook\Action\Settings\Ai\AiConnectionDeleteAction;
use Logbook\Action\Settings\Ai\AiConnectionFormAction;
use Logbook\Action\Settings\Ai\AiModelsAction;
use Logbook\Action\Settings\Ai\AiSettingsAction;
use Logbook\Action\Settings\Ai\AiTasksAction;
use Logbook\Action\Settings\Ai\AiTestAction;
use Logbook\Action\Settings\Ai\AiThisHostAction;
use Logbook\Action\Settings\AiUseAction;
use Logbook\Action\Settings\ApiKeysAction;
use Logbook\Action\Settings\CalendarFeedSettingsAction;
use Logbook\Action\Settings\AddUserAction;
use Logbook\Action\Settings\AvatarSettingsAction;
use Logbook\Action\Settings\ChangePasswordAction;
use Logbook\Action\Settings\ConfirmUserAction;
use Logbook\Action\Settings\DeleteUserAction;
use Logbook\Action\Settings\EmailSettingsAction;
use Logbook\Action\Settings\ModuleSettingsAction;
use Logbook\Action\Settings\OidcLinkAction;
use Logbook\Action\Settings\OidcUnlinkAction;
use Logbook\Action\Settings\ProfileAction;
use Logbook\Action\Settings\ReminderSettingsAction;
use Logbook\Action\Settings\RemoveIdentityAction;
use Logbook\Action\Settings\RevokeApiKeyAction;
use Logbook\Action\Settings\RevokeInvitationAction;
use Logbook\Action\Settings\SavePreferencesAction;
use Logbook\Action\Settings\SendTestNotificationAction;
use Logbook\Action\Settings\SetThemeAction;
use Logbook\Action\Settings\SettingsAction;
use Logbook\Action\Settings\TyreSettingsAction;
use Logbook\Action\Settings\UserAction;
use Logbook\Action\User\AvatarAction;
use Logbook\Action\Settings\UsersAction;
use Logbook\Action\Sharing\ChangeShareAction;
use Logbook\Action\Sharing\MyShareAction;
use Logbook\Action\Sharing\SharingAction;
use Logbook\Action\Sharing\TransferVehicleAction;
use Logbook\Action\Incident\ClaimsHistoryAction;
use Logbook\Action\Incident\ClaimsHistoryExportAction;
use Logbook\Action\Incident\CreateIncidentAction;
use Logbook\Action\Incident\DeleteIncidentAction;
use Logbook\Action\Incident\EditIncidentAction;
use Logbook\Action\Incident\IncidentListAction;
use Logbook\Action\Incident\LinkIncidentRecordAction;
use Logbook\Action\Incident\ShowIncidentAction;
use Logbook\Action\Insights\InsightsPageAction;
use Logbook\Action\Insights\RefreshAiInsightsAction;
use Logbook\Action\Trip\ClaimExportAction;
use Logbook\Action\Trip\ClaimReportAction;
use Logbook\Action\Trip\CreateTripAction;
use Logbook\Action\Trip\DeleteTripAction;
use Logbook\Action\Trip\EditTripAction;
use Logbook\Action\Trip\Settings\JourneyDeleteAction;
use Logbook\Action\Trip\Settings\JourneyFormAction;
use Logbook\Action\Trip\Settings\JourneyMoveAction;
use Logbook\Action\Trip\Settings\RateSetDeleteAction;
use Logbook\Action\Trip\Settings\RateSetFormAction;
use Logbook\Action\Trip\Settings\TripSettingsAction;
use Logbook\Action\Trip\TripListAction;
use Logbook\Action\Tyre\DeleteTyreAction;
use Logbook\Action\Tyre\DeleteTyreChangeAction;
use Logbook\Action\Tyre\DeleteTyreSetAction;
use Logbook\Action\Tyre\EditTyreAction;
use Logbook\Action\Tyre\EditTyreChangeAction;
use Logbook\Action\Tyre\EditTyreSetAction;
use Logbook\Action\Tyre\TyreChangeFormAction;
use Logbook\Action\Tyre\TyreListAction;
use Logbook\Action\Valuation\CreateValuationAction;
use Logbook\Action\Valuation\DeleteValuationAction;
use Logbook\Action\Valuation\EditValuationAction;
use Logbook\Action\Valuation\VehicleValuationsAction;
use Logbook\Action\Vehicle\ArchiveVehicleAction;
use Logbook\Action\Vehicle\CreateVehicleAction;
use Logbook\Action\Vehicle\DeleteVehicleAction;
use Logbook\Action\Vehicle\EditVehicleAction;
use Logbook\Action\Vehicle\FirstInspectionPromptAction;
use Logbook\Action\Vehicle\RestoreVehicleAction;
use Logbook\Action\Vehicle\ShowVehicleAction;
use Logbook\Action\Vehicle\VehiclePhotoAction;
use Logbook\Action\Vehicle\VehicleOwnershipAction;
use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Middleware\ApiAuthMiddleware;
use Logbook\Middleware\ApiErrorMiddleware;
use Logbook\Middleware\AuthGuardMiddleware;
use Logbook\Middleware\CsrfMiddleware;
use Logbook\Middleware\FeatureGateMiddleware;
use Logbook\Middleware\InstanceAccessMiddleware;
use Logbook\Middleware\ProxyAuthMiddleware;
use Logbook\Middleware\VehicleAccessMiddleware;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Config\AppSettings;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

/*
 * Route map only: path → invokable Action class. No logic here (CLAUDE.md §5).
 * Paths are relative to APP_BASE_PATH; templates build URLs with url_for().
 *
 * Every HTML route sits in a CSRF-protected group; machine endpoints such as
 * /health stay outside so they never create sessions. Group middleware runs
 * last-added first: auth guard, then CSRF, then (module groups) the feature
 * gate, so a switched-off module's pages answer 404 (spec.md §7.10). In
 * between, the access middlewares check each route's declared vehicle or
 * instance ability (spec.md §5 Access policy). Entry edit and delete routes
 * declare Log: their Actions then allow only one's own entry without Manage
 * (Action\EntryGuard). Group closures must not be
 * static: Slim binds them to the container.
 */
return static function (App $app): void {
    $container = $app->getContainer();
    assert($container instanceof ContainerInterface);
    $toggles = $container->get(FeatureToggles::class);
    assert($toggles instanceof FeatureToggles);
    $module = static fn (Feature $feature): FeatureGateMiddleware => new FeatureGateMiddleware($feature, $toggles);
    // What each /vehicles/{id} route and each install-wide page needs (spec.md §5
    // Access policy); the route inventory test checks that every route is classified.
    $ability = VehicleAccessMiddleware::ABILITY;
    $instance = InstanceAccessMiddleware::ABILITY;
    $settings = $container->get(AppSettings::class);
    assert($settings instanceof AppSettings);

    $app->get('/health', HealthAction::class)->setName('health');

    // Installable app (spec.md §7.15): served by the app so every URL in them
    // carries APP_BASE_PATH. No session: they are fetched by the browser itself.
    $app->get('/manifest.webmanifest', WebManifestAction::class)->setName('pwa.manifest');
    $app->get('/sw.js', ServiceWorkerAction::class)->setName('pwa.worker');
    $app->get('/offline', OfflineAction::class)->setName('pwa.offline');

    // Calendar apps cannot sign in: the secret token in the URL is the
    // authentication, and the feed never touches the session.
    $app->get('/calendar/{token:[0-9]+-[a-f0-9]{64}}.ics', CalendarFeedAction::class)
        ->setName('calendar.feed')
        ->add($module(Feature::Reminders));

    // *External URL* (spec.md §7.30): the token is the authentication; no
    // session. 404 while the trigger is off or for a wrong token.
    $app->map(['GET', 'POST'], '/cron/{token:[a-f0-9]{64}}', SchedulerUrlAction::class)->setName('scheduler.url');

    // REST API (spec.md §7.20): outside the session and CSRF groups; the
    // key is the only way in, and a session user is never used. Problem
    // details for every error; openapi.json needs no key. API_ENABLED=false
    // leaves every path unrouted (404). CORS is global (config/middleware.php).
    if ($settings->apiEnabled) {
        $app->group('/api/v1', function (Group $api) use ($module, $ability): void {
            $api->get('/openapi.json', OpenApiAction::class)->setName('api.openapi');

            $api->group('', function (Group $keyed) use ($module, $ability): void {
                $keyed->get('/me', ApiMeAction::class)->setName('api.me');
                $keyed->get('/vehicles', ApiVehiclesAction::class)->setName('api.vehicles');
                $keyed->get('/upcoming', ApiUpcomingAction::class)->setName('api.upcoming');
                $keyed->get('/reminders', ApiRemindersAction::class)->setName('api.reminders')
                    ->add($module(Feature::Reminders));

                $keyed->get('/vehicles/{id:[0-9]+}', ApiVehicleAction::class)->setName('api.vehicles.show')
                    ->setArgument($ability, VehicleAbility::View->value);
                $keyed->get('/vehicles/{id:[0-9]+}/summary', ApiSummaryAction::class)->setName('api.vehicles.summary')
                    ->setArgument($ability, VehicleAbility::View->value);
                $keyed->get('/vehicles/{id:[0-9]+}/odometer', ApiOdometerAction::class)->setName('api.odometer.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $keyed->post('/vehicles/{id:[0-9]+}/odometer', ApiLogReadingAction::class)->setName('api.odometer.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $keyed->get('/vehicles/{id:[0-9]+}/expenses', ApiExpensesAction::class)->setName('api.expenses.index')
                    ->setArgument($ability, VehicleAbility::ViewCosts->value);
                // True cost (Phase 32, spec.md §7.35): core, costs only.
                $keyed->get('/vehicles/{id:[0-9]+}/true-cost', ApiTrueCostAction::class)->setName('api.true_cost')
                    ->setArgument($ability, VehicleAbility::ViewCosts->value);
                // Phase 26.3: as the expense form, Log is enough to add one.
                $keyed->post('/vehicles/{id:[0-9]+}/expenses', ApiLogExpenseAction::class)->setName('api.expenses.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                // A manual reminder needs Manage, as on the Reminders page (spec.md §7.21).
                $keyed->post('/vehicles/{id:[0-9]+}/reminders', ApiLogReminderAction::class)->setName('api.reminders.create')
                    ->setArgument($ability, VehicleAbility::Manage->value)
                    ->add($module(Feature::Reminders));
                $keyed->group('', function (Group $fuel) use ($ability): void {
                    $fuel->get('/vehicles/{id:[0-9]+}/fuel', ApiFuelAction::class)->setName('api.fuel.index')
                        ->setArgument($ability, VehicleAbility::View->value);
                    $fuel->post('/vehicles/{id:[0-9]+}/fuel', ApiLogFuelAction::class)->setName('api.fuel.create')
                        ->setArgument($ability, VehicleAbility::Log->value);
                })->add($module(Feature::Fuel));
                // Stations (spec.md §7.33): read only; fill-ups link them.
                $keyed->group('', function (Group $stations): void {
                    $stations->get('/stations', ApiStationsAction::class)->setName('api.stations.index');
                    $stations->get('/stations/{station:[0-9]+}', ApiStationAction::class)->setName('api.stations.show');
                    // Live fuel prices (spec.md §7.34): 404 until a provider is enabled.
                    $stations->get('/fuel-prices/near', FuelPricesNearAction::class)->setName('api.fuel_prices.near');
                })->add($module(Feature::Stations));
                $keyed->get('/vehicles/{id:[0-9]+}/maintenance', ApiMaintenanceAction::class)->setName('api.maintenance.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Maintenance));
                $keyed->get('/vehicles/{id:[0-9]+}/documents', ApiDocumentsAction::class)->setName('api.documents.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Compliance));
                $keyed->get('/vehicles/{id:[0-9]+}/tyres', ApiTyresAction::class)->setName('api.tyres.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Tyres));
                $keyed->post('/vehicles/{id:[0-9]+}/maintenance', ApiLogMaintenanceAction::class)
                    ->setName('api.maintenance.create')
                    ->setArgument($ability, VehicleAbility::Log->value)
                    ->add($module(Feature::Maintenance));
                $keyed->post('/vehicles/{id:[0-9]+}/documents', ApiLogDocumentAction::class)->setName('api.documents.create')
                    ->setArgument($ability, VehicleAbility::Log->value)
                    ->add($module(Feature::Compliance));
                $keyed->post('/vehicles/{id:[0-9]+}/tyres/checks', ApiLogTreadCheckAction::class)
                    ->setName('api.tyres.checks.create')
                    ->setArgument($ability, VehicleAbility::Log->value)
                    ->add($module(Feature::Tyres));
                // Finance (spec.md §7.20, §7.32): read only; FinanceAction answers 404 without Manage and ViewCosts.
                $keyed->get('/vehicles/{id:[0-9]+}/finance', ApiFinanceAction::class)->setName('api.finance.show')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Finance));
                // Trips (spec.md §7.22, §7.23): the claim is the key user's own, across their vehicles.
                $keyed->group('', function (Group $trips) use ($ability): void {
                    $trips->get('/vehicles/{id:[0-9]+}/trips', ApiTripsAction::class)->setName('api.trips.index')
                        ->setArgument($ability, VehicleAbility::View->value);
                    $trips->post('/vehicles/{id:[0-9]+}/trips', ApiLogTripAction::class)->setName('api.trips.create')
                        ->setArgument($ability, VehicleAbility::Log->value);
                    $trips->get('/trips/claim', ApiTripClaimAction::class)->setName('api.trips.claim');
                    $trips->get('/journeys', ApiJourneysAction::class)->setName('api.journeys');
                })->add($module(Feature::Trips));
                // Incidents (spec.md §7.20, §7.29): the access rules of IncidentAccess.
                $keyed->group('', function (Group $incidents) use ($ability): void {
                    $incidents->get('/vehicles/{id:[0-9]+}/incidents', ApiIncidentsAction::class)
                        ->setName('api.incidents.index')
                        ->setArgument($ability, VehicleAbility::View->value);
                    $incidents->post('/vehicles/{id:[0-9]+}/incidents', ApiLogIncidentAction::class)
                        ->setName('api.incidents.create')
                        ->setArgument($ability, VehicleAbility::Log->value);
                    $incidents->get('/incidents/history', ApiIncidentHistoryAction::class)->setName('api.incidents.history');
                })->add($module(Feature::Incidents));
            })->add(VehicleAccessMiddleware::class)
                ->add(ApiAuthMiddleware::class);
        })->add(ApiErrorMiddleware::class);
    }

    // MCP server (spec.md §7.28, Phase 26.5): like the API, outside the session
    // and CSRF groups, the key the only way in; JSON-RPC errors throughout. Not
    // routed (404) unless MCP_ENABLED and API_ENABLED are both on. GET and
    // DELETE reach the action, which answers 405 as the transport asks.
    if ($settings->mcpRouted()) {
        $app->map(['GET', 'POST', 'DELETE'], '/mcp', McpEndpointAction::class)->setName('mcp');
    }

    // Signed-out pages.
    $app->group('', function (Group $group): void {
        $group->map(['GET', 'POST'], '/setup', SetupAction::class)->setName('setup');
        $group->map(['GET', 'POST'], '/login', LoginAction::class)->setName('login');
        // One-time invitation and password-reset links (spec.md §7.9): the token is the authentication.
        $group->map(['GET', 'POST'], '/invite/{token:[A-Za-z0-9_-]{43}}', InviteAction::class)->setName('invite.accept');
        // *Forgotten password* (spec.md §7.9, Phase 33.1): 404 unless email is set up and it is on.
        $group->map(['GET', 'POST'], '/forgot-password', ForgotPasswordAction::class)->setName('password.forgot');
        // Email confirmation links (spec.md §7.9 *Email addresses*): the token is the authentication.
        $group->map(['GET', 'POST'], '/confirm-email/{token:[A-Za-z0-9_-]{43}}', ConfirmEmailAction::class)
            ->setName('email.confirm');
        // Break-glass sign-in links from bin/auth.php (spec.md §7.9): the token is the authentication.
        $group->map(['GET', 'POST'], '/login/link/{token:[A-Za-z0-9_-]{43}}', LoginLinkAction::class)->setName('login.link');
        // Single sign-on (spec.md §7.9): 404 unless OIDC_ISSUER is set. The callback also ends a
        // signed-in user's *Link* flow, so it sits here, outside the auth guard.
        $group->get('/auth/oidc/start', OidcStartAction::class)->setName('oidc.start');
        $group->get('/auth/oidc/callback', OidcCallbackAction::class)->setName('oidc.callback');
        $group->get('/diagnostics/deep/link', DeepLinkCheckAction::class)->setName('diagnostics.deep-link');
    })->add(CsrfMiddleware::class)
        // Header sign-in (spec.md §7.9): page groups only, so never the API, feed or /health.
        ->add(ProxyAuthMiddleware::class);

    // Signed-in pages.
    $app->group('', function (Group $group) use ($module, $ability, $instance, $settings): void {
        $group->get('/', HomeAction::class)->setName('home');
        // Drafts an MCP client left (spec.md §7.28): a card's buttons, without Ask or
        // AI; the draft is the user's own MCP draft or not found. Routed even with
        // MCP off, so drafts made before still close.
        $group->post('/drafts/{draft:[0-9]+}/{action:add|discard|undo}', AskDraftAction::class)->setName('drafts.action');
        $group->post('/logout', LogoutAction::class)->setName('logout');
        // Once, after an account is created on first single sign-on (spec.md §7.9).
        $group->map(['GET', 'POST'], '/welcome', WelcomeAction::class)->setName('welcome');
        // *Link your proxy account* (spec.md §7.9 header sign-in): reads the header again.
        $group->post('/auth/proxy/link', ProxyLinkAction::class)->setName('proxy.link');
        $group->post('/dashboard/layout', SaveDashboardLayoutAction::class)->setName('dashboard.layout');
        // The *Cheapest fuel* widget's place (spec.md §7.34); 404 while prices are off.
        $group->post('/dashboard/cheapest-fuel', CheapestFuelPlaceAction::class)->setName('dashboard.cheapest_fuel');

        // "+ Log entry" (spec.md §7.3). The picker checks the kind's module itself.
        $group->get('/log/new', LogEntryAction::class)->setName('log.chooser');
        $kinds = 'odometer|maintenance|expense|document|schedule|tyre|tyre_check|trip|incident';
        $group->get('/log/new/{kind:' . $kinds . '}', LogPickVehicleAction::class)
            ->setName('log.pick');

        $group->get('/garage', GarageAction::class)->setName('garage');
        $group->map(['GET', 'POST'], '/vehicles/new', CreateVehicleAction::class)->setName('vehicles.create');
        $group->get('/vehicles/{id:[0-9]+}', ShowVehicleAction::class)->setName('vehicles.show')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/edit', EditVehicleAction::class)->setName('vehicles.edit')
            ->setArgument($ability, VehicleAbility::Manage->value);
        $group->post('/vehicles/{id:[0-9]+}/first-inspection', FirstInspectionPromptAction::class)
            ->setName('vehicles.first_inspection')
            ->setArgument($ability, VehicleAbility::Manage->value);
        // Needs attention (spec.md §7.24): core; the check is judged again before it is hidden.
        $group->post('/vehicles/{id:[0-9]+}/attention/hide', HideAttentionAction::class)
            ->setName('attention.hide')
            ->setArgument($ability, VehicleAbility::Log->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/delete', DeleteVehicleAction::class)->setName('vehicles.delete')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/archive', ArchiveVehicleAction::class)->setName('vehicles.archive')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->post('/vehicles/{id:[0-9]+}/restore', RestoreVehicleAction::class)->setName('vehicles.restore')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->get('/vehicles/{id:[0-9]+}/photo', VehiclePhotoAction::class)->setName('vehicles.photo')
            ->setArgument($ability, VehicleAbility::View->value);

        // Sharing (spec.md §7.21): the page for anyone on the vehicle, adding and changing shares
        // for the owner, a shared user's own choices, and transfer.
        $group->get('/vehicles/{id:[0-9]+}/sharing', SharingAction::class)->setName('vehicles.sharing')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->post('/vehicles/{id:[0-9]+}/sharing', SharingAction::class)->setName('vehicles.sharing.add')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->post('/vehicles/{id:[0-9]+}/sharing/{member:[0-9]+}/{action:save|remove}', ChangeShareAction::class)
            ->setName('vehicles.sharing.change')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->post('/vehicles/{id:[0-9]+}/sharing/me/{action:notify|leave}', MyShareAction::class)
            ->setName('vehicles.sharing.mine')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/transfer', TransferVehicleAction::class)
            ->setName('vehicles.transfer')
            ->setArgument($ability, VehicleAbility::Own->value);

        // History (spec.md §7.16): core, so no feature gate; the feed leaves
        // switched-off modules out itself.
        $group->get('/history', FleetHistoryAction::class)->setName('history.fleet');
        $group->get('/vehicles/{id:[0-9]+}/history', VehicleHistoryAction::class)->setName('history.vehicle')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->get('/vehicles/{id:[0-9]+}/history/print', HistoryPrintAction::class)->setName('history.print')
            ->setArgument($ability, VehicleAbility::View->value);

        // Sale pack (spec.md §7.19): core, for active and archived vehicles;
        // each module's parts leave it when that module is off.
        $group->get('/vehicles/{id:[0-9]+}/sale-pack', ShowSalePackAction::class)->setName('sale_pack.show')
            ->setArgument($ability, VehicleAbility::Manage->value);
        $group->get('/vehicles/{id:[0-9]+}/sale-pack/paperwork.zip', DownloadPaperworkAction::class)
            ->setName('sale_pack.paperwork')
            ->setArgument($ability, VehicleAbility::Manage->value);

        // Coming up (spec.md §7.18): core too; each module's items leave it
        // when that module is off.
        $group->get('/upcoming', ComingUpAction::class)->setName('upcoming');
        $group->get('/upcoming.csv', ComingUpExportAction::class)->setName('upcoming.export');

        $group->get('/vehicles/{id:[0-9]+}/odometer', OdometerLogAction::class)->setName('odometer.index')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/new', CreateOdometerReadingAction::class)
            ->setName('odometer.create')
            ->setArgument($ability, VehicleAbility::Log->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/edit', EditOdometerReadingAction::class)
            ->setName('odometer.edit')
            ->setArgument($ability, VehicleAbility::Log->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/delete', DeleteOdometerReadingAction::class)
            ->setName('odometer.delete')
            ->setArgument($ability, VehicleAbility::Log->value);

        $group->group('', function (Group $fuel) use ($ability): void {
            $fuel->get('/fuel/new', QuickFuelAction::class)->setName('fuel.quick');
            $fuel->get('/vehicles/{id:[0-9]+}/fuel', FuelLogAction::class)->setName('fuel.index')
                ->setArgument($ability, VehicleAbility::View->value);
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/new', CreateFuelEntryAction::class)->setName('fuel.create')
                ->setArgument($ability, VehicleAbility::Log->value);
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/edit', EditFuelEntryAction::class)
                ->setName('fuel.edit')
                ->setArgument($ability, VehicleAbility::Log->value);
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/delete', DeleteFuelEntryAction::class)
                ->setName('fuel.delete')
                ->setArgument($ability, VehicleAbility::Log->value);
            $fuel->post('/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/economy', ConfirmEconomyAction::class)
                ->setName('fuel.economy')
                ->setArgument($ability, VehicleAbility::Log->value);
            // Importing from another app (spec.md §7.13, Phase 31): Manage is checked per target vehicle.
            $fuel->map(['GET', 'POST'], '/settings/import-app', ImportAppUploadAction::class)->setName('import_app.upload');
            $fuel->map(['GET', 'POST'], '/settings/import-app/{token:[a-f0-9]{32}}', ImportAppAction::class)
                ->setName('import_app.map');
        })->add($module(Feature::Fuel));

        $group->group('/vehicles/{id:[0-9]+}', function (Group $vehicle) use ($module, $ability): void {
            $vehicle->group('', function (Group $maintenance) use ($ability): void {
                $maintenance->get('/maintenance', MaintenanceLogAction::class)->setName('maintenance.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/new', CreateMaintenanceEntryAction::class)
                    ->setName('maintenance.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/edit', EditMaintenanceEntryAction::class)
                    ->setName('maintenance.edit')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/delete', DeleteMaintenanceEntryAction::class)
                    ->setName('maintenance.delete')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/new', CreateScheduleAction::class)
                    ->setName('maintenance.schedules.create')
                    ->setArgument($ability, VehicleAbility::Manage->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/edit', EditScheduleAction::class)
                    ->setName('maintenance.schedules.edit')
                    ->setArgument($ability, VehicleAbility::Manage->value);
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/delete', DeleteScheduleAction::class)
                    ->setName('maintenance.schedules.delete')
                    ->setArgument($ability, VehicleAbility::Manage->value);
            })->add($module(Feature::Maintenance));

            // Tyres (spec.md §7.17).
            $vehicle->group('/tyres', function (Group $tyres) use ($ability): void {
                $tyres->get('', TyreListAction::class)->setName('tyres.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $tyres->map(['GET', 'POST'], '/{kind:existing|fit|swap|rotate|repair|remove|check}', TyreChangeFormAction::class)
                    ->setName('tyres.change')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $tyres->map(['GET', 'POST'], '/changes/{change:[0-9]+}/edit', EditTyreChangeAction::class)
                    ->setName('tyres.changes.edit')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $tyres->map(['GET', 'POST'], '/changes/{change:[0-9]+}/delete', DeleteTyreChangeAction::class)
                    ->setName('tyres.changes.delete')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $tyres->map(['GET', 'POST'], '/sets/{set:[0-9]+}/edit', EditTyreSetAction::class)->setName('tyres.sets.edit')
                    ->setArgument($ability, VehicleAbility::Manage->value);
                $tyres->map(['GET', 'POST'], '/sets/{set:[0-9]+}/delete', DeleteTyreSetAction::class)
                    ->setName('tyres.sets.delete')
                    ->setArgument($ability, VehicleAbility::Manage->value);
                $tyres->map(['GET', 'POST'], '/{tyre:[0-9]+}/edit', EditTyreAction::class)->setName('tyres.edit')
                    ->setArgument($ability, VehicleAbility::Manage->value);
                $tyres->map(['GET', 'POST'], '/{tyre:[0-9]+}/delete', DeleteTyreAction::class)->setName('tyres.delete')
                    ->setArgument($ability, VehicleAbility::Manage->value);
            })->add($module(Feature::Tyres));

            // Trips (spec.md §7.22). Other drivers' trips are 404 without ViewOthersTrips.
            $vehicle->group('/trips', function (Group $trips) use ($ability): void {
                $trips->get('', TripListAction::class)->setName('trips.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $trips->map(['GET', 'POST'], '/new', CreateTripAction::class)->setName('trips.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $trips->map(['GET', 'POST'], '/{entry:[0-9]+}/edit', EditTripAction::class)->setName('trips.edit')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $trips->map(['GET', 'POST'], '/{entry:[0-9]+}/delete', DeleteTripAction::class)->setName('trips.delete')
                    ->setArgument($ability, VehicleAbility::Log->value);
            })->add($module(Feature::Trips));

            // Incidents (spec.md §7.29). Editing someone else's needs Manage (EntryGuard).
            $vehicle->group('/incidents', function (Group $incidents) use ($ability): void {
                $incidents->get('', IncidentListAction::class)->setName('incidents.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $incidents->map(['GET', 'POST'], '/new', CreateIncidentAction::class)->setName('incidents.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $incidents->get('/{incident:[0-9]+}', ShowIncidentAction::class)->setName('incidents.show')
                    ->setArgument($ability, VehicleAbility::View->value);
                $incidents->map(['GET', 'POST'], '/{incident:[0-9]+}/edit', EditIncidentAction::class)
                    ->setName('incidents.edit')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $incidents->map(['GET', 'POST'], '/{incident:[0-9]+}/delete', DeleteIncidentAction::class)
                    ->setName('incidents.delete')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $incidents->post('/{incident:[0-9]+}/links', LinkIncidentRecordAction::class)
                    ->setName('incidents.links')
                    ->setArgument($ability, VehicleAbility::Log->value);
            })->add($module(Feature::Incidents));

            // Finance agreements (spec.md §7.32): Manage and ViewCosts, checked by the actions so that
            // anyone else gets 404, not 403 (§7.32 *Access*).
            $vehicle->group('/finance', function (Group $finance) use ($ability): void {
                $view = VehicleAbility::View->value;
                $agreement = '/{agreement:[0-9]+}';
                $finance->get('', FinanceIndexAction::class)->setName('finance.index')->setArgument($ability, $view);
                $finance->map(['GET', 'POST'], '/new', CreateFinanceAction::class)->setName('finance.create')
                    ->setArgument($ability, $view);
                $finance->get($agreement, ShowFinanceAction::class)->setName('finance.show')->setArgument($ability, $view);
                $finance->map(['GET', 'POST'], $agreement . '/edit', EditFinanceAction::class)->setName('finance.edit')
                    ->setArgument($ability, $view);
                $finance->map(['GET', 'POST'], $agreement . '/delete', DeleteFinanceAction::class)->setName('finance.delete')
                    ->setArgument($ability, $view);
                $finance->post($agreement . '/payments', FinancePaymentAction::class)->setName('finance.payments')
                    ->setArgument($ability, $view);
                $finance->post($agreement . '/events/{event:[0-9]+}/delete', DeleteFinanceEventAction::class)
                    ->setName('finance.events.delete')
                    ->setArgument($ability, $view);
                $finance->post($agreement . '/quotes', SettlementQuoteAction::class)->setName('finance.quotes')
                    ->setArgument($ability, $view);
                $finance->post($agreement . '/quotes/{quote:[0-9]+}/delete', SettlementQuoteAction::class)
                    ->setName('finance.quotes.delete')
                    ->setArgument($ability, $view);
                $finance->get($agreement . '/schedule.csv', FinanceScheduleExportAction::class)->setName('finance.schedule')
                    ->setArgument($ability, $view);
                $finance->map(['GET', 'POST'], $agreement . '/end', EndFinanceAction::class)->setName('finance.end')
                    ->setArgument($ability, $view);
            })->add($module(Feature::Finance));

            $vehicle->group('', function (Group $documents) use ($ability): void {
                $documents->get('/documents', ComplianceListAction::class)->setName('compliance.index')
                    ->setArgument($ability, VehicleAbility::View->value);
                $documents->map(['GET', 'POST'], '/documents/new', CreateComplianceDocumentAction::class)
                    ->setName('compliance.create')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $documents->map(['GET', 'POST'], '/documents/{document:[0-9]+}/edit', EditComplianceDocumentAction::class)
                    ->setName('compliance.edit')
                    ->setArgument($ability, VehicleAbility::Log->value);
                $documents->map(['GET', 'POST'], '/documents/{document:[0-9]+}/delete', DeleteComplianceDocumentAction::class)
                    ->setName('compliance.delete')
                    ->setArgument($ability, VehicleAbility::Log->value);
            })->add($module(Feature::Compliance));

            // Without ViewCosts the tab lists the ad-hoc expenses only (spec.md §7.21).
            $vehicle->get('/expenses', VehicleExpensesAction::class)->setName('expenses.index')
                ->setArgument($ability, VehicleAbility::View->value);
            $vehicle->map(['GET', 'POST'], '/expenses/new', CreateExpenseAction::class)->setName('expenses.create')
                ->setArgument($ability, VehicleAbility::Log->value);
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/edit', EditExpenseAction::class)
                ->setName('expenses.edit')
                ->setArgument($ability, VehicleAbility::Log->value);
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/delete', DeleteExpenseAction::class)
                ->setName('expenses.delete')
                ->setArgument($ability, VehicleAbility::Log->value);

            // Cost of ownership tab (spec.md §7.1, Phase 33.4): core, with costs only.
            $vehicle->get('/ownership', VehicleOwnershipAction::class)->setName('vehicles.ownership')
                ->setArgument($ability, VehicleAbility::ViewCosts->value);

            // Valuations (Phase 14.1) are core: no module toggle.
            $vehicle->get('/valuations', VehicleValuationsAction::class)->setName('valuations.index')
                ->setArgument($ability, VehicleAbility::ViewCosts->value);
            $vehicle->map(['GET', 'POST'], '/valuations/new', CreateValuationAction::class)->setName('valuations.create')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $vehicle->map(['GET', 'POST'], '/valuations/{entry:[0-9]+}/edit', EditValuationAction::class)
                ->setName('valuations.edit')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $vehicle->map(['GET', 'POST'], '/valuations/{entry:[0-9]+}/delete', DeleteValuationAction::class)
                ->setName('valuations.delete')
                ->setArgument($ability, VehicleAbility::Manage->value);

            // Export and import check the module's toggle themselves (one route, several modules).
            $exportModule = '{module:fuel|odometer|maintenance|documents|expenses|tyres|tyre-changes|valuations|trips|incidents'
                . '|finance}';
            $vehicle->get('/export/' . $exportModule . '.csv', ExportModuleAction::class)
                ->setName('export.module')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $csvModule = '{module:fuel|odometer|maintenance|documents|expenses|trips}';
            $vehicle->map(['GET', 'POST'], '/import/' . $csvModule, ImportUploadAction::class)
                ->setName('import.upload')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $vehicle->map(['GET', 'POST'], '/import/' . $csvModule . '/{token:[a-f0-9]{32}}', ImportAction::class)
                ->setName('import.map')
                ->setArgument($ability, VehicleAbility::Manage->value);

            $vehicle->get('/attachments/{attachment:[0-9]+}', ShowAttachmentAction::class)->setName('attachments.show')
                ->setArgument($ability, VehicleAbility::View->value);
            $vehicle->map(['GET', 'POST'], '/attachments/{attachment:[0-9]+}/delete', DeleteAttachmentAction::class)
                ->setName('attachments.delete')
                ->setArgument($ability, VehicleAbility::Log->value);
        });

        $group->group('', function (Group $reminders) use ($ability): void {
            $reminders->get('/reminders', ReminderListAction::class)->setName('reminders.index');
            $reminders->get('/reminders/calendar', ReminderCalendarAction::class)->setName('reminders.calendar');
            $reminders->map(['GET', 'POST'], '/reminders/new', CreateReminderAction::class)->setName('reminders.create');
            $reminders->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/edit', EditReminderAction::class)
                ->setName('reminders.edit')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $reminders->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/delete', DeleteReminderAction::class)
                ->setName('reminders.delete')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $reminders->post('/reminders/{reminder:[0-9]+}/{action:done|dismiss|reopen}', ReminderStatusAction::class)
                ->setName('reminders.status')
                ->setArgument($ability, VehicleAbility::Log->value);
            $reminders->post('/settings/reminders/test', SendTestNotificationAction::class)->setName('settings.reminders.test');
            $reminders->post('/settings/reminders/calendar', CalendarFeedSettingsAction::class)
                ->setName('settings.reminders.calendar');
        })->add($module(Feature::Reminders));

        $group->group('', function (Group $reports): void {
            $reports->get('/reports', ReportAction::class)->setName('reports.index');
            $reports->get('/reports/export.csv', ReportExportAction::class)->setName('reports.export');
            $reports->get('/reports/ownership', OwnershipReportAction::class)->setName('reports.ownership');
            $reports->get('/reports/ownership.csv', OwnershipExportAction::class)->setName('reports.ownership.export');
            $reports->get('/reports/true-cost', TrueCostReportAction::class)->setName('reports.true_cost');
            $reports->get('/reports/true-cost.csv', TrueCostExportAction::class)->setName('reports.true_cost.export');
        })->add($module(Feature::Reports));

        $group->get('/settings', SettingsAction::class)->setName('settings');
        // The signed-in user's own account (spec.md §8 *Profile page*, Phase 33.2).
        $group->get('/profile', ProfileAction::class)->setName('profile');
        // Lead times also drive the vehicle tabs' due badges, so this page stays when reminders are off.
        $group->map(['GET', 'POST'], '/settings/reminders', ReminderSettingsAction::class)->setName('settings.reminders');
        $group->map(['GET', 'POST'], '/settings/modules', ModuleSettingsAction::class)->setName('settings.modules')
            ->setArgument($instance, InstanceAbility::ManageModules->value);
        $group->map(['GET', 'POST'], '/settings/tyres', TyreSettingsAction::class)
            ->setName('settings.tyres')
            ->add($module(Feature::Tyres));

        // Fuel stations (spec.md §7.33): shared by the install; favourites and
        // places are the signed-in user's own. Off with `stations` or `fuel`.
        $group->group('', function (Group $stations) use ($instance): void {
            $station = '/stations/{station:[0-9]+}';
            $stations->get('/stations', StationsIndexAction::class)->setName('stations.index');
            $stations->get('/stations/search', SearchStationsAction::class)->setName('stations.search');
            $stations->get('/stations/duplicates', StationDuplicatesAction::class)->setName('stations.duplicates');
            // Live fuel prices (spec.md §7.34): 404 until a provider is enabled.
            $stations->get('/stations/near', CheapestNearAction::class)->setName('stations.near');
            $stations->post('/stations/near/add', AddProviderStationAction::class)->setName('stations.near.add');
            $stations->map(['GET', 'POST'], '/stations/new', CreateStationAction::class)->setName('stations.create');
            $stations->get($station, ShowStationAction::class)->setName('stations.show');
            $stations->map(['GET', 'POST'], $station . '/edit', EditStationAction::class)->setName('stations.edit');
            $stations->post($station . '/favourite', FavouriteStationAction::class)->setName('stations.favourite');
            $stations->map(['GET', 'POST'], $station . '/merge', MergeStationAction::class)->setName('stations.merge');
            $stations->post($station . '/link', LinkStationAction::class)->setName('stations.link');
            $stations->post($station . '/alerts', PriceAlertAction::class)->setName('stations.alerts');
            // Settings → Fuel prices (spec.md §7.34): admins only, 404 to others.
            $stations->map(['GET', 'POST'], '/settings/fuel-prices', FuelPricesAction::class)->setName('settings.fuel_prices')
                ->setArgument($instance, InstanceAbility::ManageFuelPrices->value);
            $stations->get('/settings/places', PlacesAction::class)->setName('settings.places');
            $stations->map(['GET', 'POST'], '/settings/places/new', PlaceFormAction::class)->setName('settings.places.create');
            $stations->map(['GET', 'POST'], '/settings/places/{place:[0-9]+}/edit', PlaceFormAction::class)
                ->setName('settings.places.edit');
            $stations->post('/settings/places/{place:[0-9]+}/delete', PlaceDeleteAction::class)
                ->setName('settings.places.delete');
        })->add($module(Feature::Stations));

        // The claims history, every vehicle the user can see (spec.md §7.29).
        $group->group('', function (Group $incidents): void {
            $incidents->get('/incidents/history', ClaimsHistoryAction::class)->setName('incidents.history');
            $incidents->get('/incidents/history.csv', ClaimsHistoryExportAction::class)->setName('incidents.history.export');
        })->add($module(Feature::Incidents));

        // The signed-in user's own claim and trip settings (spec.md §7.23).
        $group->group('', function (Group $trips): void {
            $trips->get('/trips/claim', ClaimReportAction::class)->setName('trips.claim');
            $trips->get('/trips/claim.csv', ClaimExportAction::class)->setName('trips.claim.export');
            $trips->map(['GET', 'POST'], '/settings/trips', TripSettingsAction::class)->setName('settings.trips');
            $trips->map(['GET', 'POST'], '/settings/trips/journeys/new', JourneyFormAction::class)
                ->setName('settings.trips.journeys.create');
            $trips->map(['GET', 'POST'], '/settings/trips/journeys/{journey:[0-9]+}/edit', JourneyFormAction::class)
                ->setName('settings.trips.journeys.edit');
            $trips->map(['GET', 'POST'], '/settings/trips/journeys/{journey:[0-9]+}/delete', JourneyDeleteAction::class)
                ->setName('settings.trips.journeys.delete');
            $trips->post('/settings/trips/journeys/{journey:[0-9]+}/move/{direction:up|down}', JourneyMoveAction::class)
                ->setName('settings.trips.journeys.move');
            $trips->map(['GET', 'POST'], '/settings/trips/rates/new', RateSetFormAction::class)
                ->setName('settings.trips.rates.create');
            $trips->map(['GET', 'POST'], '/settings/trips/rates/{set:[0-9]+}/edit', RateSetFormAction::class)
                ->setName('settings.trips.rates.edit');
            $trips->map(['GET', 'POST'], '/settings/trips/rates/{set:[0-9]+}/delete', RateSetDeleteAction::class)
                ->setName('settings.trips.rates.delete');
        })->add($module(Feature::Trips));
        $group->get('/settings/backup', BackupPageAction::class)->setName('backup.index')
            ->setArgument($instance, InstanceAbility::Backup->value);
        $group->get('/settings/backup/download', DownloadBackupAction::class)->setName('backup.download')
            ->setArgument($instance, InstanceAbility::Backup->value);
        $group->post('/settings/backup/restore', UploadRestoreAction::class)->setName('backup.restore')
            ->setArgument($instance, InstanceAbility::Restore->value);
        $group->map(['GET', 'POST'], '/settings/backup/restore/{token:[a-f0-9]{32}}', ConfirmRestoreAction::class)
            ->setName('backup.restore.confirm')
            ->setArgument($instance, InstanceAbility::Restore->value);
        // A scheduled backup from BACKUP_PATH (spec.md §7.13, §7.30).
        $scheduled = '{name:logbook-scheduled-[0-9]{8}-[0-9]{6}\\.zip}';
        $group->get('/settings/backup/files/' . $scheduled, DownloadScheduledBackupAction::class)
            ->setName('backup.file')
            ->setArgument($instance, InstanceAbility::Backup->value);
        // Settings → Jobs (spec.md §7.30): admins only, and 404 (not 403) to anyone else.
        $group->group('/settings/jobs', function (Group $jobs) use ($instance): void {
            $run = InstanceAbility::RunJobs->value;
            $jobs->get('', JobsAction::class)->setName('settings.jobs')->setArgument($instance, $run);
            $jobs->post('/triggers', JobTriggersAction::class)->setName('settings.jobs.triggers')
                ->setArgument($instance, $run);
            $jobs->post('/url-token', JobUrlTokenAction::class)->setName('settings.jobs.url_token')
                ->setArgument($instance, $run);
            $jobs->post('/backup', JobBackupScheduleAction::class)->setName('settings.jobs.backup')
                ->setArgument($instance, $run);
            $jobs->get('/runs/{run:[0-9]+}', JobRunAction::class)->setName('settings.jobs.run')
                ->setArgument($instance, $run);
            $jobs->get('/runs/{run:[0-9]+}/status', JobRunStatusAction::class)->setName('settings.jobs.run.status')
                ->setArgument($instance, $run);
            $jobs->post('/{job:[a-z_]+}/run', RunJobAction::class)->setName('settings.jobs.run_now')
                ->setArgument($instance, $run);
            $jobs->get('/{job:[a-z_]+}/started', JobStartedAction::class)->setName('settings.jobs.started')
                ->setArgument($instance, $run);
        });
        // Settings → Updates (spec.md §7.31): admins only; 404 with UPDATE_CHECK_ALLOWED=false.
        $group->map(['GET', 'POST'], '/settings/updates', UpdatesAction::class)->setName('settings.updates')
            ->setArgument($instance, InstanceAbility::RunJobs->value);
        // The dashboard's admin notices (spec.md §7.30): Dismiss, for 24 hours (the update banner for good).
        $group->post('/notices/{key:[0-9A-Za-z_.\\-]+}/dismiss', DismissNoticeAction::class)->setName('notices.dismiss')
            ->setArgument($instance, InstanceAbility::RunJobs->value);
        // *On page visits* (spec.md §7.30): any signed-in page's beacon; 404 while off.
        $group->post('/_scheduler/tick', SchedulerTickAction::class)->setName('scheduler.tick');
        // Users and their one-time links (spec.md §7.9): admins only.
        $group->map(['GET', 'POST'], '/settings/users', UsersAction::class)->setName('settings.users')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        $group->post('/settings/users/{member:[0-9]+}/{action:admin|member|enable|reset}', UserAction::class)
            ->setName('settings.users.change')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        // *Revoke access* (the old *Disable*) and *Sign out everywhere* ask first (Phase 33.1).
        $group->map(['GET', 'POST'], '/settings/users/{member:[0-9]+}/{action:disable|sign-out}', ConfirmUserAction::class)
            ->setName('settings.users.confirm')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        // *Add user* (Phase 33.1): the account at once, with a set-password link by email.
        $group->map(['GET', 'POST'], '/settings/users/add', AddUserAction::class)
            ->setName('settings.users.add')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        $group->map(['GET', 'POST'], '/settings/users/{member:[0-9]+}/delete', DeleteUserAction::class)
            ->setName('settings.users.delete')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        $group->post('/settings/users/{member:[0-9]+}/vehicles/{vehicle:[0-9]+}/transfer', AdminTransferAction::class)
            ->setName('settings.users.transfer')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        $group->post('/settings/users/links/{invitation:[0-9]+}/revoke', RevokeInvitationAction::class)
            ->setName('settings.users.revoke')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        $group->post('/settings/users/{member:[0-9]+}/identities/{identity:[0-9]+}/remove', RemoveIdentityAction::class)
            ->setName('settings.users.identity.remove')
            ->setArgument($instance, InstanceAbility::ManageUsers->value);
        // One's own API keys (spec.md §7.20); kept when the API is off, so keys can be prepared.
        $group->map(['GET', 'POST'], '/settings/api-keys', ApiKeysAction::class)->setName('settings.api_keys');
        $group->map(['GET', 'POST'], '/settings/api-keys/{key:[0-9]+}/revoke', RevokeApiKeyAction::class)
            ->setName('settings.api_keys.revoke');
        $group->post('/settings/preferences', SavePreferencesAction::class)->setName('settings.preferences');
        $group->post('/settings/password', ChangePasswordAction::class)->setName('settings.password');
        // One's own email address and avatar (spec.md §7.9, Phase 33.1).
        $group->post('/settings/email', EmailSettingsAction::class)->setName('settings.email');
        $group->post('/settings/email/{action:resend|cancel}', EmailSettingsAction::class)->setName('settings.email.action');
        $group->post('/settings/avatar', AvatarSettingsAction::class)->setName('settings.avatar');
        $group->post('/settings/avatar/{action:remove}', AvatarSettingsAction::class)->setName('settings.avatar.action');
        // Anyone's avatar, to any signed-in user (#161).
        $group->get('/users/{member:[0-9]+}/avatar', AvatarAction::class)->setName('users.avatar');
        // One's own single sign-on account (spec.md §7.9 *Linking*).
        $group->post('/settings/sso/link', OidcLinkAction::class)->setName('settings.sso.link');
        $group->post('/settings/sso/{identity:[0-9]+}/unlink', OidcUnlinkAction::class)->setName('settings.sso.unlink');
        $group->post('/settings/theme', SetThemeAction::class)->setName('settings.theme');

        // Insights (spec.md §7.26 *Ask and the Insights page*, Phase 33.4): core.
        $group->get('/insights', InsightsPageAction::class)->setName('insights');

        // AI (spec.md §7.25, Phase 26.1): not routed at all with AI_ENABLED=false.
        if ($settings->ai->enabled) {
            // One's own *Use AI features* switch; 404 until AI is set up.
            $group->post('/settings/ai-use', AiUseAction::class)->setName('settings.ai_use');
            // Ask Logbook (spec.md §7.26, Phase 26.2): 404 unless Ask is available to the user.
            // AI insights (spec.md §7.26, Phase 33.4): *Refresh*, or the Insights page's first view of the day.
            $group->post('/insights/refresh', RefreshAiInsightsAction::class)->setName('insights.refresh');
            $group->get('/ask', AskAction::class)->setName('ask');
            $group->post('/ask', AskPostAction::class)->setName('ask.post');
            $group->get('/ask/progress/{token:[0-9a-f]{32}}', AskProgressAction::class)->setName('ask.progress');
            $group->post('/ask/retention', AskRetentionAction::class)->setName('ask.retention');
            $group->post('/ask/threads/delete', AskThreadDeleteAction::class)->setName('ask.threads.delete');
            $group->get('/ask/threads/{thread:[0-9]+}', AskAction::class)->setName('ask.thread');
            $group->post('/ask/threads/{thread:[0-9]+}/delete', AskThreadDeleteAction::class)->setName('ask.thread.delete');
            $group->post('/ask/messages/{message:[0-9]+}/feedback', AskFeedbackAction::class)->setName('ask.feedback');
            // Drafting entries (Phase 26.3): a card's buttons; the draft is the user's own or not found.
            $group->post('/ask/drafts/{draft:[0-9]+}/{action:add|discard|undo}', AskDraftAction::class)->setName('ask.draft');
            // Reading files (spec.md §7.27, Phase 26.4): 404 unless scanning is available; a scan is its user's own.
            $group->map(['GET', 'POST'], '/scan', ScanAction::class)->setName('scan');
            $group->get('/scan/{token:[0-9a-f]{32}}', ScanResultAction::class)->setName('scan.result');
            $group->get('/scan/{token:[0-9a-f]{32}}/file', ScanFileAction::class)->setName('scan.file');
            $group->map(['GET', 'POST'], '/scan/{token:[0-9a-f]{32}}/reminders', ScanRemindersAction::class)
                ->setName('scan.reminders');
            $group->map(['GET', 'POST'], '/scan/{token:[0-9a-f]{32}}/vehicle', ScanVehicleAction::class)
                ->setName('scan.vehicle');
            // Settings → AI: admins only, and 404 (not 403) to anyone else.
            $group->group('/settings/ai', function (Group $ai) use ($instance): void {
                $manage = InstanceAbility::ManageAi->value;
                $ai->get('', AiSettingsAction::class)->setName('settings.ai')->setArgument($instance, $manage);
                $ai->post('/tasks', AiTasksAction::class)->setName('settings.ai.tasks')->setArgument($instance, $manage);
                $ai->post('/this-host', AiThisHostAction::class)->setName('settings.ai.this_host')
                    ->setArgument($instance, $manage);
                $ai->map(['GET', 'POST'], '/connections/new', AiConnectionFormAction::class)
                    ->setName('settings.ai.connections.create')
                    ->setArgument($instance, $manage);
                $ai->get('/connections/{connection:[0-9]+}', AiConnectionAction::class)
                    ->setName('settings.ai.connections.show')
                    ->setArgument($instance, $manage);
                $ai->map(['GET', 'POST'], '/connections/{connection:[0-9]+}/edit', AiConnectionFormAction::class)
                    ->setName('settings.ai.connections.edit')
                    ->setArgument($instance, $manage);
                $ai->map(['GET', 'POST'], '/connections/{connection:[0-9]+}/delete', AiConnectionDeleteAction::class)
                    ->setName('settings.ai.connections.delete')
                    ->setArgument($instance, $manage);
                $ai->post('/connections/{connection:[0-9]+}/acknowledge', AiAcknowledgeAction::class)
                    ->setName('settings.ai.connections.acknowledge')
                    ->setArgument($instance, $manage);
                $ai->post('/connections/{connection:[0-9]+}/models', AiModelsAction::class)
                    ->setName('settings.ai.connections.models')
                    ->setArgument($instance, $manage);
                $ai->post('/connections/{connection:[0-9]+}/test', AiTestAction::class)
                    ->setName('settings.ai.connections.test')
                    ->setArgument($instance, $manage);
            });
        }
    })->add(InstanceAccessMiddleware::class)
        ->add(VehicleAccessMiddleware::class)
        ->add(CsrfMiddleware::class)
        ->add(AuthGuardMiddleware::class)
        ->add(ProxyAuthMiddleware::class);
};
