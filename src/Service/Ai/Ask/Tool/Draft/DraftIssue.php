<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_issue` (Phase 40.2, spec.md §7.26, §7.37): a fault the user has
 * noticed, as an issue card for their Add. The title and description are
 * the user's own words; Logbook never adds a cause, and *Affects safety* is
 * only ticked when the user says so. The date is resolved by Logbook and
 * never in the future.
 */
final class DraftIssue extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Issue;
    }

    protected function description(): string
    {
        return 'Draft an issue for the user to add: a fault they have noticed and not fixed yet (a noise, a leak, '
            . 'a warning light, an advisory), in their own words. Never add a cause or a diagnosis.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('When the user noticed it'),
            'title' => ['type' => 'string', 'description' => 'What the user noticed, in their words, up to 120 characters.'],
            'description' => ['type' => 'string', 'description' => 'More of the user\'s own words, if they gave any.'],
            'odometer' => self::numberProperty('The odometer reading then'),
            'distance_unit' => self::distanceUnitProperty(),
            'category' => ['type' => 'string', 'description' => 'The kind of work it may need, only if the user named '
                . 'it ("brakes", "tyres").'],
            'watching' => ['type' => 'boolean', 'description' => 'True only if the user said they will keep an eye on it.'],
            'look_again_on' => ['type' => 'string', 'description' => 'When to look at it again as YYYY-MM-DD, only if '
                . 'the user is watching it and said when.'],
            'affects_safety' => ['type' => 'boolean', 'description' => 'True only if the user said it affects safety.'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $date = $this->date($user, $arguments, 'date') ?? $this->resolver->today($user);
        $word = $arguments->string('category');
        $watching = ($arguments->values['watching'] ?? false) === true;
        $lookAgain = $watching ? $this->date($user, $arguments, 'look_again_on') : null;

        return self::given([
            'noticed_on' => $date->format('Y-m-d'),
            'title' => $arguments->string('title'),
            'description' => $arguments->string('description'),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            // A category is optional on an issue: a word that matches none is left out, not asked about.
            'category' => $word === null ? null : $this->resolver->category($user, DraftKind::Maintenance, $word),
            'status' => $watching ? 'watching' : null,
            'look_again_on' => $lookAgain?->format('Y-m-d'),
            'affects_safety' => ($arguments->values['affects_safety'] ?? false) === true ? true : null,
        ]);
    }
}
