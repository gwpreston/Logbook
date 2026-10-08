<?php

declare(strict_types=1);

namespace Logbook\Action\Scan;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\IssueSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Scan\Recommendations;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Security\SafeRedirect;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /scan/{token}/reminders — the recommendations card after a
 * scanned entry is saved (spec.md §7.27 *Recommended work*): each line of
 * recommended work or each MOT advisory offered as a manual reminder, with
 * *Add reminder* per line and *Add all*, and from Phase 40.2 (§7.37, #313,
 * #314) as an issue, with *Add as issue*, *Watch* and *Add all as issues*.
 * Nothing is added without a press. The card is shown to whoever may add
 * either; each button checks its own: reminders need `Manage` and the
 * reminders module, issues `Log` and the issues module. A line is added
 * once, and says as what.
 */
final readonly class ScanRemindersAction
{
    /** What a line was added as. */
    private const string AS_REMINDER = 'reminder';
    private const string AS_ISSUE = 'issue';

    public function __construct(
        private ScanGuard $guard,
        private PendingUploadRepository $uploads,
        private VehicleRepository $vehicles,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private ReminderService $reminders,
        private ReminderSettingsStore $settings,
        private IssueService $issues,
        private Redirector $redirect,
        private View $view,
        private AppSettings $appSettings,
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
        [$vehicle, $card] = $this->card($request, $upload);
        $items = $card['items'];
        $done = $card['return_to'];
        $canRemind = $this->canRemind($user, $vehicle);
        $canIssue = $this->canIssue($user, $vehicle);

        if ($request->getMethod() === 'POST') {
            $form = RequestContext::form($request);
            $which = is_string($form['item'] ?? null) ? $form['item'] : '';
            if ($which === 'dismiss') {
                $this->uploads->setRecommendations($upload->id, null);

                return $this->redirect->to($done);
            }
            $as = is_string($form['as'] ?? null) ? $form['as'] : self::AS_REMINDER;
            $watch = $as === 'watch';
            $asIssue = $as === self::AS_ISSUE || $watch;
            if (($asIssue && !$canIssue) || (!$asIssue && !$canRemind) || ($watch && $which === 'all')) {
                throw new HttpNotFoundException($request);
            }
            $zone = $user->preferences->timeZone();
            $today = LocalTime::today($this->clock, $zone);
            $noticedOn = $card['noticed_on'] === null ? $today : min($card['noticed_on'], $today);
            $lead = $this->settings->reminderPreferences($vehicle->userId)->manualDays;
            $added = 0;
            foreach ($items as $i => $item) {
                if ($item['added_as'] !== null || ($which !== 'all' && $which !== (string) $i)) {
                    continue;
                }
                if ($asIssue) {
                    $this->issues->create(
                        $vehicle,
                        Recommendations::issue($item, $noticedOn, $card['odometer_km'], $watch),
                        $zone,
                        source: IssueSource::RecommendedWork,
                        sourceRef: 'upload:' . $upload->id,
                    );
                } else {
                    $this->reminders->createManual($user, Recommendations::reminder($vehicle, $item, $lead));
                }
                $items[$i]['added_as'] = $asIssue ? self::AS_ISSUE : self::AS_REMINDER;
                $added++;
            }
            $card['items'] = $items;
            $this->uploads->setRecommendations($upload->id, self::stored($card));
            RequestContext::session($request)->flash(
                'success',
                $asIssue ? 'scan.reminders.added_issues' : 'scan.reminders.added',
                ['count' => $added],
            );
            $left = array_filter($items, static fn (array $item): bool => $item['added_as'] === null);

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
            'has_open' => array_filter($items, static fn (array $item): bool => $item['added_as'] === null) !== [],
            'can_remind' => $canRemind,
            'can_issue' => $canIssue,
        ]);
    }

    private function canRemind(User $user, Vehicle $vehicle): bool
    {
        return $this->features->isEnabled(Feature::Reminders) && $this->access->can($user, VehicleAbility::Manage, $vehicle);
    }

    private function canIssue(User $user, Vehicle $vehicle): bool
    {
        return $this->features->isEnabled(Feature::Issues)
            && !$vehicle->isArchived()
            && $this->access->can($user, VehicleAbility::Log, $vehicle);
    }

    /**
     * @return array{Vehicle, array{
     *     vehicle_id: int,
     *     return_to: string,
     *     noticed_on: ?\DateTimeImmutable,
     *     odometer_km: ?string,
     *     items: list<array{
     *         text: string,
     *         due_on: ?string,
     *         due_km: ?string,
     *         distance_km: ?string,
     *         projected_on: ?string,
     *         guessed: bool,
     *         added_as: ?string,
     *     }>,
     * }}
     */
    private function card(ServerRequestInterface $request, ScanUpload $upload): array
    {
        $stored = $upload->recommendations;
        $vehicleId = is_int($stored['vehicle_id'] ?? null) ? $stored['vehicle_id'] : null;
        $vehicle = $vehicleId === null ? null : $this->vehicles->findById($vehicleId);
        $user = RequestContext::requireUser($request);
        if ($stored === null || $vehicle === null || (!$this->canRemind($user, $vehicle) && !$this->canIssue($user, $vehicle))) {
            throw new HttpNotFoundException($request);
        }
        $items = [];
        foreach (is_array($stored['items'] ?? null) ? $stored['items'] : [] as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)) {
                continue;
            }
            $as = $item['added_as'] ?? null;
            $items[] = [
                'text' => $item['text'],
                'due_on' => is_string($item['due_on'] ?? null) ? $item['due_on'] : null,
                'due_km' => is_string($item['due_km'] ?? null) ? $item['due_km'] : null,
                'distance_km' => is_string($item['distance_km'] ?? null) ? $item['distance_km'] : null,
                'projected_on' => is_string($item['projected_on'] ?? null) ? $item['projected_on'] : null,
                'guessed' => ($item['guessed'] ?? false) === true,
                // A card saved before Phase 40.2 says only `added`: those lines became reminders.
                'added_as' => match (true) {
                    $as === self::AS_REMINDER, $as === self::AS_ISSUE => $as,
                    ($item['added'] ?? false) === true => self::AS_REMINDER,
                    default => null,
                },
            ];
        }
        $returnTo = is_string($stored['return_to'] ?? null) ? $stored['return_to'] : '';
        $safe = SafeRedirect::localPath($returnTo, $this->appSettings->basePath)
            ?? $this->redirect->urlFor('vehicles.show', ['id' => (string) $vehicle->id]);
        $noticed = is_string($stored['noticed_on'] ?? null) ? LocalTime::parseDate($stored['noticed_on']) : null;
        $atKm = is_string($stored['odometer_km'] ?? null) ? $stored['odometer_km'] : null;

        return [$vehicle, [
            'vehicle_id' => $vehicle->id,
            'return_to' => $safe,
            'noticed_on' => $noticed,
            'odometer_km' => $atKm,
            'items' => $items,
        ]];
    }

    /**
     * The card as it is kept on the pending upload.
     *
     * @param array{noticed_on: ?\DateTimeImmutable, items: list<array<string, mixed>>, ...} $card
     * @return array<string, mixed>
     */
    private static function stored(array $card): array
    {
        return ['noticed_on' => $card['noticed_on']?->format('Y-m-d')] + $card;
    }
}
