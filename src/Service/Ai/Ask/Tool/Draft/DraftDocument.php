<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Support\Date\LocalTime;

/**
 * `draft_document`: insurance, an MOT, registration or another document
 * from a sentence. An expiry given as a term ("renewed for a year from
 * today") is worked out by Logbook: the start plus the term, less a day.
 */
final class DraftDocument extends DraftTool
{
    public function kind(): DraftKind
    {
        return DraftKind::Document;
    }

    protected function description(): string
    {
        return 'Draft a document (insurance, MOT or other inspection, registration, pollution certificate, other) '
            . 'for the user to add.';
    }

    protected function properties(): array
    {
        return [
            'type' => ['type' => 'string', 'description' => 'What it is, in the user\'s words ("insurance", "MOT").'],
            'title' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'description' => 'Insurer, test centre or issuer.'],
            'reference' => ['type' => 'string', 'description' => 'Policy or certificate number.'],
            'start' => self::dateProperty('When it starts'),
            'expiry' => self::dateProperty('When it expires'),
            'term' => ['type' => 'string', 'description' => 'How long it runs, in the user\'s words ("a year", '
                . '"6 months"), when they gave a term rather than an expiry.'],
            'cost' => self::numberProperty('What it cost'),
            'odometer' => self::numberProperty('The odometer reading on the certificate'),
            'distance_unit' => self::distanceUnitProperty(),
            'notes' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $type = null;
        $word = $arguments->string('type');
        if ($word !== null) {
            $type = $this->resolver->category($user, DraftKind::Document, $word)
                ?? throw new DraftQuestion($this->kit->t('ask.draft.question.document_type', ['words' => $word]));
        }
        $start = $this->date($user, $arguments, 'start');
        $expiry = $this->date($user, $arguments, 'expiry');
        $termWords = $arguments->string('term');
        if ($expiry === null && $termWords !== null) {
            $term = $this->resolver->term($user, $termWords)
                ?? throw new DraftQuestion($this->kit->t('ask.draft.question.term', ['words' => $termWords]));
            $start ??= $this->resolver->today($user);
            $expiry = LocalTime::addMonths($start, $term['months'])->modify(sprintf('+%d days', $term['days'] - 1));
        }

        return self::given([
            'type' => $type,
            'title' => $arguments->string('title'),
            'provider' => $arguments->string('provider'),
            'reference' => $arguments->string('reference'),
            'start_on' => $start?->format('Y-m-d'),
            'expiry_on' => $expiry?->format('Y-m-d'),
            'cost' => $this->number($user, $arguments, 'cost'),
            'odometer' => $this->number($user, $arguments, 'odometer'),
            'distance_unit' => $arguments->choice('distance_unit', ['km', 'mi']),
            'notes' => $arguments->string('notes'),
        ]);
    }
}
