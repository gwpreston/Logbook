<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The *Fixes* checklist on the service record form (spec.md §7.37 *Fixing
 * from the service record*): the vehicle's open and watching issues, and
 * those the record already fixes. A hidden `fixes_sent` marks that the
 * checklist was on the form, so a form without it (the module off, no
 * issues) changes no link.
 */
final readonly class IssueFixPicker
{
    private const string FIELD = 'fixes';
    private const string SENT = 'fixes_sent';

    public function __construct(
        private IssueService $issues,
        private FeatureToggles $features,
    ) {
    }

    /**
     * Template variables: `fix_options` (empty when the module is off or
     * there is nothing to list) and `fix_ticked`, the ticked ids.
     *
     * @param list<int>|null $ticked the ids to show ticked; null = the record's own
     * @return array{fix_options: list<Issue>, fix_ticked: list<int>}
     */
    public function context(Vehicle $vehicle, ?MaintenanceEntry $entry, ?array $ticked): array
    {
        if (!$this->features->isEnabled(Feature::Issues)) {
            return ['fix_options' => [], 'fix_ticked' => []];
        }

        return [
            'fix_options' => $this->offered($vehicle, $entry),
            'fix_ticked' => $ticked ?? ($entry === null ? [] : $this->issues->fixedBy($entry->id)),
        ];
    }

    /**
     * ?fixes=<id> on a new record (*Log the repair*): the issue ticked, its
     * category and title as the record's.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public function prefill(ServerRequestInterface $request, Vehicle $vehicle, array $values): array
    {
        $issue = $this->requested($request, $vehicle);
        if ($issue === null) {
            return $values;
        }
        $values['title'] = $issue->data->title;
        if ($issue->data->category !== null) {
            $values['category'] = $issue->data->category->value;
        }

        return $values;
    }

    /**
     * The ids ticked by ?fixes=<id> on a new record, for the first render.
     *
     * @return list<int>|null
     */
    public function requestedTicks(ServerRequestInterface $request, Vehicle $vehicle): ?array
    {
        $issue = $this->requested($request, $vehicle);

        return $issue === null ? null : [$issue->id];
    }

    /**
     * The ids ticked on a posted form, for a re-render after an error.
     *
     * @param array<array-key, mixed> $form
     * @return list<int>|null null when the checklist was not on the form
     */
    public function posted(array $form): ?array
    {
        if (!array_key_exists(self::SENT, $form)) {
            return null;
        }
        $given = $form[self::FIELD] ?? [];
        $ids = [];
        foreach (is_array($given) ? $given : [$given] as $id) {
            if (is_string($id) && ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * What to save: the ticked issues among those offered, or null to leave
     * the record's links alone.
     *
     * @param array<array-key, mixed> $form
     * @return list<int>|null
     */
    public function toSave(Vehicle $vehicle, ?MaintenanceEntry $entry, array $form): ?array
    {
        $posted = $this->posted($form);
        if ($posted === null || !$this->features->isEnabled(Feature::Issues)) {
            return null;
        }
        $offered = array_map(static fn (Issue $issue): int => $issue->id, $this->offered($vehicle, $entry));

        return array_values(array_filter($posted, static fn (int $id): bool => in_array($id, $offered, true)));
    }

    /**
     * Open and watching issues, and the ones the record already fixes.
     *
     * @return list<Issue>
     */
    private function offered(Vehicle $vehicle, ?MaintenanceEntry $entry): array
    {
        $offered = $this->issues->unresolved($vehicle);
        if ($entry !== null) {
            $listed = array_map(static fn (Issue $issue): int => $issue->id, $offered);
            foreach ($this->issues->fixedBy($entry->id) as $id) {
                $issue = in_array($id, $listed, true) ? null : $this->issues->find($vehicle, $id);
                if ($issue !== null) {
                    $offered[] = $issue;
                }
            }
        }

        return $offered;
    }

    private function requested(ServerRequestInterface $request, Vehicle $vehicle): ?Issue
    {
        $id = $request->getQueryParams()[self::FIELD] ?? null;
        if (!is_string($id) || !ctype_digit($id) || !$this->features->isEnabled(Feature::Issues)) {
            return null;
        }
        $issue = $this->issues->find($vehicle, (int) $id);

        return $issue !== null && $issue->status()->isUnresolved() ? $issue : null;
    }
}
