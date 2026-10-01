<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\User;
use Logbook\Domain\User\UserIdentity;
use Logbook\Service\Auth\SignInMethods;
use Logbook\Service\User\ProfileForm;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Display\Accent;
use Logbook\Support\Display\Theme;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DepthUnit;
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
        private SignInMethods $methods,
        private AppSettings $settings,
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
            'depth_units' => DepthUnit::cases(),
            'unit_presets' => UnitPreset::cases(),
            'locale_options' => FormOptions::locales($this->locales),
            'timezone_options' => FormOptions::timezones(),
            'currency_options' => FormOptions::currencies(RequestContext::locale($request)),
            // Sample values so the effect of each preference is visible.
            'now' => $this->clock->now(),
            // Single sign-on (spec.md §7.9 *Linking*) and whether a password is any use.
            'local_login' => $this->settings->localLogin,
            'has_password' => $user->hasPassword(),
            'sso' => $this->signInCard($user),
        ], $status);
    }

    /**
     * The *Single sign-on* card: linked OIDC and proxy accounts, and *Link*
     * while OIDC is configured and none is linked; null when there is
     * nothing to show.
     *
     * @return array<string, mixed>|null
     */
    private function signInCard(User $user): ?array
    {
        $identities = $this->methods->identities($user);
        $oidc = $this->settings->oidc->isConfigured();
        if (!$oidc && $identities === []) {
            return null;
        }
        $providers = array_map(static fn (UserIdentity $identity): string => $identity->provider, $identities);
        $hasOidc = in_array(UserIdentity::OIDC, $providers, true);

        return [
            'name' => $this->settings->oidc->providerName,
            'can_link_oidc' => $oidc && !$hasOidc,
            'identities' => array_map(
                fn (UserIdentity $identity): array => [
                    'identity' => $identity,
                    'can_remove' => $this->methods->canRemove($user, $identity),
                ],
                $identities,
            ),
        ];
    }
}
