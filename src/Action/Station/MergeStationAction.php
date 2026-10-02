<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Service\Station\StationListing;
use Logbook\Service\Station\StationService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * GET/POST /stations/{station}/merge?with={other} — merge two stations
 * (spec.md §7.33 *Merge*): choose the one to keep and, where both have a
 * detail, whose to keep. Every fill-up and favourite moves to the kept
 * one; the other points at it so old links still resolve. The creator of
 * both stations, or an admin.
 */
final readonly class MergeStationAction
{
    /** The details a merge chooses between (position as one). */
    public const array FIELDS = ['name', 'brand', 'address', 'postcode', 'country', 'position', 'opening_hours', 'notes'];

    public function __construct(
        private StationService $stations,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $station = StationRoute::active($this->stations, $request, $args);
        if (!$this->stations->canEdit($user, $station)) {
            throw new HttpForbiddenException($request);
        }

        $input = $request->getMethod() === 'POST' ? RequestContext::form($request) : $request->getQueryParams();
        $with = $input['with'] ?? '';
        $other = is_string($with) && ctype_digit($with) ? $this->stations->find((int) $with) : null;
        if ($other === null || $other->isMerged() || $other->id === $station->id) {
            return $this->view->render($request, $response, 'stations/merge_pick.twig', [
                'station' => $station,
                'suggested' => $this->suggestions($station),
                'others' => array_values(array_filter(
                    $this->stations->listing($user),
                    fn (StationListing $row): bool => $row->station->id !== $station->id
                        && $this->stations->canEdit($user, $row->station),
                )),
            ]);
        }
        if (!$this->stations->canEdit($user, $other)) {
            throw new HttpForbiddenException($request);
        }

        $keepOther = ($input['keep'] ?? '') === 'other';
        [$keep, $away] = $keepOther ? [$other, $station] : [$station, $other];

        if ($request->getMethod() !== 'POST' || ($input['confirm'] ?? '') !== '1') {
            return $this->view->render($request, $response, 'stations/merge.twig', [
                'station' => $station,
                'other' => $other,
                'keep' => $keep,
                'away' => $away,
                'keep_other' => $keepOther,
                'fields' => self::FIELDS,
                'choices' => self::defaults($keep, $away),
                'differs' => self::differs($keep, $away),
                'keep_values' => self::displayed($keep),
                'away_values' => self::displayed($away),
            ]);
        }

        $data = self::chosen($keep, $away, $input);
        $this->stations->merge($keep, $away, $data);
        RequestContext::session($request)->flash('success', 'stations.merged');

        return $this->redirect->toRoute('stations.show', ['station' => (string) $keep->id]);
    }

    /**
     * @return list<Station> stations the duplicates view pairs with this one
     */
    private function suggestions(Station $station): array
    {
        $found = [];
        foreach ($this->stations->duplicates() as $pair) {
            if ($pair->first->id === $station->id) {
                $found[] = $pair->second;
            } elseif ($pair->second->id === $station->id) {
                $found[] = $pair->first;
            }
        }

        return $found;
    }

    /**
     * Whose detail each field starts with: the kept station's when it has
     * one, else the other's.
     *
     * @return array<string, string> field → `keep` or `away`
     */
    public static function defaults(Station $keep, Station $away): array
    {
        $choices = [];
        foreach (self::FIELDS as $field) {
            $choices[$field] = self::value($keep, $field) === null && self::value($away, $field) !== null ? 'away' : 'keep';
        }

        return $choices;
    }

    /**
     * @return array<string, bool> the fields both stations have, with different values
     */
    public static function differs(Station $keep, Station $away): array
    {
        $differs = [];
        foreach (self::FIELDS as $field) {
            $a = self::value($keep, $field);
            $b = self::value($away, $field);
            $differs[$field] = $a !== null && $b !== null && $a !== $b;
        }

        return $differs;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function chosen(Station $keep, Station $away, array $input): StationData
    {
        $base = StationService::mergedData($keep, $away);
        $defaults = self::defaults($keep, $away);
        $pick = static function (string $field) use ($input, $defaults, $keep, $away): Station {
            $choice = $input['field_' . $field] ?? $defaults[$field];

            return $choice === 'away' ? $away : $keep;
        };
        $position = $pick('position')->data;
        if (!$position->hasPosition()) {
            $position = $base;
        }

        return new StationData(
            name: $pick('name')->data->name,
            brand: $pick('brand')->data->brand ?? $base->brand,
            address: $pick('address')->data->address ?? $base->address,
            postcode: $pick('postcode')->data->postcode ?? $base->postcode,
            country: $pick('country')->data->country ?? $base->country,
            latitude: $position->latitude,
            longitude: $position->longitude,
            grades: $base->grades,
            openingHours: $pick('opening_hours')->data->openingHours ?? $base->openingHours,
            notes: $pick('notes')->data->notes ?? $base->notes,
        );
    }

    private static function value(Station $station, string $field): ?string
    {
        $data = $station->data;

        return match ($field) {
            'name' => $data->name,
            'brand' => $data->brand,
            'address' => $data->address,
            'postcode' => $data->postcode,
            'country' => $data->country,
            'position' => $data->hasPosition() ? $data->latitude . ', ' . $data->longitude : null,
            'opening_hours' => $data->openingHours,
            'notes' => $data->notes,
            default => null,
        };
    }

    /**
     * The details as the merge form shows them.
     *
     * @return array<string, ?string>
     */
    private static function displayed(Station $station): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = self::value($station, $field);
        }

        return $values;
    }
}
