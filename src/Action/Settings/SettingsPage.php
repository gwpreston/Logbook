<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\Mail\MailConfig;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the settings page (spec.md §8 *Settings layout*): links to the
 * pages for reminders, driving, data, developers, admin and the
 * installation. The user's own account and preferences are on the
 * profile page (#172).
 */
final readonly class SettingsPage
{
    public function __construct(
        private View $view,
        private AppSettings $settings,
        private MailConfig $mail,
    ) {
    }

    public function render(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($request, $response, 'settings/index.twig', [
            // AI (spec.md §7.25): the admin link while AI_ENABLED.
            'ai' => ['enabled' => $this->settings->ai->enabled],
            // Delivery (spec.md §7.11): the row says whether the email server is set up.
            'mail_configured' => $this->mail->isConfigured(),
        ]);
    }
}
