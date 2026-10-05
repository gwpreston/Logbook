<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\User\User;
use Logbook\Domain\User\UserIdentity;
use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Auth\SignInMethods;
use Logbook\Service\User\AvatarService;
use Logbook\Service\User\EmailAddresses;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the profile page, the signed-in user's own account (spec.md §8
 * *Profile page*, Phase 33.2): shared by its GET and by the email, picture
 * and password forms, so a form with errors is shown in place.
 */
final readonly class ProfilePage
{
    public function __construct(
        private View $view,
        private SignInMethods $methods,
        private AppSettings $settings,
        private AiStatus $ai,
        private AiPreferences $aiPreferences,
        private EmailAddresses $emails,
    ) {
    }

    /**
     * @param array<string, string>|null $emailValues the email form as posted; null = the user's address
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $status = 200,
        ?ValidationErrors $passwordErrors = null,
        ?ValidationErrors $emailErrors = null,
        ?array $emailValues = null,
        ?ValidationErrors $avatarErrors = null,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);

        return $this->view->render($request, $response, 'profile/index.twig', [
            'password_errors' => $passwordErrors?->all() ?? [],
            // Email and avatar (spec.md §7.9, Phase 33.1).
            'email_values' => $emailValues ?? ['email' => $user->emailPending ?? $user->email ?? ''],
            'email_errors' => $emailErrors?->all() ?? [],
            'can_confirm_email' => $this->emails->canConfirm(),
            'avatar_errors' => $avatarErrors?->all() ?? [],
            'avatar_max_mb' => AvatarService::MAX_MB,
            // Single sign-on (spec.md §7.9 *Linking*) and whether a password is any use.
            'local_login' => $this->settings->localLogin,
            'has_password' => $user->hasPassword(),
            'sso' => $this->signInCard($user),
            // AI (spec.md §7.25): the user's switch once it is set up.
            'ai' => [
                'set_up' => $this->ai->isSetUp(),
                'on' => $this->aiPreferences->isOn($user->id),
            ],
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
