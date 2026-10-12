<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Household;

use Logbook\Domain\Api\ApiScope;
use Logbook\Service\Ai\Ask\Tool\Draft\DraftTool;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Golden\ExpectedSkeleton;
use Logbook\Tests\Support\Golden\GoldenHousehold;
use Logbook\Tests\Support\Household\Household;
use Logbook\Tests\Support\Household\LeakSweeping;
use Logbook\Tests\Support\Household\SweepUrls;
use Logbook\Tests\Support\McpClient;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The leak sweep (Phase 45.2, spec.md §5, §7.21): everything the app
 * answers, as each person of the household with less than everything,
 * scanned for what they must not see (LeakNeedles): another vehicle, an
 * amount whose costs are hidden from them, another driver's places.
 *
 * - Every GET route of the app and the API, for each vehicle, taken from
 *   the router (SweepUrls), so a new route is swept without being listed:
 *   pages, print views, CSV exports, the API's lists and items.
 * - Every read tool Ask and MCP offer, for each vehicle and for all of
 *   them (#418: the tools' results, not a model's answers).
 *
 * The calendar feed, the digest and webhooks are LeakSweepDeliveryTest.
 */
final class LeakSweepTest extends AppTestCase
{
    use ApiFixtures;
    use LeakSweeping;

    /** Files are their own check (AccessMatrixTest): only text is scanned. */
    private const array BINARY = ['image/', 'application/pdf', 'application/zip', 'application/octet-stream'];

    public function testNoPageOrApiResponseShowsWhatItShouldNot(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $household = $this->loadHousehold($app);
        $urls = (new SweepUrls($app, $this->connection($app)))->all(self::vehicleIds($household));
        self::assertGreaterThan(500, count($urls), 'every GET route, for each vehicle');

        $sent = [];
        foreach (Household::people() as $person) {
            $sent[$person] = $this->responses($app, $household, $person, $urls);
        }

        $leaks = $this->leaks($app, $household, $sent);
        self::assertSame([], $leaks, count($leaks) . " leaks:\n" . implode("\n", $leaks));
    }

    public function testNoAskOrMcpToolResultShowsWhatItShouldNot(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $household = $this->loadHousehold($app);
        $registry = $this->service($app, ToolRegistry::class);

        $sent = [];
        $calls = 0;
        foreach (Household::people() as $person) {
            $user = $household->user($person);
            $mcp = new McpClient($app, $this->apiKey($app, $user, ApiScope::Read));
            $listed = McpClient::result($mcp->modern('tools/list'))->column('name', 'tools');
            foreach ($registry->available($user) as $tool) {
                if ($tool instanceof DraftTool) {
                    continue;
                }
                $name = $tool->name();
                self::assertContains($name, $listed, "{$person} gets {$name} over MCP too");
                foreach (self::arguments($tool->definition()->parameters, $household) as $arguments) {
                    $label = $name . ' ' . json_encode($arguments);
                    $run = $registry->run($user, new ToolCall('sweep', $name, $arguments));
                    $sent[$person]['Ask ' . $label] = (string) json_encode([
                        $run->result?->data,
                        $run->result?->figures,
                        $run->error,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $sent[$person]['MCP ' . $label] = self::body($mcp->modern('tools/call', [
                        'name' => $name,
                        'arguments' => (object) $arguments,
                    ]));
                    $calls++;
                }
            }
        }
        self::assertGreaterThan(300, $calls, 'every read tool, for each vehicle, as each person');

        $leaks = $this->leaks($app, $household, $sent);
        self::assertSame([], $leaks, count($leaks) . " leaks:\n" . implode("\n", $leaks));
    }

    /**
     * Each URL's body as one person, binary files left out.
     *
     * @param App<ContainerInterface> $app
     * @param list<array{route: string, url: string, api: bool}> $urls
     * @return array<string, string> "url [route]" => body
     */
    private function responses(App $app, Household $household, string $person, array $urls): array
    {
        $browser = $this->browserFor($app, $person);
        $api = new ApiClient($app, $this->apiKey($app, $household->user($person), ApiScope::Read));
        $bodies = [];
        foreach ($urls as $entry) {
            $response = $entry['api'] ? $api->get($entry['url']) : $browser->get($entry['url']);
            $type = $response->getHeaderLine('Content-Type');
            foreach (self::BINARY as $binary) {
                if (str_starts_with($type, $binary)) {
                    continue 2;
                }
            }
            $bodies[($entry['api'] ? 'API ' : '') . $entry['url'] . ' [' . $entry['route'] . ']'] = self::body($response);
        }

        return $bodies;
    }

    /**
     * The calls to make of one tool, from its parameters: none given (all
     * vehicles), then each vehicle; a period of all time where it takes one;
     * each vehicle's make as a search.
     *
     * @param array<string, mixed> $parameters a JSON schema
     * @return list<array<string, mixed>>
     */
    private static function arguments(array $parameters, Household $household): array
    {
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        $base = [];
        if (isset($properties['period'])) {
            $base['period'] = is_array($properties['period']['enum'] ?? null)
                && in_array('since_bought', $properties['period']['enum'], true) ? 'since_bought' : 'all_time';
        }
        if (isset($properties['by_year'])) {
            $base['by_year'] = true;
        }
        $calls = [$base];
        foreach (self::vehicleIds($household) as $id) {
            if (isset($properties['vehicle'])) {
                $calls[] = ['vehicle' => $id] + $base;
            }
            if (isset($properties['vehicles'])) {
                $calls[] = ['vehicles' => [$id]] + $base;
            }
        }
        if (isset($properties['query'])) {
            $calls = [['query' => '']];
            foreach (ExpectedSkeleton::read(GoldenHousehold::INPUTS . '/vehicles.csv') as $row) {
                // By make: a search echoes its words, so a hidden registration must come from a match.
                $calls[] = ['query' => $row['make']];
            }
        }

        return $calls;
    }

    /**
     * @return list<int>
     */
    private static function vehicleIds(Household $household): array
    {
        return array_values(array_map(static fn ($v): int => $v->id, $household->vehicles()));
    }
}
