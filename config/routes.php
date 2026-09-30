<?php

declare(strict_types=1);

use Logbook\Action\Api\ListDocumentsAction as ApiDocumentsAction;
use Logbook\Action\Api\ListExpensesAction as ApiExpensesAction;
use Logbook\Action\Api\ListFuelAction as ApiFuelAction;
use Logbook\Action\Api\ListMaintenanceAction as ApiMaintenanceAction;
use Logbook\Action\Api\ListOdometerAction as ApiOdometerAction;
use Logbook\Action\Api\ListTyresAction as ApiTyresAction;
use Logbook\Action\Api\ListVehiclesAction as ApiVehiclesAction;
use Logbook\Action\Api\LogFuelAction as ApiLogFuelAction;
use Logbook\Action\Api\LogReadingAction as ApiLogReadingAction;
use Logbook\Action\Api\MeAction as ApiMeAction;
use Logbook\Action\Api\OpenApiAction;
use Logbook\Action\Api\RemindersAction as ApiRemindersAction;
use Logbook\Action\Api\ShowVehicleAction as ApiVehicleAction;
use Logbook\Action\Api\UpcomingAction as ApiUpcomingAction;
use Logbook\Action\Api\VehicleSummaryAction as ApiSummaryAction;
use Logbook\Action\Attachment\DeleteAttachmentAction;
use Logbook\Action\Attachment\ShowAttachmentAction;
use Logbook\Action\Auth\LoginAction;
use Logbook\Action\Auth\LogoutAction;
use Logbook\Action\Auth\SetupAction;
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
use Logbook\Action\Fuel\ConfirmEconomyAction;
use Logbook\Action\Fuel\CreateFuelEntryAction;
use Logbook\Action\Fuel\DeleteFuelEntryAction;
use Logbook\Action\Fuel\EditFuelEntryAction;
use Logbook\Action\Fuel\FuelLogAction;
use Logbook\Action\Fuel\QuickFuelAction;
use Logbook\Action\Garage\GarageAction;
use Logbook\Action\HealthAction;
use Logbook\Action\Forecast\ComingUpAction;
use Logbook\Action\Forecast\ComingUpExportAction;
use Logbook\Action\History\FleetHistoryAction;
use Logbook\Action\History\HistoryPrintAction;
use Logbook\Action\History\VehicleHistoryAction;
use Logbook\Action\HomeAction;
use Logbook\Action\Import\ImportAction;
use Logbook\Action\Import\ImportUploadAction;
use Logbook\Action\Log\LogEntryAction;
use Logbook\Action\Log\LogPickVehicleAction;
use Logbook\Action\Maintenance\CreateMaintenanceEntryAction;
use Logbook\Action\Maintenance\CreateScheduleAction;
use Logbook\Action\Maintenance\DeleteMaintenanceEntryAction;
use Logbook\Action\Maintenance\DeleteScheduleAction;
use Logbook\Action\Maintenance\EditMaintenanceEntryAction;
use Logbook\Action\Maintenance\EditScheduleAction;
use Logbook\Action\Maintenance\MaintenanceLogAction;
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
use Logbook\Action\Reminder\ReminderListAction;
use Logbook\Action\Reminder\ReminderStatusAction;
use Logbook\Action\Report\OwnershipExportAction;
use Logbook\Action\Report\OwnershipReportAction;
use Logbook\Action\Report\ReportAction;
use Logbook\Action\Report\ReportExportAction;
use Logbook\Action\SalePack\DownloadPaperworkAction;
use Logbook\Action\SalePack\ShowSalePackAction;
use Logbook\Action\Settings\ApiKeysAction;
use Logbook\Action\Settings\CalendarFeedSettingsAction;
use Logbook\Action\Settings\ChangePasswordAction;
use Logbook\Action\Settings\ModuleSettingsAction;
use Logbook\Action\Settings\ReminderSettingsAction;
use Logbook\Action\Settings\RevokeApiKeyAction;
use Logbook\Action\Settings\SavePreferencesAction;
use Logbook\Action\Settings\SendTestNotificationAction;
use Logbook\Action\Settings\SetThemeAction;
use Logbook\Action\Settings\TyreSettingsAction;
use Logbook\Action\Settings\SettingsAction;
use Logbook\Action\Sharing\ChangeShareAction;
use Logbook\Action\Sharing\MyShareAction;
use Logbook\Action\Sharing\SharingAction;
use Logbook\Action\Sharing\TransferVehicleAction;
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
use Logbook\Action\Vehicle\RestoreVehicleAction;
use Logbook\Action\Vehicle\ShowVehicleAction;
use Logbook\Action\Vehicle\VehiclePhotoAction;
use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Middleware\ApiAuthMiddleware;
use Logbook\Middleware\ApiErrorMiddleware;
use Logbook\Middleware\AuthGuardMiddleware;
use Logbook\Middleware\CsrfMiddleware;
use Logbook\Middleware\FeatureGateMiddleware;
use Logbook\Middleware\InstanceAccessMiddleware;
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
                $keyed->group('', function (Group $fuel) use ($ability): void {
                    $fuel->get('/vehicles/{id:[0-9]+}/fuel', ApiFuelAction::class)->setName('api.fuel.index')
                        ->setArgument($ability, VehicleAbility::View->value);
                    $fuel->post('/vehicles/{id:[0-9]+}/fuel', ApiLogFuelAction::class)->setName('api.fuel.create')
                        ->setArgument($ability, VehicleAbility::Log->value);
                })->add($module(Feature::Fuel));
                $keyed->get('/vehicles/{id:[0-9]+}/maintenance', ApiMaintenanceAction::class)->setName('api.maintenance.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Maintenance));
                $keyed->get('/vehicles/{id:[0-9]+}/documents', ApiDocumentsAction::class)->setName('api.documents.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Compliance));
                $keyed->get('/vehicles/{id:[0-9]+}/tyres', ApiTyresAction::class)->setName('api.tyres.index')
                    ->setArgument($ability, VehicleAbility::View->value)
                    ->add($module(Feature::Tyres));
            })->add(VehicleAccessMiddleware::class)
                ->add(ApiAuthMiddleware::class);
        })->add(ApiErrorMiddleware::class);
    }

    // Signed-out pages.
    $app->group('', function (Group $group): void {
        $group->map(['GET', 'POST'], '/setup', SetupAction::class)->setName('setup');
        $group->map(['GET', 'POST'], '/login', LoginAction::class)->setName('login');
        $group->get('/diagnostics/deep/link', DeepLinkCheckAction::class)->setName('diagnostics.deep-link');
    })->add(CsrfMiddleware::class);

    // Signed-in pages.
    $app->group('', function (Group $group) use ($module, $ability, $instance): void {
        $group->get('/', HomeAction::class)->setName('home');
        $group->post('/logout', LogoutAction::class)->setName('logout');
        $group->post('/dashboard/layout', SaveDashboardLayoutAction::class)->setName('dashboard.layout');

        // "+ Log entry" (spec.md §7.3). The picker checks the kind's module itself.
        $group->get('/log/new', LogEntryAction::class)->setName('log.chooser');
        $group->get('/log/new/{kind:odometer|maintenance|expense|document|schedule|tyre|tyre_check}', LogPickVehicleAction::class)
            ->setName('log.pick');

        $group->get('/garage', GarageAction::class)->setName('garage');
        $group->map(['GET', 'POST'], '/vehicles/new', CreateVehicleAction::class)->setName('vehicles.create');
        $group->get('/vehicles/{id:[0-9]+}', ShowVehicleAction::class)->setName('vehicles.show')
            ->setArgument($ability, VehicleAbility::View->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/edit', EditVehicleAction::class)->setName('vehicles.edit')
            ->setArgument($ability, VehicleAbility::Manage->value);
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/delete', DeleteVehicleAction::class)->setName('vehicles.delete')
            ->setArgument($ability, VehicleAbility::Own->value);
        $group->post('/vehicles/{id:[0-9]+}/archive', ArchiveVehicleAction::class)->setName('vehicles.archive')
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
        $group->post('/vehicles/{id:[0-9]+}/sharing/{user:[0-9]+}/{action:save|remove}', ChangeShareAction::class)
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
            $exportModule = '{module:fuel|odometer|maintenance|documents|expenses|tyres|tyre-changes|valuations}';
            $vehicle->get('/export/' . $exportModule . '.csv', ExportModuleAction::class)
                ->setName('export.module')
                ->setArgument($ability, VehicleAbility::Manage->value);
            $csvModule = '{module:fuel|odometer|maintenance|documents|expenses}';
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
        })->add($module(Feature::Reports));

        $group->get('/settings', SettingsAction::class)->setName('settings');
        // Lead times also drive the vehicle tabs' due badges, so this page stays when reminders are off.
        $group->map(['GET', 'POST'], '/settings/reminders', ReminderSettingsAction::class)->setName('settings.reminders');
        $group->map(['GET', 'POST'], '/settings/modules', ModuleSettingsAction::class)->setName('settings.modules')
            ->setArgument($instance, InstanceAbility::ManageModules->value);
        $group->map(['GET', 'POST'], '/settings/tyres', TyreSettingsAction::class)
            ->setName('settings.tyres')
            ->add($module(Feature::Tyres));
        $group->get('/settings/backup', BackupPageAction::class)->setName('backup.index')
            ->setArgument($instance, InstanceAbility::Backup->value);
        $group->get('/settings/backup/download', DownloadBackupAction::class)->setName('backup.download')
            ->setArgument($instance, InstanceAbility::Backup->value);
        $group->post('/settings/backup/restore', UploadRestoreAction::class)->setName('backup.restore')
            ->setArgument($instance, InstanceAbility::Restore->value);
        $group->map(['GET', 'POST'], '/settings/backup/restore/{token:[a-f0-9]{32}}', ConfirmRestoreAction::class)
            ->setName('backup.restore.confirm')
            ->setArgument($instance, InstanceAbility::Restore->value);
        // One's own API keys (spec.md §7.20); kept when the API is off, so keys can be prepared.
        $group->map(['GET', 'POST'], '/settings/api-keys', ApiKeysAction::class)->setName('settings.api_keys');
        $group->map(['GET', 'POST'], '/settings/api-keys/{key:[0-9]+}/revoke', RevokeApiKeyAction::class)
            ->setName('settings.api_keys.revoke');
        $group->post('/settings/preferences', SavePreferencesAction::class)->setName('settings.preferences');
        $group->post('/settings/password', ChangePasswordAction::class)->setName('settings.password');
        $group->post('/settings/theme', SetThemeAction::class)->setName('settings.theme');
    })->add(InstanceAccessMiddleware::class)
        ->add(VehicleAccessMiddleware::class)
        ->add(CsrfMiddleware::class)
        ->add(AuthGuardMiddleware::class);
};
