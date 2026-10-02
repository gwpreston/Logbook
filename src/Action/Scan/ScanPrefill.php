<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Closure;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Scan\Mapper;
use Logbook\Service\Ai\Scan\Recommendations;
use Logbook\Service\Ai\Scan\ScanReader;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * A create form opened from a scan (spec.md §7.27 *The prefilled form*):
 * `?scan={token}` starts the form from what was read, with each scanned
 * field marked and its evidence shown, and the file listed as already
 * attached. The form carries the token back; saving claims the pending
 * upload once (so a double submit attaches it once), copies its file in
 * as an ordinary attachment in the entry's save, and deletes the pending
 * file only after the save succeeded. Then, when there is recommended
 * work to offer, the user is taken to its card.
 */
final readonly class ScanPrefill
{
    /** Form field carrying the token; `scan_remove` leaves the file unattached. */
    public const string FIELD = 'scan';
    public const string REMOVE = 'scan_remove';
    /** Query: read the file as another kind ("This is a fuel receipt"). */
    public const string AS = 'as';
    private const string COPY_PREFIX = 'logbook-scan-';

    public function __construct(
        private ScanReader $reader,
        private Mapper $mapper,
        private VehicleMatcher $matcher,
        private PendingUploadRepository $uploads,
        private FileStorage $files,
        private Recommendations $recommendations,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private RouteParserInterface $routes,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The form's values from the scan named by `?scan=`, or $defaults when
     * there is none, it is someone else's, expired or already saved. A scan
     * that could not be read keeps the defaults and says why; its file is
     * attached all the same.
     *
     * Editing (Phase 27.2, a claim letter or estimate on its incident):
     * $defaults are the entry as saved; only the values the file changes are
     * filled and marked, the entry's own date is kept, and the notes line
     * is added to its notes rather than replacing them.
     *
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    public function values(
        ServerRequestInterface $request,
        ScanTarget $target,
        Vehicle $vehicle,
        array $defaults,
        bool $editing = false,
    ): array {
        $upload = $this->upload($request, self::string($request->getQueryParams(), self::FIELD));
        if ($upload === null) {
            return $defaults;
        }
        $user = RequestContext::requireUser($request);
        $meta = [
            self::FIELD => $upload->token,
            '_scan_name' => $upload->filename,
            '_scan_image' => $upload->isImage() ? '1' : '',
        ];

        $reading = ScanReader::extraction($upload);
        if ($reading === null) {
            $problem = ScanReader::problem($upload);
            $meta['_scan_problem'] = $this->translator->trans($problem?->messageKey() ?? 'scan.problem.failed');

            return [...$defaults, ...$meta];
        }
        $as = ScanKind::tryFrom(self::string($request->getQueryParams(), self::AS));
        $form = $this->mapper->map($user, $vehicle, $reading, $as);
        if ($form->target !== $target) {
            return [...$defaults, ...$meta];
        }

        $match = $this->matcher->match($user, $reading, $vehicle->id);
        $warnings = $form->warnings;
        if ($match->isMismatch()) {
            array_unshift($warnings, $this->translator->trans('scan.warning.other_vehicle', [
                'registration' => (string) $match->otherRegistration,
                'vehicle' => $vehicle->name(),
            ]));
            if ($match->documentVehicle !== null) {
                $meta['_scan_other_vehicle'] = (string) $match->documentVehicle->id;
            }
        }

        $values = $editing ? self::changes($form->values, $defaults) : $form->values;
        $marked = array_keys(array_filter($values, static fn (string $v): bool => $v !== ''));
        $meta += [
            '_scan_kind' => $form->kind->value,
            '_from_file' => implode(',', $marked),
            '_scan_warnings' => implode("\n", $warnings),
        ];
        // Editing: the words behind a value the file did not change would point at nothing.
        $only = array_flip($marked);
        $notes = [
            '_evidence:' => $editing ? array_intersect_key($form->evidence, $only) : $form->evidence,
            '_problem:' => $form->problems,
            '_check:' => $editing ? array_intersect_key($form->checks, $only) : $form->checks,
            '_hint:' => $form->hints,
        ];
        foreach ($notes as $prefix => $byField) {
            foreach ($byField as $field => $text) {
                $meta[$prefix . $field] = $text;
            }
        }

        return [...$defaults, ...$values, ...$meta];
    }

    /**
     * What a reading changes on a saved entry: the values that differ from
     * it, without its date (an incident's date is when it happened, not
     * the letter's), the notes line added to its notes once.
     *
     * @param array<string, string> $read
     * @param array<string, string> $saved
     * @return array<string, string>
     */
    private static function changes(array $read, array $saved): array
    {
        unset($read['occurred_on']);
        $notes = $read['notes'] ?? null;
        $own = trim($saved['notes'] ?? '');
        if ($notes !== null) {
            $read['notes'] = str_contains($own, $notes) ? $own : trim($own . "\n" . $notes);
        }

        return array_filter($read, static fn (string $value, string $field): bool
            => $value !== '' && $value !== ($saved[$field] ?? ''), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The chosen files plus the scanned one (unless the user removed it),
     * for the form's checks and its save. The scanned file is a temporary
     * copy: the pending one stays until the save has succeeded.
     */
    public function files(ServerRequestInterface $request, PendingUploads $chosen): PendingUploads
    {
        $upload = $this->posted($request);
        if ($upload === null || $upload->storedPath === null || $this->removed($request)) {
            return $chosen;
        }
        $copy = $this->files->temporaryCopy($upload->storedPath, self::COPY_PREFIX);
        register_shutdown_function(static function () use ($copy): void {
            if (is_file($copy)) {
                @unlink($copy);
            }
        });
        $extension = pathinfo($upload->filename, PATHINFO_EXTENSION);

        return new PendingUploads([...$chosen->files, new PendingUpload(
            new UploadedFile($copy, $upload->filename, $upload->mime, $upload->size, UPLOAD_ERR_OK),
            FileUpload::accepted($upload->mime, $extension === '' ? 'bin' : strtolower($extension), $upload->size),
        )]);
    }

    /**
     * Save the entry with its files. The scan is claimed first, once: a
     * second submit of the same form saves without the file. If the save
     * fails, the scan waits for an entry again.
     *
     * @template T
     * @param Closure(PendingUploads): T $save
     * @return array{T, ?ScanUpload} the save's result, and the scan it claimed
     */
    public function save(ServerRequestInterface $request, PendingUploads $files, Closure $save): array
    {
        $upload = $this->posted($request);
        if ($upload === null) {
            return [$save($files), null];
        }
        if (!$this->uploads->claim($upload->id, $this->clock->now())) {
            $files = new PendingUploads(array_values(array_filter(
                $files->files,
                static fn (PendingUpload $file): bool => !self::isScanCopy($file),
            )));

            return [$save($files), null];
        }
        try {
            $result = $save($files);
        } catch (Throwable $e) {
            $this->uploads->release($upload->id, $upload->status);
            throw $e;
        }

        return [$result, $upload];
    }

    /**
     * After the save: the pending file goes, and the user is taken to the
     * recommendations card when the reading offers any (and they may add
     * reminders to this vehicle); otherwise $response as it was.
     *
     * @param string|null $atKm the saved entry's odometer, km
     */
    public function after(
        ServerRequestInterface $request,
        ?ScanUpload $claimed,
        Vehicle $vehicle,
        ?string $atKm,
        ResponseInterface $response,
    ): ResponseInterface {
        if ($claimed === null) {
            return $response;
        }
        $offers = [];
        $reading = ScanReader::extraction($claimed);
        $user = RequestContext::requireUser($request);
        if (
            $reading !== null
            && $this->features->isEnabled(Feature::Reminders)
            && $this->access->can($user, VehicleAbility::Manage, $vehicle)
        ) {
            $as = ScanKind::tryFrom(self::string(RequestContext::form($request), 'scan_kind'));
            $offers = $this->recommendations->offers($user, $vehicle, $as === null ? $reading : $reading->as($as), $atKm);
        }
        $this->uploads->finish($claimed->id, $offers === [] ? null : [
            'vehicle_id' => $vehicle->id,
            'return_to' => $response->getHeaderLine('Location'),
            'items' => $offers,
        ]);
        $this->files->delete($claimed->storedPath);

        if ($offers === []) {
            return $response;
        }

        return $response->withHeader('Location', $this->routes->urlFor('scan.reminders', ['token' => $claimed->token]));
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function isScanCopy(PendingUpload $file): bool
    {
        $uri = $file->file->getStream()->getMetadata('uri');

        return is_string($uri) && str_starts_with(basename($uri), self::COPY_PREFIX);
    }

    private function posted(ServerRequestInterface $request): ?ScanUpload
    {
        return $this->upload($request, self::string(RequestContext::form($request), self::FIELD));
    }

    private function removed(ServerRequestInterface $request): bool
    {
        return (RequestContext::form($request)[self::REMOVE] ?? '') === '1';
    }

    private function upload(ServerRequestInterface $request, string $token): ?ScanUpload
    {
        if ($token === '') {
            return null;
        }
        $upload = $this->reader->find(RequestContext::requireUser($request), $token);

        return $upload !== null && $upload->isClaimable($this->clock->now()) ? $upload : null;
    }
}
