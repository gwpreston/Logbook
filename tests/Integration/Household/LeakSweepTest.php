<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use Logbook\Domain\Api\ApiScope;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenFigures;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Golden\InputBuilder;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Household\LeakNeedles;
use Logbook\Tests\Support\Household\SweepUrls;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * The leak sweep (Phase 45.2, spec.md §5, §7.21): every GET route of the
 * app and the API, for each of the household's vehicles, as each person
 * with less than everything, scanned for what they must not see
 * (LeakNeedles): another vehicle, an amount whose costs are hidden from
 * them, another driver's places.
 *
 * Alex sees everything, so Alex's responses are the control: a needle Alex
 * never sees is in a form the app doesn't print, and is dropped; each kind
 * of needle must be seen somewhere, or the sweep would prove nothing.
 *
 * Every leak is reported at once, with the URL and a snippet.
 */
final class LeakSweepTest extends AppTestCase
{
    use ApiFixtures;

    /**
     * Routes where an admin is shown other people's vehicles by name, as
     * the spec asks: deleting a user is refused while they own vehicles,
     * and the page lists them, each with a transfer form (§7.9 *Delete*).
     * Names only; an amount there is still a leak.
     */
    private const string EXPECTED = __DIR__ . '/../../Fixtures/golden/expected.csv';

    private const array ADMIN_SEES_NAMES = ['settings.users.delete'];

    /** Files are their own check (AccessMatrixTest): a 200 here is allowed only for those who may. */
    private const array BINARY = ['image/', 'application/pdf', 'application/zip', 'application/octet-stream'];

    public function testNoPageOrApiResponseShowsWhatItShouldNot(): void
    {
        [$app, $household] = $this->household();
        $container = $app->getContainer();
        $urls = (new SweepUrls($app, $this->connection($app)))->all(array_map(
            static fn ($v): int => $v->id,
            array_values($household->vehicles()),
        ));
        self::assertGreaterThan(500, count($urls), 'every GET route, for each vehicle');

        $figures = self::figures($container, $household);
        $bodies = [];
        foreach (Household::people() as $person) {
            $bodies[$person] = $this->responses($app, $household, $person, $urls);
            $dump = getenv('SWEEP_DUMP');
            if (is_string($dump) && $dump !== '') {
                @mkdir($dump . '/' . $person, 0777, true);
                foreach ($bodies[$person] as $url => $body) {
                    file_put_contents($dump . '/' . $person . '/' . md5($url) . '.txt', $url . "\n" . $body);
                }
            }
        }

        $leaks = [];
        foreach (Household::RESTRICTED as $person) {
            $needles = $this->checked(LeakNeedles::for($person)->withFigures($person, $figures), $bodies['alex'], $person);
            $admin = $household->user($person)->isAdmin;
            foreach ($bodies[$person] as $url => $body) {
                foreach ($needles->find($body) as $needle => $snippet) {
                    $allowed = $admin && $needles->namesVehicle((string) $needle) && self::onRoute($url, self::ADMIN_SEES_NAMES);
                    if ($allowed) {
                        continue;
                    }
                    $leaks[] = sprintf('%s sees %s (%s) at %s: …%s…', $person, $needle, $needles->label($needle), $url, $snippet);
                }
            }
        }

        self::assertSame([], $leaks, count($leaks) . " leaks:\n" . implode("\n", $leaks));
    }

    /**
     * The household on a fresh database, today pinned.
     *
     * @return array{App<ContainerInterface>, Household}
     */
    private function household(): array
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, InputBuilder::TODAY_UTC);
        $this->resetDatabase($app);

        return [$app, Household::load($app->getContainer(), self::PASSWORD)];
    }

    /**
     * Each URL's body as one person, binary files left out (a 200 for one
     * is checked against what they may see).
     *
     * @param App<ContainerInterface> $app
     * @param list<array{route: string, url: string, api: bool}> $urls
     * @return array<string, string> "GET url" => body
     */
    private function responses(App $app, Household $household, string $person, array $urls): array
    {
        $browser = $this->browserFor($app, $person);
        $api = new ApiClient($app, $this->apiKey($app, $household->user($person), ApiScope::Read));
        $bodies = [];
        foreach ($urls as $entry) {
            $response = $entry['api'] ? $api->get($entry['url']) : $this->page($browser, $entry['url']);
            $label = ($entry['api'] ? 'API ' : '') . $entry['url'] . ' [' . $entry['route'] . ']';
            $type = $response->getHeaderLine('Content-Type');
            foreach (self::BINARY as $binary) {
                if (str_starts_with($type, $binary)) {
                    continue 2;
                }
            }
            $bodies[$label] = self::body($response);
        }

        return $bodies;
    }

    /**
     * The app's money figures for the golden rows (expected.csv), as each
     * row's viewer: totals and rates, with the vehicles they cover ("fleet"
     * and "all" cover the viewer's vehicles in the row's currency).
     *
     * @return list<array{vehicles: list<string>, value: string, label: string}>
     */
    private static function figures(ContainerInterface $container, Household $household): array
    {
        $golden = new GoldenFigures($container, $household->golden);
        $currency = array_column(ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/vehicles.csv'), 'currency', 'vehicle');
        $figures = [];
        foreach (ExpectedSkeleton::read(self::EXPECTED) as $row) {
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
     * @param list<string> $routes
     */
    private static function onRoute(string $label, array $routes): bool
    {
        foreach ($routes as $route) {
            if (str_ends_with($label, '[' . $route . ']')) {
                return true;
            }
        }

        return false;
    }

    private function page(TestBrowser $browser, string $url): ResponseInterface
    {
        return $browser->get($url);
    }

    /**
     * The person's needles that Alex sees somewhere; each kind must keep
     * some, or its search would find nothing whatever the app did.
     *
     * @param array<string, string> $control
     */
    private function checked(LeakNeedles $needles, array $control, string $person): LeakNeedles
    {
        $seen = [];
        foreach ($control as $body) {
            foreach (array_keys($needles->find($body)) as $needle) {
                $seen[(string) $needle] = true;
            }
        }
        $kept = $needles->only($seen);
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
        fwrite(STDERR, sprintf(
            "\n%s: %d of %d amounts and %d of %d texts are seen by the control\n",
            $person,
            count($kept->amounts),
            count($needles->amounts),
            count($kept->texts),
            count($needles->texts),
        ));

        return $kept;
    }
}
