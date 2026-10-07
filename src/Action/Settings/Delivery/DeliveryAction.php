<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Delivery;

use Logbook\Domain\Notification\MemberDestinations;
use Logbook\Service\Mail\EmailServerAdmin;
use Logbook\Service\Mail\EmailServerForm;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\MailTestResult;
use Logbook\Service\Notification\ChannelVariables;
use Logbook\Service\Notification\Outbound\OutboundDestination;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GET|POST /settings/delivery — Settings → Delivery (spec.md §7.11 *The
 * email server*), admins only: the email server's fields, its password
 * (never shown back, not even after an error), *Save*, and *Send test
 * email*, which sends with what was typed without saving it. From Phase
 * 36.2 also *Where members can send* (`intent=destinations`) and the
 * notices about the old channel variables.
 */
final readonly class DeliveryAction
{
    public function __construct(
        private MailConfig $config,
        private EmailServerAdmin $admin,
        private View $view,
        private Redirector $redirect,
        private OutboundDestination $destinations,
        private ChannelVariables $variables,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, EmailServerForm::values($this->config->effective()));
        }

        $input = RequestContext::form($request);
        if (($input['intent'] ?? null) === 'destinations') {
            $policy = MemberDestinations::tryFrom(is_string($input['destinations'] ?? null) ? $input['destinations'] : '');
            if ($policy !== null) {
                $this->destinations->savePolicy($policy);
                $this->logger->notice('Where members can send changed by user {user}: {policy}.', [
                    'user' => $user->id,
                    'policy' => $policy->value,
                ]);
                RequestContext::session($request)->flash('success', 'delivery.destinations.saved');
            }

            return $this->redirect->toRoute('settings.delivery');
        }
        $form = EmailServerForm::parse($input);
        $errors = $form->errors;
        if ($form->password !== null && !isset($errors['password']) && !$this->admin->canStore($form->password)) {
            $errors['password'] = 'delivery.email.error.password_store';
        }
        $testing = ($input['intent'] ?? null) === 'test';
        $testTo = $user->email;
        if ($testing && $testTo === null) {
            $testTo = is_string($input['test_to'] ?? null) ? trim($input['test_to']) : '';
            if (!EmailServerForm::validAddress($testTo)) {
                $errors['test_to'] = 'delivery.email.error.test_to';
            }
        }

        if ($errors !== [] || $form->server === null) {
            return $this->render($request, $response, $form->values, $errors, $form->warnings, null, 422, $input);
        }

        if ($testing) {
            assert(is_string($testTo));
            $result = $this->admin->test($form->server, $form->password, $form->removePassword, $testTo, $user);

            return $this->render($request, $response, $form->values, [], $form->warnings, $result, 200, $input);
        }

        $this->admin->save($form->server, $form->password, $form->removePassword, $user);
        $session = RequestContext::session($request);
        $session->flash('success', 'delivery.email.saved');
        foreach ($form->warnings as $warning) {
            $session->flash('warning', $warning);
        }

        return $this->redirect->toRoute('settings.delivery');
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors translation keys by field
     * @param list<string> $warnings
     * @param array<mixed> $input
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        array $errors = [],
        array $warnings = [],
        ?MailTestResult $test = null,
        int $status = 200,
        array $input = [],
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);

        return $this->view->render($request, $response, 'settings/delivery/index.twig', [
            'server' => $this->config->effective(),
            'values' => $values + ['test_to' => is_string($input['test_to'] ?? null) ? trim($input['test_to']) : ''],
            'errors' => array_map(static fn (string $key): array => ['key' => $key, 'params' => []], $errors),
            'warnings' => $warnings,
            'remove_password' => ($input['remove_password'] ?? null) === '1',
            'password' => $this->admin->passwordState(),
            'can_seal' => $this->admin->canSeal(),
            'old_variables' => $this->admin->oldVariables(),
            'encryptions' => MailEncryption::cases(),
            'test' => $test,
            'test_to' => $user->email,
            'destinations' => $this->destinations->policy(),
            'destination_choices' => MemberDestinations::cases(),
            'channel_variables' => $this->variables->removedButSet(),
            'webhook_variable' => $this->variables->webhookSet(),
        ], $status);
    }
}
