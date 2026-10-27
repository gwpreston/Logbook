<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * *Declared on* records what the owner told the insurer, so only `Manage`
 * sets or clears it (spec.md §7.39, K3, #485). The modification forms don't
 * offer it; a `declared_on` field posted by anyone without `Manage` is
 * dropped, so a new record has none and an edit keeps the stored date.
 */
final readonly class DeclaredInput
{
    public function __construct(private VehicleAccess $access)
    {
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public function guard(ServerRequestInterface $request, Vehicle $vehicle, array $input): array
    {
        $user = RequestContext::requireUser($request);
        if (!$this->access->can($user, VehicleAbility::Manage, $vehicle)) {
            unset($input['declared_on']);
        }

        return $input;
    }
}
