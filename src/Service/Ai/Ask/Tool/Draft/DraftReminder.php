<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use DateTimeImmutable;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Ai\Draft\Resolver;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Date\LocalTime;

/**
 * `draft_reminder`: a manual reminder from a sentence. The due date is a
 * date, or a time before or after a document's expiry or a schedule's
 * next due date ("two weeks before the MOT expires"), worked out once by
 * Logbook from that source (spec.md §7.26: manual reminders have no
 * source, so the date stays put).
 */
final class DraftReminder extends DraftTool
{
    public function __construct(
        ToolKit $kit,
        Resolver $resolver,
        DraftWriter $writer,
        FeatureToggles $features,
        private readonly ComplianceService $compliance,
        private readonly ScheduleService $schedules,
        private readonly OdometerService $odometer,
    ) {
        parent::__construct($kit, $resolver, $writer, $features);
    }

    public function kind(): DraftKind
    {
        return DraftKind::Reminder;
    }

    protected function description(): string
    {
        return 'Draft a manual reminder for the user to add: a title and a due date, or a time before or after '
            . 'a document expires or a schedule is next due.';
    }

    protected function properties(): array
    {
        return [
            'title' => ['type' => 'string', 'description' => 'What to be reminded of, in the user\'s words.'],
            'due' => self::dateProperty('When it is due'),
            'relative_to_document' => ['type' => 'string', 'description' => 'A document type in the user\'s words '
                . '("MOT", "insurance"), when the due date is relative to its expiry.'],
            'relative_to_schedule' => ['type' => 'string', 'description' => 'A maintenance schedule\'s title, when '
                . 'the due date is relative to when it is next due.'],
            'offset' => ['type' => 'string', 'description' => 'How long before or after, in the user\'s words '
                . '("two weeks", "1 month").'],
            'direction' => ['type' => 'string', 'enum' => ['before', 'after']],
            'lead_time_days' => ['type' => 'integer', 'description' => 'Only if the user said how many days ahead to warn.'],
            'notes' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $due = $this->date($user, $arguments, 'due');
        $from = null;
        if ($due === null && ($arguments->has('relative_to_document') || $arguments->has('relative_to_schedule'))) {
            [$source, $from] = $this->source($user, $vehicle, $arguments);
            $due = $this->offset($user, $source, $arguments);
        }

        $body = self::given([
            'title' => $arguments->string('title'),
            'due_on' => $due?->format('Y-m-d'),
            'lead_time_days' => $arguments->int('lead_time_days', 0),
            'notes' => $arguments->string('notes'),
        ]);
        if ($from !== null) {
            $body[self::NOTES] = [$from];
        }

        return $body;
    }

    /**
     * The source's date, and what the card says it was worked out from.
     *
     * @return array{DateTimeImmutable, string}
     * @throws DraftQuestion
     */
    private function source(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $today = $this->resolver->today($user);
        $word = $arguments->string('relative_to_document');
        if ($word !== null) {
            $code = $this->resolver->category($user, DraftKind::Document, $word)
                ?? throw new DraftQuestion($this->kit->t('ask.draft.question.document_type', ['words' => $word]));
            $type = ComplianceType::from($code);
            $latest = null;
            foreach ($this->compliance->states($vehicle, $today) as $state) {
                $expiry = $state->document->data->expiryOn;
                if ($state->document->data->type === $type && $state->status->isCurrent() && $expiry !== null) {
                    $latest = $latest === null || $expiry > $latest[0] ? [$expiry, $state] : $latest;
                }
            }
            if ($latest === null) {
                throw new DraftQuestion($this->kit->t('ask.draft.question.no_document', [
                    'type' => $this->kit->t('compliance.type.' . $type->value),
                    'vehicle' => $vehicle->name(),
                ]));
            }

            return [$latest[0], $this->kit->t('ask.draft.from_document', [
                'document' => $latest[1]->document->data->title ?? $this->kit->t('compliance.type.' . $type->value),
                'date' => $this->kit->format->date($latest[0]),
            ])];
        }

        $words = mb_strtolower((string) $arguments->string('relative_to_schedule'));
        foreach ($this->schedules->states($vehicle, $today, $this->odometer->history($vehicle)) as $state) {
            if (mb_strtolower($state->schedule->data->title) === $words && $state->due->dueOn !== null) {
                return [$state->due->dueOn, $this->kit->t('ask.draft.from_schedule', [
                    'schedule' => $state->schedule->data->title,
                    'date' => $this->kit->format->date($state->due->dueOn),
                ])];
            }
        }
        throw new DraftQuestion($this->kit->t('ask.draft.question.no_schedule', ['words' => $words]));
    }

    /**
     * @throws DraftQuestion
     */
    private function offset(User $user, DateTimeImmutable $source, ToolArguments $arguments): DateTimeImmutable
    {
        $words = $arguments->string('offset');
        if ($words === null) {
            return $source;
        }
        $term = $this->resolver->term($user, $words)
            ?? throw new DraftQuestion($this->kit->t('ask.draft.question.term', ['words' => $words]));
        $sign = $arguments->choice('direction', ['before', 'after']) === 'after' ? 1 : -1;

        return LocalTime::addMonths($source, $sign * $term['months'])->modify(sprintf('%+d days', $sign * $term['days']));
    }
}
