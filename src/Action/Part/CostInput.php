<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A part's price, a modification's cost and a sale price need `ViewCosts`
 * (spec.md §7.39 *Access*): the forms don't offer them without it, and
 * whatever a member without it sends anyway is ignored. A new record gets
 * none, an edit keeps the stored one, and a sale is recorded without a
 * price.
 */
final readonly class CostInput
{
    public function __construct(private VehicleAccess $access)
    {
    }

    /**
     * The posted form with its cost fields taken from $stored when the user
     * may not see costs.
     *
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $stored the form values of what is saved (the edit form's)
     * @param bool $withCost whether `cost` is the modification's (not a service record's own cost,
     *                       which follows the own-entry rule)
     * @return array<array-key, mixed>
     */
    public function guard(
        ServerRequestInterface $request,
        Vehicle $vehicle,
        array $input,
        array $stored = [],
        bool $withCost = true,
    ): array {
        $user = RequestContext::requireUser($request);
        if ($this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            return $input;
        }
        foreach (array_keys($input) as $key) {
            if (self::isCost((string) $key, $withCost)) {
                unset($input[$key]);
            }
        }
        foreach ($stored as $key => $value) {
            $key = (string) $key;
            if (!self::isCost($key, $withCost)) {
                continue;
            }
            // A stored sale price stays only while the part is still marked sold.
            $outcome = substr($key, 0, -strlen('sold_price')) . 'outcome';
            if (str_ends_with($key, 'sold_price') && ($input[$outcome] ?? null) !== 'sold') {
                continue;
            }
            $input[$key] = $value;
        }

        return $input;
    }

    private static function isCost(string $key, bool $withCost): bool
    {
        return preg_match('/(^|_)price$/', $key) === 1 || ($withCost && $key === 'cost');
    }
}
