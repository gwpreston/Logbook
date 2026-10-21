<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\Mapper;
use Logbook\Service\Ai\Scan\ScanProblem;
use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /scan/{token}/vehicle?vehicle={id} — a registration document's
 * details offered as updates to the vehicle (spec.md §7.27): registration,
 * VIN and first registration date beside the current values, each with
 * its own tick, saved through the vehicle edit's own parser. The file is
 * kept only when the user ticks it, since it carries the document
 * reference: on a `registration` document, which the sale pack never
 * offers (PaperworkKind::NEVER_OFFERED). Otherwise it is deleted. Needs
 * `Manage`, and the compliance module for keeping the file. Opens only for
 * a scan whose kind is a registration document, worked out as the result
 * page does (`?as=` passed on from it); any other scan is not found (K136).
 */
final readonly class ScanVehicleAction
{
    public const array FIELDS = ['registration', 'vin', 'first_registered_on'];
    public const string KEEP = 'scan_keep';

    public function __construct(
        private ScanGuard $guard,
        private VehicleMatcher $matcher,
        private VehicleAccess $access,
        private Mapper $mapper,
        private VehicleService $vehicles,
        private ComplianceService $compliance,
        private FeatureToggles $features,
        private ScanPrefill $prefill,
        private Redirector $redirect,
        private View $view,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $upload = $this->guard->upload($request, $user, $args);
        $reading = ScanReader::extraction($upload);
        // A *Scan the V5C* scan that couldn't be read (#511) shows why, with nothing to update.
        $problem = $reading === null && $upload->target === ScanTarget::Vehicle ? ScanReader::problem($upload) : null;
        $vehicle = $this->vehicle($request, $upload);
        if (($reading === null && $problem === null) || !$upload->isClaimable($this->clock->now())) {
            throw new HttpNotFoundException($request);
        }
        if ($this->kind($request, $upload, $reading) !== ScanKind::Registration) {
            // Only where the result page sends a scan (K136): not a receipt by a crafted URL.
            throw new HttpNotFoundException($request);
        }
        $form = $reading === null ? null : $this->mapper->map($user, $vehicle, $reading, ScanKind::Registration);
        $current = VehicleForm::values($vehicle, $user->preferences);

        $rows = [];
        foreach ($form === null ? [] : self::FIELDS as $field) {
            $found = $form->values[$field] ?? '';
            if ($found === '' && !isset($form->problems[$field])) {
                continue;
            }
            $same = $found !== '' && self::same($field, $found, $current[$field] ?? '');
            $rows[] = [
                'field' => $field,
                'found' => $found,
                'found_date' => $field === 'first_registered_on' && $found !== '' ? LocalTime::parseDate($found) : null,
                'current' => $current[$field] ?? '',
                'same' => $same,
                'evidence' => $form->evidence[$field] ?? null,
                'problem' => $form->problems[$field] ?? null,
                'check' => $form->checks[$field] ?? null,
            ];
        }

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $upload, $vehicle, $rows, problem: $problem);
        }

        $input = RequestContext::form($request);
        $ticked = is_array($input['update'] ?? null) ? $input['update'] : [];
        $values = $current;
        foreach ($rows as $row) {
            if ($row['found'] !== '' && in_array($row['field'], $ticked, true)) {
                $values[$row['field']] = $row['found'];
            }
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $data = VehicleForm::parse($values, $user->preferences, $today, false, $vehicle->data->firstInspectionDueOn);
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, $upload, $vehicle, $rows, $data, 422, $problem);
        }
        $keep = ($input[self::KEEP] ?? '') === '1' && $this->canKeep();
        $files = $keep ? $this->prefill->files($request, new PendingUploads()) : new PendingUploads();

        [$updated, $claimed] = $this->prefill->save(
            $request,
            $files,
            function (PendingUploads $files) use ($user, $vehicle, $data, $keep): Vehicle {
                $updated = $this->vehicles->update($user, $vehicle, $data);
                if ($keep) {
                    $this->compliance->create(
                        $updated,
                        new ComplianceDocumentData(ComplianceType::Registration),
                        $user->preferences->timeZone(),
                        $files,
                    );
                }

                return $updated;
            },
        );
        RequestContext::session($request)->flash('success', 'vehicle.updated', ['name' => $updated->name()]);

        return $this->prefill->after(
            $request,
            $claimed,
            $updated,
            null,
            $this->redirect->toRoute('vehicles.show', ['id' => (string) $updated->id]),
        );
    }

    /**
     * The scan's kind as ScanResultAction works it out: the owner's *Read
     * as*, *Scan the V5C* (#511), the model's reading, then the form it
     * was started from.
     */
    private function kind(ServerRequestInterface $request, ScanUpload $upload, ?Extraction $reading): ?ScanKind
    {
        $v5c = $upload->target === ScanTarget::Vehicle ? ScanKind::Registration : null;

        return ScanKind::tryFrom(self::as($request)) ?? $v5c ?? $reading->kind ?? $upload->target?->defaultKind();
    }

    private static function as(ServerRequestInterface $request): string
    {
        $as = $request->getQueryParams()[ScanPrefill::AS] ?? null;

        return is_string($as) ? $as : '';
    }

    private function canKeep(): bool
    {
        return $this->features->isEnabled(Feature::Compliance);
    }

    private function vehicle(ServerRequestInterface $request, ScanUpload $upload): Vehicle
    {
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams()['vehicle'] ?? null;
        $wanted = is_string($query) ? $query : (string) $upload->vehicleId;
        foreach ($this->matcher->candidates($user) as $vehicle) {
            if ((string) $vehicle->id === $wanted && $this->access->can($user, VehicleAbility::Manage, $vehicle)) {
                return $vehicle;
            }
        }

        throw new HttpNotFoundException($request);
    }

    private static function same(string $field, string $found, string $current): bool
    {
        return $field === 'first_registered_on'
            ? $found === $current
            : VehicleMatcher::plate($found) === VehicleMatcher::plate($current);
    }

    /**
     * @param list<array{
     *     field: string,
     *     found: string,
     *     found_date: ?\DateTimeImmutable,
     *     current: string,
     *     same: bool,
     *     evidence: ?string,
     *     problem: ?string,
     *     check: ?string,
     * }> $rows
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ScanUpload $upload,
        Vehicle $vehicle,
        array $rows,
        ?ValidationErrors $errors = null,
        int $status = 200,
        ?ScanProblem $problem = null,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'scan/vehicle.twig', [
            'upload' => $upload,
            'vehicle' => $vehicle,
            'rows' => $rows,
            'errors' => $errors?->all() ?? [],
            'can_keep' => $this->canKeep(),
            'problem' => $problem,
            'as' => ScanKind::tryFrom(self::as($request))?->value,
        ], $status);
    }
}
