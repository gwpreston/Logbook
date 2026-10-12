<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenFigures;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The leak sweep's scan (Phase 45.2), for AppTestCase subclasses: the
 * household loaded, and what each person was sent scanned for what they
 * must not see (LeakNeedles).
 *
 * Alex sees everything, so what Alex was sent is the control: a needle
 * Alex never sees is in a form the app doesn't print, and is dropped.
 * Every leak is reported at once.
 *
 * Set SWEEP_DUMP to a directory to keep every scanned body there.
 */
trait LeakSweeping
{
    /**
     * Where an admin is shown other people's vehicles by name, as the spec
     * asks: deleting a user is refused while they own vehicles, and the page
     * lists them, each with a transfer form (§7.9 *Delete*). Names only; an
     * amount there is still a leak.
     */
    private const array ADMIN_SEES_NAMES = ['settings.users.delete'];

    /**
     * The household on a fresh database, today pinned.
     *
     * @param App<ContainerInterface> $app
     */
    private function loadHousehold(App $app): Household
    {
        $this->pinClock($app, InputBuilder::TODAY_UTC);
        $this->resetDatabase($app);

        return Household::load($app->getContainer(), self::PASSWORD);
    }

    /**
     * Every leak in what each person was sent: "person sees needle (what)
     * at where: …snippet…".
     *
     * @param array<string, array<string, string>> $sent person => where => body; Alex's is the control
     * @param bool $wholeControl each kind of needle must be seen by the control, or the scan proves nothing
     * @return list<string>
     */
    private function leaks(App $app, Household $household, array $sent, bool $wholeControl = true): array
    {
        $figures = self::figures($app->getContainer(), $household);
        self::dump($sent);
        $leaks = [];
        foreach (Household::RESTRICTED as $person) {
            if (!isset($sent[$person])) {
                continue;
            }
            $needles = $this->checked(
                LeakNeedles::for($person)->withFigures($person, $figures),
                $sent['alex'] ?? [],
                $person,
                $wholeControl,
            );
            $admin = $household->user($person)->isAdmin;
            foreach ($sent[$person] as $where => $body) {
                foreach ($needles->find($body) as $needle => $snippet) {
                    if ($admin && $needles->namesVehicle((string) $needle) && self::onRoute($where, self::ADMIN_SEES_NAMES)) {
                        continue;
                    }
                    $what = $needles->label((string) $needle);
                    $leaks[] = sprintf('%s sees %s (%s) at %s: …%s…', $person, $needle, $what, $where, $snippet);
                }
            }
        }

        return $leaks;
    }

    /**
     * The app's money figures for the golden rows (expected.csv), as each
     * row's viewer: totals and rates, with the vehicles they cover ("fleet"
     * and "all" cover the viewer's vehicles in the row's currency). Read
     * once per class: the household is the same each time.
     *
     * @return list<array{vehicles: list<string>, value: string, label: string}>
     */
    private static function figures(ContainerInterface $container, Household $household): array
    {
        static $figures = null;
        if ($figures !== null) {
            return $figures;
        }
        $golden = new GoldenFigures($container, $household->golden);
        $currency = array_column(ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/vehicles.csv'), 'currency', 'vehicle');
        $figures = [];
        foreach (ExpectedSkeleton::read(dirname(GoldenHousehold::INPUTS) . '/expected.csv') as $row) {
            if (!str_starts_with($row['unit'], 'money')) {
                continue;
            }
            $vehicles = [$row['vehicle']];
            if (!isset($household->golden->vehicles[$row['vehicle']])) {
                // A fleet figure is per currency: the viewer's vehicles in that one.
                $vehicles = array_values(array_filter(
                    Household::PEOPLE[$row['viewer']]['costs'],
                    static fn (string $key): bool => $currency[$key] === $row['currency'],
                ));
            }
            $figures[] = [
                'vehicles' => $vehicles,
                'value' => $golden->actual($row),
                'label' => "{$row['figure']} for {$row['vehicle']}, {$row['period']}",
            ];
        }

        return $figures;
    }

    /**
     * The person's needles that the control sees somewhere.
     *
     * @param array<string, string> $control
     */
    private function checked(LeakNeedles $needles, array $control, string $person, bool $wholeControl): LeakNeedles
    {
        $seen = [];
        foreach ($control as $body) {
            foreach (array_keys($needles->find($body)) as $needle) {
                $seen[(string) $needle] = true;
            }
        }
        $kept = $needles->only($seen);
        if (!$wholeControl) {
            return $kept;
        }
        $model = Household::PEOPLE[$person];
        if (count($model['costs']) < 4) {
            self::assertNotEmpty($kept->amounts, "none of {$person}'s hidden amounts is ever shown to Alex");
        }
        if (count($model['vehicles']) < 4) {
            self::assertNotEmpty(array_filter(
                $kept->texts,
                static fn (string $label): bool => str_starts_with($label, 'the registration'),
            ), "none of the registrations hidden from {$person} is ever shown to Alex");
        }

        return $kept;
    }

    /**
     * @param list<string> $routes
     */
    private static function onRoute(string $where, array $routes): bool
    {
        foreach ($routes as $route) {
            if (str_ends_with($where, '[' . $route . ']')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, string>> $sent
     */
    private static function dump(array $sent): void
    {
        $dir = getenv('SWEEP_DUMP');
        if (!is_string($dir) || $dir === '') {
            return;
        }
        foreach ($sent as $person => $bodies) {
            if (!is_dir($dir . '/' . $person)) {
                mkdir($dir . '/' . $person, 0777, true);
            }
            foreach ($bodies as $where => $body) {
                file_put_contents($dir . '/' . $person . '/' . md5($where) . '.txt', $where . "\n" . $body);
            }
        }
    }
}
