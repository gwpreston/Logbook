<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;

/**
 * `draft_expense`: a parking, toll, tax or other expense from a sentence.
 */
final class DraftExpense extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Expense;
    }

    protected function description(): string
    {
        return 'Draft an expense (parking, tolls, road tax, cleaning, accessories, fines, finance, other) for '
            . 'the user to add. Fuel, servicing and documents have their own tools.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('The day'),
            'category' => ['type' => 'string', 'description' => 'What it was for, in the user\'s words.'],
            'amount' => self::numberProperty('What it cost (0 is fine)'),
            'note' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $category = null;
        $word = $arguments->string('category');
        if ($word !== null) {
            $category = $this->resolver->category($user, DraftKind::Expense, $word)
                ?? throw new DraftQuestion($this->kit->t('ask.draft.question.category', ['words' => $word]));
        }

        return self::given([
            'spent_on' => $this->date($user, $arguments, 'date')?->format('Y-m-d'),
            'category' => $category,
            'amount' => $this->number($user, $arguments, 'amount'),
            'note' => $arguments->string('note'),
        ]);
    }
}
