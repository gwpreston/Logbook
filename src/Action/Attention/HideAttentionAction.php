<?php

declare(strict_types=1);

namespace Logbook\Action\Attention;

use Logbook\Service\Attention\AttentionHiding;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/{id}/attention/hide — *Hide* on a *Needs attention* data
 * check (spec.md §7.24): `kind`, `subject` and the `fingerprint` the page
 * showed. Hidden only when the check is still shown to this user with that
 * fingerprint; otherwise nothing is stored and the page says it changed.
 * Returns to where it came from (`return_to`), else the overview.
 */
final readonly class HideAttentionAction
{
    public function __construct(
        private AttentionHiding $hiding,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $form = RequestContext::form($request);
        $kind = is_string($form['kind'] ?? null) ? $form['kind'] : '';
        $subject = is_string($form['subject'] ?? null) && ctype_digit($form['subject']) ? (int) $form['subject'] : 0;
        $fingerprint = is_string($form['fingerprint'] ?? null) ? $form['fingerprint'] : '';

        $hidden = $this->hiding->hide($user, $vehicle, $kind, $subject, $fingerprint);
        RequestContext::session($request)->flash(
            $hidden ? 'success' : 'warning',
            $hidden ? 'attention.hidden' : 'attention.changed',
        );

        return $this->redirect->backOr($request, 'vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
