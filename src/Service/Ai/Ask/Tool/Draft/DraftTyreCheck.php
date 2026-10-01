<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Ai\Draft\Resolver;
use Logbook\Service\Feature\FeatureToggles;

/**
 * `draft_tyre_check`: tread depths from a sentence, in the user's depth
 * unit unless they named one. "All at 5 mm" measures every fitted tyre.
 */
final class DraftTyreCheck extends DraftTool
{
    public function __construct(
        ToolKit $kit,
        Resolver $resolver,
        DraftWriter $writer,
        FeatureToggles $features,
        private readonly TyreService $tyres,
    ) {
        parent::__construct($kit, $resolver, $writer, $features);
    }

    public function kind(): DraftKind
    {
        return DraftKind::TyreCheck;
    }

    protected function description(): string
    {
        return 'Draft a tread depth check for the user to add: a depth for one or more fitted tyres.';
    }

    protected function properties(): array
    {
        $positions = array_map(static fn (TyrePosition $p): string => $p->value, TyrePosition::cases());

        return [
            'date' => self::dateProperty('The day of the check'),
            'odometer' => self::numberProperty('The odometer reading then'),
            'distance_unit' => self::distanceUnitProperty(),
            'depth_unit' => [
                'type' => 'string',
                'enum' => ['mm', 'in32'],
                'description' => 'Only if the user named it: millimetres, or 32nds of an inch.',
            ],
            'depths' => [
                'type' => 'object',
                'description' => 'Depth per position (fl, fr, rl, rr; front and rear on a bike; spare), as the '
                    . 'user wrote it. Use "all" for one depth for every fitted tyre.',
                'properties' => array_fill_keys([...$positions, 'all'], ['type' => 'string']),
                'additionalProperties' => false,
            ],
            'note' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $raw = $arguments->values['depths'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new ToolError($this->kit->t('ask.draft.say.depths'));
        }
        $fitted = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            if ($tyre->isFitted() && $tyre->position !== null) {
                $fitted[] = $tyre->position->value;
            }
        }
        $depths = [];
        foreach ($raw as $position => $value) {
            $depth = $this->number($user, new ToolArguments(['depth' => $value]), 'depth');
            if ($depth === null) {
                continue;
            }
            if ($position === 'all') {
                foreach ($fitted as $code) {
                    $depths[$code] ??= $depth;
                }
                continue;
            }
            $depths[(string) $position] = $depth;
        }

        return self::given([
            'checked_on' => $this->date($user, $arguments, 'date')?->format('Y-m-d'),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'depth_unit' => $arguments->choice('depth_unit', ['mm', 'in32']),
            'depths' => $depths,
            'note' => $arguments->string('note'),
        ]);
    }
}
