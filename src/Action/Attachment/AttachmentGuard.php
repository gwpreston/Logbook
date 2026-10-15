<?php

declare(strict_types=1);

namespace Logbook\Action\Attachment;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Trip\TripNotFound;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Who may have a file at all, beyond `View` on its vehicle, for the pages'
 * and the API's download and delete (spec.md §7.12, §7.20 *Attachments*):
 *
 * - A trip's file is where someone went (a parking or toll receipt): only
 *   for those who may see the trip, and not at all while the trips module
 *   is off (§7.10, §7.22).
 * - An expense's receipt, a valuation's quote and the purchase and sale
 *   paperwork show an amount: only with *Can see costs*, or to whoever
 *   uploaded it (decided 2026-10-08, #303, #305), as the lists and cards
 *   they appear in.
 * - A modification's or part's files (Phase 50.1, spec.md §7.39) carry
 *   invoices, receipts and prices too, and a converted expense's receipt
 *   moves to its modification: the same rule. While the `parts` module is
 *   off they are not served at all, as its pages aren't.
 *
 * Other files pass.
 */
final readonly class AttachmentGuard
{
    /** Files that show an amount: an expense's receipt, a valuation's quote, the purchase and sale paperwork (#303, #305). */
    private const array COSTS = [
        AttachmentOwner::Expense,
        AttachmentOwner::Valuation,
        AttachmentOwner::Purchase,
        AttachmentOwner::Sale,
        AttachmentOwner::Modification,
        AttachmentOwner::Part,
    ];

    public function __construct(
        private TripService $trips,
        private FeatureToggles $features,
        private EntryAccess $entries,
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
        $parts = [AttachmentOwner::Modification, AttachmentOwner::Part];
        if (in_array($attachment->ownerType, $parts, true) && !$this->features->isEnabled(Feature::Parts)) {
            return false;
        }
        if (in_array($attachment->ownerType, self::COSTS, true)) {
            return $this->entries->canSeeAmount($user, $vehicle, $attachment->uploadedBy);
        }
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
