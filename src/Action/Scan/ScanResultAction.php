<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanStatus;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Http\Redirector;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /scan/{token} — where a scan goes next (spec.md §7.27): still
 * reading (the page looks again shortly); the vehicle to pick; the kind to
 * pick for a file that could not be read; a module that is off; or the
 * prefilled form, as a redirect to the create form with `?scan=`. `?as=`
 * reads it as another kind, `?vehicle=` picks the vehicle. A saved scan
 * goes to its recommendations card.
 */
final readonly class ScanResultAction
{
    public function __construct(
        private ScanGuard $guard,
        private VehicleMatcher $matcher,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private Redirector $redirect,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $upload = $this->guard->upload($request, $user, $args);
        if ($upload->status === ScanStatus::Saved) {
            if ($upload->recommendations === null) {
                throw new HttpNotFoundException($request);
            }

            return $this->redirect->toRoute('scan.reminders', ['token' => $upload->token]);
        }
        if ($upload->status === ScanStatus::Reading) {
            return $this->view->render($request, $response, 'scan/reading.twig', ['upload' => $upload]);
        }

        $query = $request->getQueryParams();
        $reading = ScanReader::extraction($upload);
        $as = ScanKind::tryFrom(is_string($query['as'] ?? null) ? $query['as'] : '');
        $kind = $as ?? $reading->kind ?? $upload->target?->defaultKind();
        if ($kind === null) {
            // Not read, and not started from a form: which form?
            return $this->view->render($request, $response, 'scan/kind.twig', [
                'upload' => $upload,
                'problem' => ScanReader::problem($upload),
                'kinds' => [ScanKind::ServiceInvoice, ScanKind::FuelReceipt, ScanKind::Other],
            ]);
        }
        $target = $kind->target();

        $vehicles = $this->matcher->candidates($user);
        $picked = is_string($query['vehicle'] ?? null) ? $query['vehicle'] : '';
        $vehicle = null;
        foreach ($vehicles as $candidate) {
            if ((string) $candidate->id === $picked) {
                $vehicle = $candidate;
            }
        }
        if ($vehicle === null && $reading !== null) {
            $vehicle = $this->matcher->match($user, $reading, $upload->vehicleId)->vehicle;
        }
        if ($vehicle === null && $upload->vehicleId !== null) {
            foreach ($vehicles as $candidate) {
                $vehicle = $candidate->id === $upload->vehicleId ? $candidate : $vehicle;
            }
        }
        if ($vehicle === null && count($vehicles) === 1) {
            $vehicle = $vehicles[0];
        }
        if ($vehicle === null) {
            return $this->view->render($request, $response, 'scan/pick.twig', [
                'upload' => $upload,
                'vehicles' => $vehicles,
                'kind' => $kind,
                'as' => $as,
                'registration' => $reading?->value('registration'),
            ]);
        }

        $feature = $target->feature();
        $off = $feature !== null && !$this->features->isEnabled($feature);
        $forbidden = $target === ScanTarget::Vehicle && !$this->access->can($user, VehicleAbility::Manage, $vehicle);
        if ($off || $forbidden) {
            return $this->view->render($request, $response, 'scan/unavailable.twig', [
                'upload' => $upload,
                'kind' => $kind,
                'feature' => $feature,
                'vehicle' => $vehicle,
            ]);
        }

        return $this->redirect->to($this->formUrl($target, $vehicle, $upload->token, $as));
    }

    private function formUrl(ScanTarget $target, Vehicle $vehicle, string $token, ?ScanKind $as): string
    {
        $query = [ScanPrefill::FIELD => $token] + ($as === null ? [] : [ScanPrefill::AS => $as->value]);
        if ($target === ScanTarget::Vehicle) {
            return $this->redirect->urlFor('scan.vehicle', ['token' => $token], ['vehicle' => (string) $vehicle->id]);
        }

        return $this->redirect->urlFor($target->route(), ['id' => (string) $vehicle->id], $query);
    }
}
