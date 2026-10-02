<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\Scan\IncidentMatcher;
use Logbook\Service\Ai\Scan\ScanAvailability;
use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET|POST /scan — *Scan a receipt or document* (spec.md §7.27). One file,
 * checked like any attachment (a photo is turned upright and stripped of
 * its EXIF before anything else), kept as a pending upload, then read. The
 * reading carries on if a proxy gives up on the request; /scan/{token}
 * shows where it got to. `?vehicle=` and `?for=` (a create form's *Fill
 * from a file*) choose the vehicle and the form; `?incident=` with
 * `for=incident` (*Update from a letter*, Phase 27.2) the incident it updates.
 */
final readonly class ScanAction
{
    public const string FIELD = 'file';
    private const int READ_SECONDS = 300;

    public function __construct(
        private ScanGuard $guard,
        private ScanAvailability $availability,
        private ScanReader $reader,
        private VehicleMatcher $matcher,
        private IncidentMatcher $incidents,
        private AttachmentService $attachments,
        private Redirector $redirect,
        private View $view,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->guard->user($request);
        $vehicles = $this->matcher->candidates($user);

        if ($request->getMethod() !== 'POST') {
            $query = $request->getQueryParams();

            return $this->render($request, $response, $user, $vehicles, [
                'vehicle_id' => is_string($query['vehicle'] ?? null) ? $query['vehicle'] : '',
                'for' => is_string($query['for'] ?? null) ? $query['for'] : '',
                'incident' => is_string($query['incident'] ?? null) ? $query['incident'] : '',
            ]);
        }

        $form = RequestContext::form($request);
        $values = [
            'vehicle_id' => is_string($form['vehicle_id'] ?? null) ? $form['vehicle_id'] : '',
            'for' => is_string($form['for'] ?? null) ? $form['for'] : '',
            'incident' => is_string($form['incident'] ?? null) ? $form['incident'] : '',
        ];
        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        if (!$file instanceof UploadedFileInterface || !FileUpload::wasProvided($file)) {
            return $this->render($request, $response, $user, $vehicles, $values, 'scan.validation.file', 422);
        }
        $checked = $this->attachments->check($file);
        if (!$checked->isValid()) {
            $key = 'upload.file.' . substr((string) $checked->error, strlen('upload.'));

            return $this->render($request, $response, $user, $vehicles, $values, $this->translator->trans($key, [
                'name' => basename((string) $file->getClientFilename()),
                'max' => $this->attachments->maxMegabytes(),
            ]), 422);
        }

        $chosen = null;
        foreach ($vehicles as $vehicle) {
            if ((string) $vehicle->id === $values['vehicle_id']) {
                $chosen = $vehicle;
            }
        }
        $target = ScanTarget::tryFrom($values['for']);
        $target = $target === ScanTarget::Vehicle ? null : $target;
        // *Update from a letter*: an incident of the chosen vehicle the user may change.
        $incident = $target === ScanTarget::Incident && $chosen !== null
            ? $this->incidents->find($user, $chosen, $values['incident'])
            : null;
        $upload = $this->reader->keep($user, $file, $checked, $chosen?->id, $target, $incident?->id);

        // Kept going if a proxy gives up on the request: /scan/{token} shows the result.
        set_time_limit(self::READ_SECONDS + AiGateway::LOCK_GRACE);
        ignore_user_abort(true);
        $this->reader->read($user, $upload);

        return $this->redirect->toRoute('scan.result', ['token' => $upload->token]);
    }

    /**
     * @param list<Vehicle> $vehicles
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        array $vehicles,
        array $values,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        $options = [['value' => '', 'label' => $this->translator->trans('scan.field.vehicle_any')]];
        foreach ($vehicles as $vehicle) {
            $options[] = ['value' => (string) $vehicle->id, 'label' => $vehicle->name()];
        }

        return $this->view->render($request, $response, 'scan/form.twig', [
            'values' => $values,
            'errors' => $error === null ? [] : [self::FIELD => ['key' => $error, 'params' => []]],
            'vehicle_options' => $options,
            'has_vehicles' => $vehicles !== [],
            'connection' => $this->availability->connection(),
            'reads_pictures' => $this->availability->readsPictures(),
            'max_upload_mb' => $this->attachments->maxMegabytes(),
        ], $status);
    }
}
