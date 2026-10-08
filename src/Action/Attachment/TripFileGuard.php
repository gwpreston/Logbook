<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * A trip's file is where someone went (a parking or toll receipt): it is
 * served and deleted only for those who may see the trip, and not at all
 * while the trips module is off (spec.md §7.10, §7.22). Other files pass.
 */
final readonly class TripFileGuard
{
    public function __construct(
        private TripService $trips,
        private FeatureToggles $features,
    ) {
    }

    public function allow(ServerRequestInterface $request, Attachment $attachment): void
    {
        if (!$this->mayUse(RequestContext::requireUser($request), RequestContext::vehicle($request), $attachment)) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * The same rule for a caller that has the user and vehicle in hand (the
     * API's `/attachments/{id}`, spec.md §7.20).
     */
    public function mayUse(User $user, Vehicle $vehicle, Attachment $attachment): bool
    {
        if ($attachment->ownerType !== AttachmentOwner::Trip) {
            return true;
        }
        if (!$this->features->isEnabled(Feature::Trips)) {
            return false;
        }
        try {
            $this->trips->get($user, $vehicle, $attachment->ownerId);
        } catch (TripNotFound) {
            return false;
        }

        return true;
    }
}
