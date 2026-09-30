<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a vehicle's Sharing page (spec.md §7.21): for the owner the
 * shares and the add form, for a shared user their own share.
 */
final readonly class SharingPage
{
    public function __construct(
        private SharingService $sharing,
        private VehicleAccess $access,
        private UserDirectory $directory,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $values
     * @param array<string, array{key: string, params: array<string, string>}> $errors
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        array $values = [],
        array $errors = [],
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $owns = $this->access->can($user, VehicleAbility::Own, $vehicle);

        return $this->view->render($request, $response, 'vehicles/sharing.twig', [
            'vehicle' => $vehicle,
            'owns' => $owns,
            'owner' => $this->directory->find($vehicle->userId),
            'shares' => $owns ? $this->sharing->sharesOf($vehicle) : [],
            'mine' => $owns ? null : $this->sharing->shareOf($user, $vehicle),
            'levels' => ShareLevel::cases(),
            'values' => $values + ['level' => ShareLevel::Log->value],
            'errors' => $errors,
        ], $status);
    }
}
