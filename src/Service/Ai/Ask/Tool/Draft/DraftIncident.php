<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool\Draft;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Ai\Draft\Resolver;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentService;

/**
 * `draft_incident` (Phase 27.1, spec.md §7.26, §7.29): an incident card for
 * the user's Add. Type, fault, damage areas and claim status are codes the
 * model picks from the schema's lists; the date is resolved by Logbook and
 * never in the future; the insurer is the policy current on the date unless
 * the user named one.
 */
final class DraftIncident extends DraftTool
{
    public function __construct(
        ToolKit $kit,
        Resolver $resolver,
        DraftWriter $writer,
        FeatureToggles $features,
        private readonly IncidentService $incidents,
    ) {
        parent::__construct($kit, $resolver, $writer, $features);
    }

    public function kind(): DraftKind
    {
        return DraftKind::Incident;
    }

    protected function description(): string
    {
        return 'Draft an incident (' . self::typeList() . ') for the user to add: what happened, where the '
            . 'vehicle was damaged and anything about the insurance claim. Repairs are separate records the user '
            . 'links afterwards.';
    }

    protected function properties(): array
    {
        return [
            'date' => self::dateProperty('When it happened'),
            'time' => ['type' => 'string', 'description' => 'The time of day as HH:MM, only if the user said it.'],
            'type' => ['type' => 'string', 'enum' => self::types()],
            'fault' => [
                'type' => 'string',
                'enum' => self::faults(),
                'description' => 'Only if the user said whose fault it was.',
            ],
            'damage_areas' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::areas()],
            ],
            'location' => ['type' => 'string', 'description' => 'Where it happened, in the user\'s words.'],
            'description' => ['type' => 'string'],
            'claim_status' => [
                'type' => 'string',
                'enum' => self::claims(),
                'description' => 'Only if the user said they claimed or told the insurer.',
            ],
            'insurer' => ['type' => 'string', 'description' => 'Only if the user named the insurer.'],
            'claim_number' => ['type' => 'string'],
            'police_reference' => ['type' => 'string'],
        ];
    }

    protected function body(User $user, Vehicle $vehicle, ToolArguments $arguments): array
    {
        $date = $this->date($user, $arguments, 'date') ?? $this->resolver->today($user);
        $insurer = $arguments->string('insurer');
        $policy = $insurer === null ? $this->incidents->policyOn($vehicle, $date) : null;
        $areas = $arguments->values['damage_areas'] ?? null;

        return self::given([
            'occurred_on' => $date->format('Y-m-d'),
            'occurred_at_time' => $arguments->string('time'),
            'type' => $arguments->choice('type', self::types()),
            'fault' => $arguments->choice('fault', self::faults()),
            'damage_areas' => is_array($areas) ? array_values(array_filter($areas, is_string(...))) : null,
            'location' => $arguments->string('location'),
            'description' => $arguments->string('description'),
            'claim_status' => $arguments->choice('claim_status', self::claims()),
            'insurer' => $insurer ?? $policy?->data->provider,
            'insurance_document_id' => $policy === null ? null : (string) $policy->id,
            'claim_number' => $arguments->string('claim_number'),
            'police_reference' => $arguments->string('police_reference'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function types(): array
    {
        return array_map(static fn (IncidentType $type): string => $type->value, IncidentType::cases());
    }

    /**
     * The types in words, from the enum so a new one is never left out:
     * "a collision, parked damage, …, fire, breakdown or other".
     */
    private static function typeList(): string
    {
        $words = array_map(static fn (string $type): string => str_replace('_', ' ', $type), self::types());
        $last = array_pop($words);

        return 'a ' . implode(', ', $words) . ' or ' . $last;
    }

    /**
     * @return list<string>
     */
    private static function areas(): array
    {
        return array_map(static fn (DamageArea $area): string => $area->value, DamageArea::cases());
    }

    /**
     * @return list<string>
     */
    private static function faults(): array
    {
        return array_map(static fn (Fault $fault): string => $fault->value, Fault::cases());
    }

    /**
     * @return list<string>
     */
    private static function claims(): array
    {
        return array_map(static fn (ClaimStatus $status): string => $status->value, ClaimStatus::cases());
    }
}
