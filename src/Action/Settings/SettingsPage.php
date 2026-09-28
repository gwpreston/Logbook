<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\ProfileForm;
use Logbook\Support\Display\Accent;
use Logbook\Support\Display\Theme;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the settings page (shared by its GET and POST Actions, so a form
 * with errors is shown in place with the submitted values).
 */
final readonly class SettingsPage
{
    public function __construct(
        private View $view,
        private AvailableLocales $locales,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string>|null $values preference form values; null = the saved ones
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?array $values = null,
        ?ValidationErrors $preferenceErrors = null,
        ?ValidationErrors $passwordErrors = null,
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);

        return $this->view->render($request, $response, 'settings/index.twig', [
            'values' => $values ?? ProfileForm::values($user),
            'errors' => $preferenceErrors?->all() ?? [],
            'password_errors' => $passwordErrors?->all() ?? [],
            'themes' => Theme::cases(),
            'accents' => Accent::cases(),
            'distance_units' => DistanceUnit::cases(),
            'volume_units' => VolumeUnit::cases(),
            'consumption_units' => ConsumptionUnit::cases(),
            'unit_presets' => UnitPreset::cases(),
            'locale_options' => FormOptions::locales($this->locales),
            'timezone_options' => FormOptions::timezones(),
            'currency_options' => FormOptions::currencies(RequestContext::locale($request)),
            // Sample values so the effect of each preference is visible.
            'now' => $this->clock->now(),
        ], $status);
    }
}
