<?php

declare(strict_types=1);

use Logbook\Action\Auth\LoginAction;
use Logbook\Action\Auth\LogoutAction;
use Logbook\Action\Auth\SetupAction;
use Logbook\Action\DeepLinkCheckAction;
use Logbook\Action\Fuel\CreateFuelEntryAction;
use Logbook\Action\Fuel\DeleteFuelEntryAction;
use Logbook\Action\Fuel\EditFuelEntryAction;
use Logbook\Action\Fuel\FuelLogAction;
use Logbook\Action\Fuel\QuickFuelAction;
use Logbook\Action\Garage\GarageAction;
use Logbook\Action\HealthAction;
use Logbook\Action\HomeAction;
use Logbook\Action\Odometer\CreateOdometerReadingAction;
use Logbook\Action\Odometer\DeleteOdometerReadingAction;
use Logbook\Action\Odometer\EditOdometerReadingAction;
use Logbook\Action\Odometer\OdometerLogAction;
use Logbook\Action\Settings\ChangePasswordAction;
use Logbook\Action\Settings\SavePreferencesAction;
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

        $group->get('/settings', SettingsAction::class)->setName('settings');
        $group->post('/settings/preferences', SavePreferencesAction::class)->setName('settings.preferences');
        $group->post('/settings/password', ChangePasswordAction::class)->setName('settings.password');
        $group->post('/settings/theme', SetThemeAction::class)->setName('settings.theme');
    })->add(CsrfMiddleware::class)->add(AuthGuardMiddleware::class);
};
