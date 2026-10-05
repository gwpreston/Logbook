<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\UserRepository;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Tyre\TyreSettingsStore;
use Logbook\Service\Tyre\TyreThresholds;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DepthUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The Tyres tab's *Current tyres* (spec.md §7.17, Phase 33.3): per position
 * the pill, the latest measured depth (never an assumed one), the tread bar
 * once there are two measurements, when the tyre was fitted, the estimate,
 * the note from the owner's thresholds and *Check tread* beside *Fit tyres*.
 */
final class CurrentTyresTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $car;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->car = $this->vehicle($this->app);
        $this->base = '/vehicles/' . $this->car->id . '/tyres';
    }

    /**
     * @param array<string, string> $form
     */
    private function post(string $path, array $form): void
    {
        $response = $this->browser->post($path, $form);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    /**
     * @return array<string, Tyre> position → fitted tyre
     */
    private function fitted(Vehicle $vehicle): array
    {
        $fitted = [];
        foreach ($this->service($this->app, TyreService::class)->tyres($vehicle) as $tyre) {
            if ($tyre->status === TyreStatus::Fitted && $tyre->position !== null) {
                $fitted[$tyre->position->value] = $tyre;
            }
        }

        return $fitted;
    }

    /**
     * The rears already on the car (no depth), new Michelins at the front at
     * 8 mm, and a check of the front left at 4.8 mm 6,000 miles later.
     */
    private function carWithTyres(): void
    {
        $this->post($this->base . '/existing', [
            'done_on' => '2025-10-03',
            'odometer' => '20000',
            'pos_rl' => '1',
            'brand_rl' => 'Goodyear',
            'model_rl' => 'EfficientGrip',
            'pos_rr' => '1',
            'brand_rr' => 'Goodyear',
            'model_rr' => 'EfficientGrip',
        ]);
        $this->post($this->base . '/fit', [
            'done_on' => '2026-01-10',
            'odometer' => '21000',
            'pos_fl' => '1',
            'pos_fr' => '1',
            'brand' => 'Michelin',
            'model' => 'Primacy 4',
            'size' => '205/55 R16 91V',
            'tread' => '8',
        ]);
        $this->post($this->base . '/check', [
            'done_on' => '2026-08-01',
            'odometer' => '27000',
            'tread_' . $this->fitted($this->car)['fl']->id => '4.8',
        ]);
        $this->reading($this->app, $this->car, '45061.632', '2026-09-20T09:00:00Z');
    }

    /**
     * One position's card.
     */
    private static function card(string $html, string $position): string
    {
        $start = strpos($html, 'aria-labelledby="tyre-pos-' . $position . '"');
        self::assertNotFalse($start, $position . ' has a card');
        $end = strpos($html, '</article>', $start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function useThirtySeconds(): void
    {
        $users = $this->service($this->app, UserRepository::class);
        $owner = $users->findByUsername('owner');
        self::assertNotNull($owner);
        $prefs = $owner->preferences;
        $users->updateProfile($owner->id, $owner->displayName, new DisplayPreferences(
            $prefs->locale,
            $prefs->timezone,
            $prefs->distanceUnit,
            $prefs->volumeUnit,
            $prefs->consumptionUnit,
            $prefs->currency,
            $prefs->theme,
            $prefs->accent,
            DepthUnit::ThirtySecond,
        ), new DateTimeImmutable());
    }

    public function testEachFittedPositionOfACar(): void
    {
        $this->carWithTyres();
        $html = self::body($this->browser->get($this->base));

        self::assertStringContainsString('>Current tyres</h2>', $html);
        self::assertStringNotContainsString('On the vehicle', $html);

        // Measured twice: the depth with its date, the bar from 8.0 to 1.6 mm, the estimate.
        $fl = self::card($html, 'fl');
        self::assertStringContainsString('4.8 mm</span>', $fl);
        self::assertStringContainsString('Checked 1 Aug 2026', $fl);
        self::assertMatchesRegularExpression('~data-testid="tyre-bar"><span class="bar__fill" style="--w: 50%"~', $fl);
        self::assertStringContainsString('aria-hidden="true" data-testid="tyre-bar"', $fl, 'the bar is decorative');
        self::assertStringContainsString('Fitted Jan 2026 · 7,000 mi covered', $fl);
        self::assertStringContainsString('<span class="visually-hidden">Estimate: </span>about ', $fl);
        self::assertMatchesRegularExpression('~about [\d.]+ mm now · about [\d,]+ mi left~', $fl);
        self::assertStringContainsString('data-testid="tyre-pill"', $fl);
        self::assertStringContainsString('Michelin Primacy 4', $fl);
        self::assertStringContainsString('205/55 R16 91V', $fl);

        // Measured once: the depth, no bar and no estimate yet.
        $fr = self::card($html, 'fr');
        self::assertStringContainsString('8.0 mm</span>', $fr);
        self::assertStringContainsString('Checked 10 Jan 2026', $fr);
        self::assertStringNotContainsString('tyre-bar', $fr);
        self::assertStringNotContainsString('Estimate', $fr);

        // Never measured: no depth figure at all, counted since it was recorded.
        $rl = self::card($html, 'rl');
        self::assertStringContainsString('Not measured yet', $rl);
        self::assertStringNotContainsString(' mm', $rl);
        self::assertStringNotContainsString('tyre-bar', $rl);
        self::assertStringNotContainsString('Fitted ', $rl);
        self::assertStringContainsString('8,000 mi covered since 3 Oct 2025', $rl);
        self::assertStringNotContainsString('data-testid="tyre-pill"', $rl, 'nothing judgeable: no pill');

        self::assertStringContainsString('No tyre', self::card($html, 'spare'));
    }

    public function testFittedIsTheFirstFittingWhateverMovesFollow(): void
    {
        $this->carWithTyres();
        $fitted = $this->fitted($this->car);
        $this->post($this->base . '/rotate', [
            'done_on' => '2026-09-25',
            'odometer' => '28100',
            'move_' . $fitted['fl']->id => 'rl',
            'move_' . $fitted['rl']->id => 'fl',
            'move_' . $fitted['fr']->id => 'fr',
            'move_' . $fitted['rr']->id => 'rr',
        ]);
        $html = self::body($this->browser->get($this->base));

        self::assertStringNotContainsString('Moved', $html, 'a move never relabels the line (#197)');
        self::assertStringContainsString(
            'Fitted Jan 2026 · 7,100 mi covered',
            self::card($html, 'rl'),
            'rotated: still the first fitting, with its distance since',
        );
        $fl = self::card($html, 'fl');
        self::assertStringContainsString('8,100 mi covered since 3 Oct 2025', $fl, 'recorded as already on, then moved');
        self::assertStringNotContainsString('Fitted ', $fl);
        self::assertStringContainsString('Fitted Jan 2026 · 7,100 mi covered', self::card($html, 'fr'), 'not moved');
        self::assertStringContainsString('8,100 mi covered since 3 Oct 2025', self::card($html, 'rr'));
    }

    public function testAMotorbikesFrontAndRear(): void
    {
        $bike = $this->vehicle($this->app, 'Honda', 'CB500F', type: VehicleType::Bike);
        $base = '/vehicles/' . $bike->id . '/tyres';
        $this->post($base . '/existing', [
            'done_on' => '2026-03-01',
            'odometer' => '5000',
            'pos_front' => '1',
            'brand_front' => 'Michelin',
            'model_front' => 'Road 6',
            'tread_front' => '5',
            'pos_rear' => '1',
            'brand_rear' => 'Michelin',
            'model_rear' => 'Road 6',
            'tread_rear' => '6',
        ]);
        $fitted = $this->fitted($bike);
        $this->post($base . '/check', [
            'done_on' => '2026-09-01',
            'odometer' => '8000',
            'tread_' . $fitted['front']->id => '4.2',
            'tread_' . $fitted['rear']->id => '3.6',
        ]);
        $html = self::body($this->browser->get($base));

        self::assertStringNotContainsString('tyre-pos-fl', $html);
        self::assertStringNotContainsString('tyre-pos-spare', $html);
        foreach (['front' => '4.2 mm', 'rear' => '3.6 mm'] as $position => $depth) {
            $card = self::card($html, $position);
            self::assertStringContainsString($depth . '</span>', $card);
            self::assertStringContainsString('data-testid="tyre-bar"', $card);
            self::assertStringContainsString('<span class="visually-hidden">Estimate: </span>about ', $card, $position);
            self::assertStringContainsString('3,000 mi covered since 1 Mar 2026', $card);
        }
        // Front: (4.2 − 1.0) ÷ (5.0 − 1.0) = 80%, against a motorbike's legal minimum.
        self::assertStringContainsString('style="--w: 80%"', self::card($html, 'front'));
        self::assertStringContainsString(
            'You replace at 2.0 mm; the legal minimum you set is 1.0 mm.',
            $html,
            'a motorbike\'s thresholds',
        );
    }

    public function testTheNoteUsesTheOwnersThresholdsAndDepthUnit(): void
    {
        $this->carWithTyres();
        $html = self::body($this->browser->get($this->base));
        self::assertStringContainsString(
            'You replace at 3.0 mm; the legal minimum you set is 1.6 mm. Legal minimums differ by country; check yours.',
            (string) preg_replace('~\s+~', ' ', $html),
        );

        $owner = $this->service($this->app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $this->service($this->app, TyreSettingsStore::class)
            ->saveThresholds($owner->id, new TyreThresholds(carReplaceMm: '4.000', carLegalMm: '2.000'));
        self::assertStringContainsString(
            'You replace at 4.0 mm; the legal minimum you set is 2.0 mm.',
            self::body($this->browser->get($this->base)),
        );

        $this->useThirtySeconds();
        $this->service($this->app, TyreSettingsStore::class)->saveThresholds($owner->id, new TyreThresholds());
        self::assertStringContainsString(
            'You replace at 4/32″; the legal minimum you set is 2/32″.',
            self::body($this->browser->get($this->base)),
        );
    }

    public function testCheckTreadSitsBesideFitTyresWhereItIsAllowed(): void
    {
        $check = 'href="' . $this->base . '/check"';
        self::assertStringNotContainsString($check, self::body($this->browser->get($this->base)), 'not on an empty tab');

        $this->carWithTyres();
        $html = self::body($this->browser->get($this->base));
        self::assertSame(1, substr_count($html, $check), 'a button, no longer in the More menu');
        self::assertMatchesRegularExpression('~<a class="btn" href="' . preg_quote($this->base, '~') . '/check"~', $html);
        self::assertLessThan(strpos($html, 'href="' . $this->base . '/fit"'), strpos($html, $check), 'beside Fit tyres');

        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->car);
        $archived = self::body($this->browser->get($this->base));
        self::assertStringNotContainsString($check, $archived, 'archived: read-only');
        self::assertStringContainsString('>Current tyres</h2>', $archived);
        self::assertStringContainsString('4.8 mm</span>', self::card($archived, 'fl'));
    }
}
