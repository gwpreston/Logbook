<?php

declare(strict_types=1);

namespace Logbook\Action\Auth;

use Logbook\Service\Auth\PasswordResets;
use Logbook\Support\Clock\Sleeper;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /forgot-password (spec.md §7.9 *Forgotten password*): asks for
 * a username or email address, and answers every POST with the same page,
 * whatever was typed. The answer is padded to a fixed time and its email,
 * if any, goes after the response, so neither the page nor its timing
 * shows whether an account matched. 404 unless email is set up, password
 * sign-in is on and PASSWORD_RESET_ENABLED is not false.
 */
final readonly class ForgotPasswordAction
{
    /** Every answer takes at least this long (spec.md §7.9 *Equal timing*). */
    public const float FLOOR_SECONDS = 1.5;
    private const int MAX_LENGTH = 254;

    public function __construct(
        private PasswordResets $resets,
        private View $view,
        private ClockInterface $clock,
        private Sleeper $sleeper,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->resets->isAvailable()) {
            throw new HttpNotFoundException($request);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'auth/forgot_password.twig', ['sent' => false, 'typed' => '']);
        }

        $started = (float) $this->clock->now()->format('U.u');
        $input = RequestContext::form($request);
        $typed = is_string($input['login'] ?? null) ? mb_substr(trim($input['login']), 0, self::MAX_LENGTH) : '';
        if ($typed !== '') {
            $this->resets->request($typed, RequestContext::clientAddress($request));
        }

        $answer = $this->view->render($request, $response, 'auth/forgot_password.twig', ['sent' => true, 'typed' => $typed])
            ->withHeader('Cache-Control', 'no-store');
        $elapsed = (float) $this->clock->now()->format('U.u') - $started;
        $this->sleeper->sleep(max(0.0, self::FLOOR_SECONDS - $elapsed));

        return $answer;
    }
}
