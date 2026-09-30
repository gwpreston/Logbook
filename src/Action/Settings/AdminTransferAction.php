<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Repository\VehicleRepository;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /settings/users/{member}/vehicles/{vehicle}/transfer — an admin hands
 * a vehicle of the user being deleted to someone else (spec.md §7.9), so a
 * user who cannot sign in any more can still be removed. The vehicle must
 * be that user's; everything stays with it, as in the owner's transfer.
 */
final readonly class AdminTransferAction
{
    public function __construct(
        private UserAdmin $admin,
        private VehicleRepository $vehicles,
        private SharingService $sharing,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->admin->find((int) ($args['member'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $vehicle = $this->vehicles->findById((int) ($args['vehicle'] ?? 0));
        if ($vehicle === null || $vehicle->userId !== $user->id) {
            throw new HttpNotFoundException($request);
        }

        $username = RequestContext::form($request)['username'] ?? '';
        $username = is_string($username) ? trim($username) : '';
        $refusal = $username === '' ? null : $this->sharing->transfer($vehicle, $username, false);
        $session = RequestContext::session($request);
        if ($username === '' || $refusal !== null) {
            $key = $refusal === null ? 'sharing.refused.username_required' : 'sharing.refused.' . $refusal->value;
            $session->flash('error', $key);
        } else {
            $params = ['name' => $vehicle->name(), 'username' => mb_strtolower($username)];
            $session->flash('success', 'sharing.transferred', $params);
        }

        return $this->redirect->toRoute('settings.users.delete', ['member' => (string) $user->id]);
    }
}
