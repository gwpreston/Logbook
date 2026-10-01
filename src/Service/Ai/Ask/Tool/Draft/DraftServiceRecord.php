<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_service_record`: maintenance work from a sentence. The category
 * word is matched to Logbook's categories; schedules the work may
 * complete are suggested on the card, never ticked.
 */
final class DraftServiceRecord extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Maintenance;
    }

    protected function description(): string
    {
        return 'Draft a service record (maintenance or repair work) for the user to add.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('The day of the work'),
            'odometer' => self::numberProperty('The odometer reading then'),
            'distance_unit' => self::distanceUnitProperty(),
            'category' => ['type' => 'string', 'description' => 'The kind of work in the user\'s words ("service", '
                . '"oil change", "brakes", "new battery").'],
            'title' => ['type' => 'string', 'description' => 'A short title in the user\'s words.'],
            'cost' => self::numberProperty('What it cost'),
            'vendor' => ['type' => 'string', 'description' => 'The garage.'],
            'description' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $date = $this->date($user, $arguments, 'date');
        $category = null;
        $word = $arguments->string('category');
        if ($word !== null) {
            $category = $this->resolver->category($user, DraftKind::Maintenance, $word)
                ?? throw new DraftQuestion($this->kit->t('ask.draft.question.category', ['words' => $word]));
        }

        return self::given([
            'performed_on' => $date?->format('Y-m-d'),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'category' => $category,
            'title' => $arguments->string('title'),
            'cost' => $this->number($user, $arguments, 'cost'),
            'vendor' => $arguments->string('vendor'),
            'description' => $arguments->string('description'),
        ]);
    }
}
