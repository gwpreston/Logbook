<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use DateTimeImmutable;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Draft\DraftProposal;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolError;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Draft\DraftInvalid;
use Logbook\Service\Ai\Draft\DraftRefused;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Ai\Draft\Resolver;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Date\LocalTime;

/**
 * A draft tool (spec.md §7.26 *Drafting entries*): the model passes on
 * what the user said, Logbook resolves the vehicle, words, dates and
 * numbers, and writes the entry through the API's writer inside the
 * registry's transaction, which is always rolled back. So the draft is
 * validated, derived and warned about exactly as a save would be, and
 * nothing is left behind. An `ok` result carries the proposal the
 * registry keeps as a card; only the card's *Add* writes it.
 *
 * Results for the model: `ok`, `duplicate`, `needs`, `invalid`,
 * `choose_vehicle` and `ask_user`, each with what to say or ask.
 */
abstract class DraftTool implements AskTool
{
    /** A body key for card notes (where a date was worked out from); never sent to the writer. */
    protected const string NOTES = '__notes';
    private const string NOT_SAVED = 'Nothing is saved yet. The user sees a card with Add, Edit and Discard; '
        . 'only their press on Add saves it. Never say it is saved; tell them to check the card and press Add.';

    public function __construct(
        protected readonly ToolKit $kit,
        protected readonly Resolver $resolver,
        private readonly DraftWriter $writer,
        private readonly FeatureToggles $features,
    ) {
    }

    abstract public function kind(): DraftKind;

    abstract protected function description(): string;

    /**
     * The tool's own arguments, beside `vehicle`.
     *
     * @return array<string, mixed>
     */
    abstract protected function properties(): array;

    /**
     * The API body from the arguments, every word and date resolved.
     *
     * @return array<string, mixed>
     * @throws DraftQuestion|ToolError
     */
    abstract protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array;

    public function name(): string
    {
        return $this->kind()->toolName();
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            $this->description() . ' Pass on the user\'s own words, numbers and units; never work out amounts, '
            . 'dates or conversions yourself. Leave out what the user did not say. ' . self::NOT_SAVED,
            [
                'type' => 'object',
                'properties' => [
                    'vehicle' => ['type' => 'integer', 'description' => 'Vehicle id. Leave it out when unsure: '
                        . 'the candidates come back to ask about.'],
                    ...$this->properties(),
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        $feature = $this->kind()->feature();

        return $this->features->isEnabled(Feature::AiActions)
            && ($feature === null || $this->features->isEnabled($feature))
            && $this->resolver->candidates($user, $this->kind()) !== [];
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $kind = $this->kind();
        $label = $this->kit->t($kind->labelKey());
        $id = $arguments->int('vehicle');
        if ($id === null) {
            $candidates = $this->resolver->candidates($user, $kind);
            if (count($candidates) !== 1) {
                return $this->answer('choose_vehicle', $label, [
                    'question' => $this->kit->t('ask.draft.question.vehicle'),
                    'candidates' => array_map($this->kit->vehicleRow(...), $candidates),
                ]);
            }
            $id = $candidates[0]->id;
        }
        try {
            $vehicle = $this->writer->vehicle($user, $kind, $id);
        } catch (DraftRefused $refused) {
            throw new ToolError($this->kit->t($refused->key));
        }

        try {
            $body = $this->body($user, $vehicle, $arguments);
            $notes = $body[self::NOTES] ?? [];
            unset($body[self::NOTES]);
            $written = $this->writer->write($user, $vehicle, $kind, $body);
        } catch (DraftQuestion $question) {
            return $this->answer('ask_user', $label, ['question' => $question->getMessage(), ...$question->extra], $vehicle);
        } catch (DraftInvalid $invalid) {
            $messages = [];
            foreach ($invalid->errors->all() as $field => $error) {
                $messages[$field] = $this->kit->t($error['key'], $error['params']);
            }

            return $this->answer($invalid->onlyMissing() ? 'needs' : 'invalid', $label, [
                'fields' => $messages,
                'say' => $this->kit->t($invalid->onlyMissing() ? 'ask.draft.say.needs' : 'ask.draft.say.invalid'),
            ], $vehicle);
        } catch (DraftRefused $refused) {
            throw new ToolError($this->kit->t($refused->key));
        }

        $card = $written->card;
        $card['notes'] = [...(is_array($notes) ? $notes : []), ...(is_array($card['notes'] ?? null) ? $card['notes'] : [])];
        if ($written->duplicate) {
            return $this->answer('duplicate', $label, [
                'say' => $this->kit->t('ask.draft.say.duplicate'),
                'summary' => $written->card['summary'] ?? '',
            ], $vehicle);
        }

        return new ToolResult(
            [
                'status' => 'ok',
                'kind' => $kind->value,
                'vehicle' => $this->kit->vehicleRef($vehicle),
                'summary' => $card['summary'] ?? '',
                'fields' => $card['fields'] ?? [],
                'warnings' => $card['warnings'] ?? [],
                'notes' => $card['notes'],
                'say' => self::NOT_SAVED,
            ],
            $this->kit->source([$this->kit->t('ask.tool.draft'), $label, $vehicle->name()]),
            [],
            null,
            [$vehicle->id],
            new DraftProposal(
                $kind,
                $vehicle->id,
                $body,
                $card + ['vehicle' => $vehicle->name(), 'registration' => $vehicle->data->registration],
                $written->formValues,
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function answer(string $status, string $label, array $data, ?Vehicle $vehicle = null): ToolResult
    {
        return new ToolResult(
            ['status' => $status, 'kind' => $this->kind()->value, ...$data],
            $this->kit->source([$this->kit->t('ask.tool.draft'), $label, $vehicle?->name()]),
            [],
            null,
            $vehicle === null ? [] : [$vehicle->id],
        );
    }

    // Shared argument readers.

    /**
     * A date argument resolved by Logbook, or null when not given.
     *
     * @throws DraftQuestion
     */
    protected function date(User $user, ToolArguments $arguments, string $key): ?DateTimeImmutable
    {
        $words = $arguments->string($key);
        if ($words === null) {
            return null;
        }

        return $this->resolver->date($user, $words)
            ?? throw new DraftQuestion($this->kit->t('ask.draft.question.date', ['words' => $words]));
    }

    /**
     * An instant from a date (and optional HH:MM time) argument: today at
     * now, another day at local noon unless a time was said (spec.md §7.26).
     *
     * @throws DraftQuestion
     */
    protected function instant(User $user, ToolArguments $arguments, string $dateKey = 'date', string $timeKey = 'time'): ?string
    {
        $date = $this->date($user, $arguments, $dateKey);
        $time = $arguments->string($timeKey);
        if ($time !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw new DraftQuestion($this->kit->t('ask.draft.question.time', ['words' => $time]));
        }
        if ($date === null && $time === null) {
            return null;
        }
        $zone = $user->preferences->timeZone();
        $date ??= $this->resolver->today($user);
        if ($time === null) {
            if ($date == $this->resolver->today($user)) {
                return $this->resolver->now()->format(DATE_ATOM);
            }
            $time = '12:00';
        }
        $instant = LocalTime::toUtc($date->format('Y-m-d') . 'T' . str_pad($time, 5, '0', STR_PAD_LEFT), $zone);

        return $instant?->format(DATE_ATOM);
    }

    /**
     * A number as the user's forms read it, canonical.
     *
     * @throws DraftQuestion
     */
    protected function number(User $user, ToolArguments $arguments, string $key): ?string
    {
        $value = $arguments->string($key);
        if ($value === null) {
            return null;
        }

        return $this->resolver->number($user, $value)
            ?? throw new DraftQuestion($this->kit->t('ask.draft.question.number', ['words' => $value]));
    }

    /**
     * Only the body fields that were given.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected static function given(array $body): array
    {
        return array_filter($body, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function distanceUnitProperty(): array
    {
        return ['type' => 'string', 'enum' => ['km', 'mi'], 'description' => 'Only if the user named the unit.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function dateProperty(string $what): array
    {
        return [
            'type' => 'string',
            'description' => $what . ' as YYYY-MM-DD, or the user\'s own words ("yesterday", "last Tuesday", '
                . '"3 days ago"), which Logbook resolves. Leave out for today.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function numberProperty(string $what): array
    {
        return ['type' => 'string', 'description' => $what . ', as the user wrote it ("51,5" is fine).'];
    }
}
