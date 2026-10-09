<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotReview;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\Serializer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET /api/v1/vehicles/{id}/mot-tests (spec.md §7.20, §7.38): the stored
 * MOT tests, newest first, each with its defects, what was made from them,
 * the recall state as Logbook's codes and the provider's attribution. A 404
 * while MOT history is off. Never fetches.
 */
final readonly class ApiMotTests
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotTestRepository $tests,
        private MotReview $review,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $provider = $this->config->provider() ?? throw ApiProblem::notFound('MOT history is off on this install.');
        $state = $this->tests->state($vehicle->id);
        $tests = $this->tests->listForVehicle($vehicle->id);
        $documents = $this->review->documentsFor($vehicle, $tests);
        $licence = $provider->licence();

        return [
            'vehicle_id' => $vehicle->id,
            'enabled' => $state->enabled(),
            'fetched_at' => Serializer::instant($state->fetchedAt),
            'recall' => $state->recall?->value,
            'first_due_on' => Serializer::date($state->firstDueOn),
            'provider' => [
                'code' => $provider->code(),
                'name' => $this->translator->trans($provider->nameKey()),
                'attribution' => trim($this->translator->trans($licence->attributionKey) . ' ' . $licence->name . '.'),
                'licence_url' => $licence->url,
            ],
            'distance_unit' => Serializer::DISTANCE_UNIT,
            'items' => array_map(
                static fn (MotTest $test): array => self::test($test, $documents[$test->id] ?? null),
                $tests,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function test(MotTest $test, ?int $documentId): array
    {
        return [
            'id' => $test->id,
            'test_number' => $test->reference(),
            'completed_at' => Serializer::instant($test->completedAt),
            'result' => $test->result->value,
            'expires_on' => Serializer::date($test->expiryOn),
            'odometer' => Serializer::dec($test->odometerKm, Serializer::QUANTITY_SCALE),
            'odometer_state' => $test->odometerState->value,
            'tested_in' => $test->odometerUnit?->value,
            'registration_at_test' => $test->registrationAtTest,
            'data_source' => $test->source->value,
            'reviewed' => $test->reviewedAt !== null,
            'document_id' => $documentId,
            'defects' => array_map(static fn (MotDefect $defect): array => [
                'type' => $defect->type->value,
                'text' => $defect->text,
                'dangerous' => $defect->dangerous,
                'issue_id' => $defect->issueId,
                'not_now' => $defect->dismissedAt !== null,
            ], $test->defects),
        ];
    }
}
