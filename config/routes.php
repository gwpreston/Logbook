<?php

declare(strict_types=1);

use Logbook\Action\Attachment\DeleteAttachmentAction;
use Logbook\Action\Attachment\ShowAttachmentAction;
use Logbook\Action\Auth\LoginAction;
use Logbook\Action\Auth\LogoutAction;
use Logbook\Action\Auth\SetupAction;
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
use Logbook\Action\Fuel\CreateFuelEntryAction;
use Logbook\Action\Fuel\DeleteFuelEntryAction;
use Logbook\Action\Fuel\EditFuelEntryAction;
use Logbook\Action\Fuel\FuelLogAction;
use Logbook\Action\Fuel\QuickFuelAction;
use Logbook\Action\Garage\GarageAction;
use Logbook\Action\HealthAction;
use Logbook\Action\HomeAction;
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
use Logbook\Action\Settings\ReminderSettingsAction;
use Logbook\Action\Settings\SavePreferencesAction;
use Logbook\Action\Settings\SendTestNotificationAction;
use Logbook\Action\Settings\SetThemeAction;
use Logbook\Action\Settings\SettingsAction;
use Logbook\Action\Vehicle\ArchiveVehicleAction;
use Logbook\Action\Vehicle\CreateVehicleAction;
use Logbook\Action\Vehicle\DeleteVehicleAction;
use Logbook\Action\Vehicle\EditVehicleAction;
use Logbook\Action\Vehicle\RestoreVehicleAction;
use Logbook\Action\Vehicle\ShowVehicleAction;
use Logbook\Action\Vehicle\VehiclePhotoAction;
use Logbook\Middleware\AuthGuardMiddleware;
use Logbook\Middleware\CsrfMiddleware;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

/*
 * Route map only: path → invokable Action class. No logic here (CLAUDE.md §5).
 * Paths are relative to APP_BASE_PATH; templates build URLs with url_for().
 *
 * Every HTML route sits in a CSRF-protected group; machine endpoints such as
 * /health stay outside so they never create sessions. Group middleware runs
 * last-added first: auth guard, then CSRF. (Group closures must not be
 * static: Slim binds them to the container.)
 */
return static function (App $app): void {
    $app->get('/health', HealthAction::class)->setName('health');

    // Calendar apps cannot sign in: the secret token in the URL is the
    // authentication, and the feed never touches the session.
    $app->get('/calendar/{token:[0-9]+-[a-f0-9]{64}}.ics', CalendarFeedAction::class)->setName('calendar.feed');

    // Signed-out pages.
    $app->group('', function (Group $group): void {
        $group->map(['GET', 'POST'], '/setup', SetupAction::class)->setName('setup');
        $group->map(['GET', 'POST'], '/login', LoginAction::class)->setName('login');
        $group->get('/diagnostics/deep/link', DeepLinkCheckAction::class)->setName('diagnostics.deep-link');
    })->add(CsrfMiddleware::class);

    // Signed-in pages.
    $app->group('', function (Group $group): void {
        $group->get('/', HomeAction::class)->setName('home');
        $group->post('/logout', LogoutAction::class)->setName('logout');
        $group->post('/dashboard/layout', SaveDashboardLayoutAction::class)->setName('dashboard.layout');

        $group->get('/garage', GarageAction::class)->setName('garage');
        $group->map(['GET', 'POST'], '/vehicles/new', CreateVehicleAction::class)->setName('vehicles.create');
        $group->get('/vehicles/{id:[0-9]+}', ShowVehicleAction::class)->setName('vehicles.show');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/edit', EditVehicleAction::class)->setName('vehicles.edit');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/delete', DeleteVehicleAction::class)->setName('vehicles.delete');
        $group->post('/vehicles/{id:[0-9]+}/archive', ArchiveVehicleAction::class)->setName('vehicles.archive');
        $group->post('/vehicles/{id:[0-9]+}/restore', RestoreVehicleAction::class)->setName('vehicles.restore');
        $group->get('/vehicles/{id:[0-9]+}/photo', VehiclePhotoAction::class)->setName('vehicles.photo');

        $group->get('/vehicles/{id:[0-9]+}/odometer', OdometerLogAction::class)->setName('odometer.index');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/new', CreateOdometerReadingAction::class)
            ->setName('odometer.create');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/edit', EditOdometerReadingAction::class)
            ->setName('odometer.edit');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/odometer/{reading:[0-9]+}/delete', DeleteOdometerReadingAction::class)
            ->setName('odometer.delete');

        $group->get('/fuel/new', QuickFuelAction::class)->setName('fuel.quick');
        $group->get('/vehicles/{id:[0-9]+}/fuel', FuelLogAction::class)->setName('fuel.index');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/new', CreateFuelEntryAction::class)->setName('fuel.create');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/edit', EditFuelEntryAction::class)
            ->setName('fuel.edit');
        $group->map(['GET', 'POST'], '/vehicles/{id:[0-9]+}/fuel/{entry:[0-9]+}/delete', DeleteFuelEntryAction::class)
            ->setName('fuel.delete');

        $group->group('/vehicles/{id:[0-9]+}', function (Group $vehicle): void {
            $vehicle->get('/maintenance', MaintenanceLogAction::class)->setName('maintenance.index');
            $vehicle->map(['GET', 'POST'], '/maintenance/new', CreateMaintenanceEntryAction::class)
                ->setName('maintenance.create');
            $vehicle->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/edit', EditMaintenanceEntryAction::class)
                ->setName('maintenance.edit');
            $vehicle->map(['GET', 'POST'], '/maintenance/{entry:[0-9]+}/delete', DeleteMaintenanceEntryAction::class)
                ->setName('maintenance.delete');
            $vehicle->map(['GET', 'POST'], '/maintenance/schedules/new', CreateScheduleAction::class)
                ->setName('maintenance.schedules.create');
            $vehicle->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/edit', EditScheduleAction::class)
                ->setName('maintenance.schedules.edit');
            $vehicle->map(['GET', 'POST'], '/maintenance/schedules/{schedule:[0-9]+}/delete', DeleteScheduleAction::class)
                ->setName('maintenance.schedules.delete');

            $vehicle->get('/documents', ComplianceListAction::class)->setName('compliance.index');
            $vehicle->map(['GET', 'POST'], '/documents/new', CreateComplianceDocumentAction::class)
                ->setName('compliance.create');
            $vehicle->map(['GET', 'POST'], '/documents/{document:[0-9]+}/edit', EditComplianceDocumentAction::class)
                ->setName('compliance.edit');
            $vehicle->map(['GET', 'POST'], '/documents/{document:[0-9]+}/delete', DeleteComplianceDocumentAction::class)
                ->setName('compliance.delete');

            $vehicle->get('/expenses', VehicleExpensesAction::class)->setName('expenses.index');
            $vehicle->map(['GET', 'POST'], '/expenses/new', CreateExpenseAction::class)->setName('expenses.create');
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/edit', EditExpenseAction::class)
                ->setName('expenses.edit');
            $vehicle->map(['GET', 'POST'], '/expenses/{entry:[0-9]+}/delete', DeleteExpenseAction::class)
                ->setName('expenses.delete');

            $vehicle->get('/export/{module:fuel|odometer|maintenance|documents|expenses}.csv', ExportModuleAction::class)
                ->setName('export.module');

            $vehicle->get('/attachments/{attachment:[0-9]+}', ShowAttachmentAction::class)->setName('attachments.show');
            $vehicle->map(['GET', 'POST'], '/attachments/{attachment:[0-9]+}/delete', DeleteAttachmentAction::class)
                ->setName('attachments.delete');
        });

        $group->get('/reminders', ReminderListAction::class)->setName('reminders.index');
        $group->map(['GET', 'POST'], '/reminders/new', CreateReminderAction::class)->setName('reminders.create');
        $group->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/edit', EditReminderAction::class)->setName('reminders.edit');
        $group->map(['GET', 'POST'], '/reminders/{reminder:[0-9]+}/delete', DeleteReminderAction::class)
            ->setName('reminders.delete');
        $group->post('/reminders/{reminder:[0-9]+}/{action:done|dismiss|reopen}', ReminderStatusAction::class)
            ->setName('reminders.status');

        $group->get('/reports', ReportAction::class)->setName('reports.index');
        $group->get('/reports/export.csv', ReportExportAction::class)->setName('reports.export');

        $group->get('/settings', SettingsAction::class)->setName('settings');
        $group->map(['GET', 'POST'], '/settings/reminders', ReminderSettingsAction::class)->setName('settings.reminders');
        $group->post('/settings/reminders/test', SendTestNotificationAction::class)->setName('settings.reminders.test');
        $group->post('/settings/reminders/calendar', CalendarFeedSettingsAction::class)
            ->setName('settings.reminders.calendar');
        $group->post('/settings/preferences', SavePreferencesAction::class)->setName('settings.preferences');
        $group->post('/settings/password', ChangePasswordAction::class)->setName('settings.password');
        $group->post('/settings/theme', SetThemeAction::class)->setName('settings.theme');
    })->add(CsrfMiddleware::class)->add(AuthGuardMiddleware::class);
};
