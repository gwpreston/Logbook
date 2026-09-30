<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
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
        if ($attachment->ownerType !== AttachmentOwner::Trip) {
            return;
        }
        if (!$this->features->isEnabled(Feature::Trips)) {
            throw new HttpNotFoundException($request);
        }
        try {
            $this->trips->get(RequestContext::requireUser($request), RequestContext::vehicle($request), $attachment->ownerId);
        } catch (TripNotFound) {
            throw new HttpNotFoundException($request);
        }
    }
}
