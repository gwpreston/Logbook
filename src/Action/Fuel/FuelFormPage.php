<?php

declare(strict_types=1);

namespace Logbook\Action\Fuel;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Fuel\FuelPicker;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\FuelPrices\ComparisonWording;
use Logbook\Service\FuelPrices\FillUpComparisons;
use Logbook\Service\FuelPrices\ListedHint;
use Logbook\Service\Station\StationHint;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the log/edit fill-up form (shared by both Actions).
 */
final readonly class FuelFormPage
{
    public function __construct(
        private View $view,
        private OdometerService $odometer,
        private FuelService $fuel,
        private AttachmentUpload $upload,
        private ClockInterface $clock,
        private StationService $stations,
        private StationHint $hints,
        private ListedHint $listed,
        private FillUpComparisons $comparisons,
        private ComparisonWording $wording,
        private EntryAccess $access,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        string $currency,
        array $values,
        ?FuelEntry $entry = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $picker = FuelPicker::groups(
            $vehicle,
            $this->fuel->entries($vehicle),
            RequestContext::requireUser($request)->preferences->locale,
            $this->clock->now(),
            $values['fuel'] ?? '',
        );

        // The economy check of the segment this fill-up closes, shown above the form.
        $check = $entry === null ? null : $this->fuel->checks($this->fuel->history($vehicle))->for($entry->id);

        return $this->view->render($request, $response, 'fuel/form.twig', $this->stationContext($request, $values) + [
            'vehicle' => $vehicle,
            'entry' => $entry,
            'check' => $check !== null && ($check->isFlagged() || $check->isConfirmedFlag()) ? $check : null,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'fuel_groups' => $picker,
            'latest' => $this->odometer->history($vehicle)->latest(),
            // Phase 30.2: how this fill-up compared with the usual station (spec.md §7.34).
            'comparison' => $this->comparison($request, $vehicle, $entry),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Fuel, $entry?->id), $status);
    }

    private function comparison(ServerRequestInterface $request, Vehicle $vehicle, ?FuelEntry $entry): ?string
    {
        $user = RequestContext::requireUser($request);
        if ($entry === null || !$this->access->canSeeAmount($user, $vehicle, $entry->createdBy)) {
            return null;
        }
        $comparison = $this->comparisons->forEntry($user, $vehicle, $entry);

        return $comparison === null ? null : $this->wording->sentence($comparison);
    }

    /**
     * The station field (spec.md §7.33 *Fill-up form*): with the module on,
     * the favourites and recent stations for the select (plus the chosen
     * one), and the "Last time here" hint of the chosen one.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function stationContext(ServerRequestInterface $request, array $values): array
    {
        if (!$this->stations->enabled()) {
            return ['stations_on' => false];
        }
        $user = RequestContext::requireUser($request);
        $choices = $this->stations->choices($user, '', 50);
        $chosenId = ctype_digit($values['station_id'] ?? '') ? (int) $values['station_id'] : null;
        $chosen = $chosenId === null ? null : $this->stations->resolve($chosenId);
        $listed = array_map(static fn (StationListing $row): int => $row->station->id, $choices);

        return [
            'stations_on' => true,
            'station_favourites' => array_values(array_filter(
                $choices,
                static fn (StationListing $row): bool => $row->favourite,
            )),
            'station_recent' => array_values(array_filter($choices, static fn (StationListing $row): bool => !$row->favourite)),
            'station_chosen' => $chosen,
            'station_chosen_listed' => $chosen !== null && in_array($chosen->id, $listed, true),
            'station_hint' => $chosen === null ? null : $this->hints->forStation($user, $chosen->id),
            // Phase 30.2: the listed price per grade at a linked station (spec.md §7.34).
            'station_listed' => $chosen === null ? [] : ($this->listed->forStations($user, [$chosen])[$chosen->id] ?? []),
        ];
    }
}
