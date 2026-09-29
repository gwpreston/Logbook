<?php

declare(strict_types=1);

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
use Logbook\Action\Report\ReportAction;
use Logbook\Action\Report\ReportExportAction;
use Logbook\Action\Settings\CalendarFeedSettingsAction;
use Logbook\Action\Settings\ChangePasswordAction;
use Logbook\Action\Settings\ModuleSettingsAction;
use Logbook\Action\Settings\ReminderSettingsAction;
use Logbook\Action\Settings\SavePreferencesAction;
use Logbook\Action\Settings\SendTestNotificationAction;
use Logbook\Action\Settings\SetThemeAction;
use Logbook\Action\Settings\TyreSettingsAction;
use Logbook\Action\Settings\SettingsAction;
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
use Logbook\Domain\Feature\Feature;
use Logbook\Middleware\AuthGuardMiddleware;
use Logbook\Middleware\CsrfMiddleware;
use Logbook\Middleware\FeatureGateMiddleware;
use Logbook\Service\Feature\FeatureToggles;
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
 * gate, so a switched-off module's pages answer 404 (spec.md §7.10). Group
 * closures must not be static: Slim binds them to the container.
 */
return static function (App $app): void {
    $container = $app->getContainer();
    assert($container instanceof ContainerInterface);
    $toggles = $container->get(FeatureToggles::class);
    assert($toggles instanceof FeatureToggles);
    $module = static fn (Feature $feature): FeatureGateMiddleware => new FeatureGateMiddleware($feature, $toggles);

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

    // Signed-out pages.
    $app->group('', function (Group $group): void {
        $group->map(['GET', 'POST'], '/setup', SetupAction::class)->setName('setup');
        $group->map(['GET', 'POST'], '/login', LoginAction::class)->setName('login');
        $group->get('/diagnostics/deep/link', DeepLinkCheckAction::class)->setName('diagnostics.deep-link');
    })->add(CsrfMiddleware::class);

    // Signed-in pages.
    $app->group('', function (Group $group) use ($module): void {
        $group->get('/', HomeAction::class)->setName('home');
        $group->post('/logout', LogoutAction::class)->setName('logout');
        $group->post('/dashboard/layout', SaveDashboardLayoutAction::class)->setName('dashboard.layout');

        // "+ Log entry" (spec.md §7.3). The picker checks the kind's module itself.
        $group->get('/log/new', LogEntryAction::class)->setName('log.chooser');
        $group->get('/log/new/{kind:odometer|maintenance|expense|document|schedule|tyre|tyre_check}', LogPickVehicleAction::class)
            ->setName('log.pick');

        $group->get('/garage', GarageAction::class)->setName('garage');
        $group->map(['GET', 'POST'], '/vehicles/new', CreateVehicleAction::class)->setName('vehicles.create');
        $group->get('/vehicles/{id:[0-9]+}', ShowVehicleAction::class)->setName('vehicles.show');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/edit', EditVehicleAction::class)->setName('vehicles.edit');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/delete', DeleteVehicleAction::class)->setName('vehicles.delete');
        $group->post('/vehicles/{id:[0-9]+}/archive', ArchiveVehicleAction::class)->setName('vehicles.archive');
        $group->post('/vehicles/{id:[0-9]+}/restore', RestoreVehicleAction::class)->setName('vehicles.restore');
        $group->get('/vehicles/{id:[0-9]+}/photo', VehiclePhotoAction::class)->setName('vehicles.photo');

        // History (spec.md §7.16): core, so no feature gate; the feed leaves
        // switched-off modules out itself.
        $group->get('/history', FleetHistoryAction::class)->setName('history.fleet');
        $group->get('/vehicles/{id:[0-9]+}/history', VehicleHistoryAction::class)->setName('history.vehicle');
        $group->get('/vehicles/{id:[0-9]+}/history/print', HistoryPrintAction::class)->setName('history.print');

        $group->get('/vehicles/{id:[0-9]+}/odometer', OdometerLogAction::class)->setName('odometer.index');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/new', CreateOdometerReadingAction::class)
            ->setName('odometer.create');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/edit', EditOdometerReadingAction::class)
            ->setName('odometer.edit');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/delete', DeleteOdometerReadingAction::class)
            ->setName('odometer.delete');

        $group->group('', function (Group $fuel): void {
            $fuel->get('/fuel/new', QuickFuelAction::class)->setName('fuel.quick');
            $fuel->get('/vehicles/{id:[0-9]+}/fuel', FuelLogAction::class)->setName('fuel.index');
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/new', CreateFuelEntryAction::class)->setName('fuel.create');
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/edit', EditFuelEntryAction::class)
                ->setName('fuel.edit');
            $fuel->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/delete', DeleteFuelEntryAction::class)
                ->setName('fuel.delete');
            $fuel->post('/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/economy', ConfirmEconomyAction::class)
                ->setName('fuel.economy');
        })->add($module(Feature::Fuel));

        $group->group('/vehicles/{id:[0-9]+}', function (Group $vehicle) use ($module): void {
            $vehicle->group('', function (Group $maintenance): void {
                $maintenance->get('/maintenance', MaintenanceLogAction::class)->setName('maintenance.index');
                $maintenance->map(['GET', 'POST'], '/maintenance/new', CreateMaintenanceEntryAction::class)
                    ->setName('maintenance.create');
                $maintenance->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/edit', EditMaintenanceEntryAction::class)
                    ->setName('maintenance.edit');
                $maintenance->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/delete', DeleteMaintenanceEntryAction::class)
                    ->setName('maintenance.delete');
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/new', CreateScheduleAction::class)
                    ->setName('maintenance.schedules.create');
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/edit', EditScheduleAction::class)
                    ->setName('maintenance.schedules.edit');
                $maintenance->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/delete', DeleteScheduleAction::class)
                    ->setName('maintenance.schedules.delete');
            })->add($module(Feature::Maintenance));

            // Tyres (spec.md §7.17).
            $vehicle->group('/tyres', function (Group $tyres): void {
                $tyres->get('', TyreListAction::class)->setName('tyres.index');
                $tyres->map(['GET', 'POST'], '/{kind:existing|fit|swap|rotate|repair|remove|check}', TyreChangeFormAction::class)
                    ->setName('tyres.change');
                $tyres->map(['GET', 'POST'], '/changes/{change:[0-9]+}/edit', EditTyreChangeAction::class)
                    ->setName('tyres.changes.edit');
                $tyres->map(['GET', 'POST'], '/changes/{change:[0-9]+}/delete', DeleteTyreChangeAction::class)
                    ->setName('tyres.changes.delete');
                $tyres->map(['GET', 'POST'], '/sets/{set:[0-9]+}/edit', EditTyreSetAction::class)->setName('tyres.sets.edit');
                $tyres->map(['GET', 'POST'], '/sets/{set:[0-9]+}/delete', DeleteTyreSetAction::class)
                    ->setName('tyres.sets.delete');
                $tyres->map(['GET', 'POST'], '/{tyre:[0-9]+}/edit', EditTyreAction::class)->setName('tyres.edit');
                $tyres->map(['GET', 'POST'], '/{tyre:[0-9]+}/delete', DeleteTyreAction::class)->setName('tyres.delete');
            })->add($module(Feature::Tyres));

            $vehicle->group('', function (Group $documents): void {
                $documents->get('/documents', ComplianceListAction::class)->setName('compliance.index');
                $documents->map(['GET', 'POST'], '/documents/new', CreateComplianceDocumentAction::class)
                    ->setName('compliance.create');
                $documents->map(['GET', 'POST'], '/documents/{document:[0-9]+}/edit', EditComplianceDocumentAction::class)
                    ->setName('compliance.edit');
                $documents->map(['GET', 'POST'], '/documents/{document:[0-9]+}/delete', DeleteComplianceDocumentAction::class)
                    ->setName('compliance.delete');
            })->add($module(Feature::Compliance));

            $vehicle->get('/expenses', VehicleExpensesAction::class)->setName('expenses.index');
            $vehicle->map(['GET', 'POST'], '/expenses/new', CreateExpenseAction::class)->setName('expenses.create');
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/edit', EditExpenseAction::class)
                ->setName('expenses.edit');
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/delete', DeleteExpenseAction::class)
                ->setName('expenses.delete');

            // Valuations (Phase 14.1) are core: no module toggle.
            $vehicle->get('/valuations', VehicleValuationsAction::class)->setName('valuations.index');
            $vehicle->map(['GET', 'POST'], '/valuations/new', CreateValuationAction::class)->setName('valuations.create');
            $vehicle->map(['GET', 'POST'], '/valuations/{entry:[0-9]+}/edit', EditValuationAction::class)
                ->setName('valuations.edit');
            $vehicle->map(['GET', 'POST'], '/valuations/{entry:[0-9]+}/delete', DeleteValuationAction::class)
                ->setName('valuations.delete');

            // Export and import check the module's toggle themselves (one route, several modules).
            $exportModule = '{module:fuel|odometer|maintenance|documents|expenses|tyres|tyre-changes|valuations}';
            $vehicle->get('/export/' . $exportModule . '.csv', ExportModuleAction::class)
                ->setName('export.module');
            $csvModule = '{module:fuel|odometer|maintenance|documents|expenses}';
            $vehicle->map(['GET', 'POST'], '/import/' . $csvModule, ImportUploadAction::class)
                ->setName('import.upload');
            $vehicle->map(['GET', 'POST'], '/import/' . $csvModule . '/{token:[a-f0-9]{32}}', ImportAction::class)
                ->setName('import.map');

            $vehicle->get('/attachments/{attachment:[0-9]+}', ShowAttachmentAction::class)->setName('attachments.show');
            $vehicle->map(['GET', 'POST'], '/attachments/{attachment:[0-9]+}/delete', DeleteAttachmentAction::class)
                ->setName('attachments.delete');
        });

        $group->group('', function (Group $reminders): void {
            $reminders->get('/reminders', ReminderListAction::class)->setName('reminders.index');
            $reminders->map(['GET', 'POST'], '/reminders/new', CreateReminderAction::class)->setName('reminders.create');
            $reminders->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/edit', EditReminderAction::class)
                ->setName('reminders.edit');
            $reminders->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/delete', DeleteReminderAction::class)
                ->setName('reminders.delete');
            $reminders->post('/reminders/{reminder:[0-9]+}/{action:done|dismiss|reopen}', ReminderStatusAction::class)
                ->setName('reminders.status');
            $reminders->post('/settings/reminders/test', SendTestNotificationAction::class)->setName('settings.reminders.test');
            $reminders->post('/settings/reminders/calendar', CalendarFeedSettingsAction::class)
                ->setName('settings.reminders.calendar');
        })->add($module(Feature::Reminders));

        $group->group('', function (Group $reports): void {
            $reports->get('/reports', ReportAction::class)->setName('reports.index');
            $reports->get('/reports/export.csv', ReportExportAction::class)->setName('reports.export');
        })->add($module(Feature::Reports));

        $group->get('/settings', SettingsAction::class)->setName('settings');
        // Lead times also drive the vehicle tabs' due badges, so this page stays when reminders are off.
        $group->map(['GET', 'POST'], '/settings/reminders', ReminderSettingsAction::class)->setName('settings.reminders');
        $group->map(['GET', 'POST'], '/settings/modules', ModuleSettingsAction::class)->setName('settings.modules');
        $group->map(['GET', 'POST'], '/settings/tyres', TyreSettingsAction::class)
            ->setName('settings.tyres')
            ->add($module(Feature::Tyres));
        $group->get('/settings/backup', BackupPageAction::class)->setName('backup.index');
        $group->get('/settings/backup/download', DownloadBackupAction::class)->setName('backup.download');
        $group->post('/settings/backup/restore', UploadRestoreAction::class)->setName('backup.restore');
        $group->map(['GET', 'POST'], '/settings/backup/restore/{token:[a-f0-9]{32}}', ConfirmRestoreAction::class)
            ->setName('backup.restore.confirm');
        $group->post('/settings/preferences', SavePreferencesAction::class)->setName('settings.preferences');
        $group->post('/settings/password', ChangePasswordAction::class)->setName('settings.password');
        $group->post('/settings/theme', SetThemeAction::class)->setName('settings.theme');
    })->add(CsrfMiddleware::class)->add(AuthGuardMiddleware::class);
};
