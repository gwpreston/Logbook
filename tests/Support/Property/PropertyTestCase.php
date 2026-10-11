<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * A property test (Phase 45.1): each test runs once per seed, on a household
 * generated from it and entered through the services. The seeds are fixed
 * unless PROPERTY_SEEDS lists others ("12,99"); the nightly job passes fresh
 * ones, and a failure names its seed so it replays exactly.
 */
abstract class PropertyTestCase extends AppTestCase
{
    /** The seeds every push runs, on every engine. */
    private const array FIXED_SEEDS = [7, 42, 451, 2026, 31337];

    private ?string $defaultZone = null;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        $listed = getenv('PROPERTY_SEEDS');
        $seeds = self::FIXED_SEEDS;
        if (is_string($listed) && trim($listed) !== '') {
            $seeds = array_map('intval', array_filter(array_map('trim', explode(',', $listed)), 'is_numeric'));
        }
        foreach ($seeds as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    protected function tearDown(): void
    {
        if ($this->defaultZone !== null) {
            date_default_timezone_set($this->defaultZone);
            $this->defaultZone = null;
        }
        parent::tearDown();
    }

    /**
     * A fresh database with the seed's household in it.
     *
     * @param (callable(list<Entry>): list<Entry>)|null $order
     * @return array{App<ContainerInterface>, Plan, PlanWriter}
     */
    protected function household(int $seed, ?callable $order = null, ?Plan $plan = null): array
    {
        $app = $this->createApp();
        $this->pinClock($app, HouseholdGenerator::TODAY);
        $this->resetDatabase($app);
        $plan ??= (new HouseholdGenerator())->generate($seed);
        $writer = (new PlanWriter($app->getContainer()))->write($plan, self::PASSWORD, $order);

        return [$app, $plan, $writer];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function figures(App $app): Figures
    {
        return new Figures($app->getContainer());
    }

    /**
     * Run PHP in another default zone from here on (the app sets UTC when it
     * boots; this is after), restored after the test.
     */
    protected function serverZone(string $zone): void
    {
        $this->defaultZone ??= date_default_timezone_get();
        date_default_timezone_set($zone);
    }
}
