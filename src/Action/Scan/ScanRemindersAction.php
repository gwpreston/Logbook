<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Scan\Recommendations;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Security\SafeRedirect;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /scan/{token}/reminders — the recommendations card after a
 * scanned entry is saved (spec.md §7.27 *Recommended work*): each line of
 * recommended work or each MOT advisory offered as a manual reminder, with
 * *Add reminder* per line and *Add all*. Nothing is added without a press.
 * Needs `Manage` and the reminders module, as the Reminders page does.
 */
final readonly class ScanRemindersAction
{
    public function __construct(
        private ScanGuard $guard,
        private PendingUploadRepository $uploads,
        private VehicleRepository $vehicles,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private ReminderService $reminders,
        private ReminderSettingsStore $settings,
        private Redirector $redirect,
        private View $view,
        private AppSettings $appSettings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $upload = $this->guard->upload($request, $user, $args);
        [$vehicle, $card] = $this->card($request, $upload);
        $items = $card['items'];
        $done = $card['return_to'];

        if ($request->getMethod() === 'POST') {
            $form = RequestContext::form($request);
            $which = is_string($form['item'] ?? null) ? $form['item'] : '';
            if ($which === 'dismiss') {
                $this->uploads->setRecommendations($upload->id, null);

                return $this->redirect->to($done);
            }
            $lead = $this->settings->reminderPreferences($vehicle->userId)->manualDays;
            $added = 0;
            foreach ($items as $i => $item) {
                if ($item['added'] || ($which !== 'all' && $which !== (string) $i)) {
                    continue;
                }
                $this->reminders->createManual($user, Recommendations::reminder($vehicle, $item, $lead));
                $items[$i]['added'] = true;
                $added++;
            }
            $card['items'] = $items;
            $this->uploads->setRecommendations($upload->id, $card);
            RequestContext::session($request)->flash('success', 'scan.reminders.added', ['count' => $added]);
            $left = array_filter($items, static fn (array $item): bool => !$item['added']);

            return $left === []
                ? $this->redirect->to($done)
                : $this->redirect->toRoute('scan.reminders', ['token' => $upload->token]);
        }

        return $this->view->render($request, $response, 'scan/reminders.twig', [
            'upload' => $upload,
            'vehicle' => $vehicle,
            'items' => array_map(static fn (array $item): array => $item + [
                'due_at' => Recommendations::date($item['due_on']),
                'projected_at' => Recommendations::date($item['projected_on']),
            ], $items),
            'done' => $done,
            'has_open' => array_filter($items, static fn (array $item): bool => !$item['added']) !== [],
        ]);
    }

    /**
     * @return array{Vehicle, array{vehicle_id: int, return_to: string, items: list<array{
     *     text: string,
     *     due_on: ?string,
     *     due_km: ?string,
     *     distance_km: ?string,
     *     projected_on: ?string,
     *     guessed: bool,
     *     added: bool,
     * }>}}
     */
    private function card(ServerRequestInterface $request, ScanUpload $upload): array
    {
        $stored = $upload->recommendations;
        $vehicleId = is_int($stored['vehicle_id'] ?? null) ? $stored['vehicle_id'] : null;
        $vehicle = $vehicleId === null ? null : $this->vehicles->findById($vehicleId);
        $user = RequestContext::requireUser($request);
        if (
            $stored === null || $vehicle === null
            || !$this->features->isEnabled(Feature::Reminders)
            || !$this->access->can($user, VehicleAbility::Manage, $vehicle)
        ) {
            throw new HttpNotFoundException($request);
        }
        $items = [];
        foreach (is_array($stored['items'] ?? null) ? $stored['items'] : [] as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)) {
                continue;
            }
            $items[] = [
                'text' => $item['text'],
                'due_on' => is_string($item['due_on'] ?? null) ? $item['due_on'] : null,
                'due_km' => is_string($item['due_km'] ?? null) ? $item['due_km'] : null,
                'distance_km' => is_string($item['distance_km'] ?? null) ? $item['distance_km'] : null,
                'projected_on' => is_string($item['projected_on'] ?? null) ? $item['projected_on'] : null,
                'guessed' => ($item['guessed'] ?? false) === true,
                'added' => ($item['added'] ?? false) === true,
            ];
        }
        $returnTo = is_string($stored['return_to'] ?? null) ? $stored['return_to'] : '';
        $safe = SafeRedirect::localPath($returnTo, $this->appSettings->basePath)
            ?? $this->redirect->urlFor('vehicles.show', ['id' => (string) $vehicle->id]);

        return [$vehicle, ['vehicle_id' => $vehicle->id, 'return_to' => $safe, 'items' => $items]];
    }
}
