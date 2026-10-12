<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Household;

use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Slim\App;
use Slim\Interfaces\RouteInterface;

/**
 * Every GET route the app has, with its placeholders filled from the
 * household's own rows, for Phase 45.2's leak sweep. Taken from the router,
 * not a list, so a new route is swept without being named here. A new
 * placeholder must be named in PLACEHOLDERS (with the table its ids come
 * from, or as one that takes no id), or the sweep fails and says which.
 *
 * A vehicle route is requested for each of the household's vehicles. An
 * id inside one comes from that vehicle's rows: its first and last.
 */
final class SweepUrls
{
    /**
     * Placeholder => the table its ids come from; null takes the route's
     * own pattern (a token, a name, a kind).
     *
     * @var array<string, string|null>
     */
    private const array PLACEHOLDERS = [
        'reading' => 'odometer_readings',
        'schedule' => 'maintenance_schedules',
        'change' => 'tyre_changes',
        'tyre' => 'tyres',
        'issue' => 'issues',
        'update' => 'issue_updates',
        'incident' => 'incidents',
        'agreement' => 'finance_agreements',
        'document' => 'compliance_documents',
        'attachment' => 'attachments',
        'reminder' => 'reminders',
        'member' => 'users',
        'station' => 'stations',
        'connection' => 'ai_connections',
        'journey' => 'saved_journeys',
        'key' => 'api_keys',
        'place' => 'places',
        'webhook' => 'webhooks',
        'run' => 'job_runs',
        'thread' => 'ai_threads',
        // {set} is a tyre set under a vehicle, a mileage rate set in Settings.
        'set' => 'tyre_sets|mileage_rate_sets',
        // {entry} names its table by the path segment before it (ENTRIES).
        'entry' => '',
        'token' => null,
        'name' => null,
        'kind' => null,
        'action' => null,
        'module' => null,
        'job' => null,
    ];

    /** The path segment before {entry} => its table. */
    private const array ENTRIES = [
        'fuel' => 'fuel_entries',
        'maintenance' => 'maintenance_entries',
        'expenses' => 'expense_entries',
        'documents' => 'compliance_documents',
        'odometer' => 'odometer_readings',
        'trips' => 'trips',
        'incidents' => 'incidents',
        'issues' => 'issues',
        'valuations' => 'vehicle_valuations',
        'schedules' => 'maintenance_schedules',
    ];

    /**
     * Not swept as a page: the MCP endpoint (the sweep calls its tools), and
     * the scheduler's URL, which runs the jobs.
     */
    private const array SKIP = ['mcp', 'scheduler.url'];

    /** Tables without a vehicle_id column. */
    private const array NOT_PER_VEHICLE = [
        'users', 'stations', 'ai_connections', 'saved_journeys', 'api_keys', 'places', 'webhooks',
        'job_runs', 'ai_threads', 'mileage_rate_sets', 'issue_updates',
    ];

    /** @var array<string, list<int>> */
    private array $ids = [];

    /**
     * @param App<ContainerInterface> $app
     */
    public function __construct(private readonly App $app, private readonly Connection $db)
    {
    }

    /**
     * @param list<int> $vehicleIds
     * @return list<array{route: string, url: string, api: bool}>
     */
    public function all(array $vehicleIds): array
    {
        $urls = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            if (!in_array('GET', $route->getMethods(), true) || in_array($route->getName(), self::SKIP, true)) {
                continue;
            }
            $pattern = $route->getPattern();
            $vehicles = str_contains($pattern, '{id') ? $vehicleIds : [null];
            foreach ($vehicles as $vehicleId) {
                foreach ($this->expand($route, $pattern, $vehicleId) as $url) {
                    $api = str_starts_with($url, '/api/v1/');
                    $urls[] = [
                        'route' => (string) $route->getName(),
                        'url' => $api ? substr($url, strlen('/api/v1')) : $url,
                        'api' => $api,
                    ];
                }
            }
        }

        return $urls;
    }

    /**
     * Every way to fill the pattern's placeholders, left to right.
     *
     * @return list<string>
     */
    private function expand(RouteInterface $route, string $pattern, ?int $vehicleId): array
    {
        $found = preg_match('/\{(\w+)(?::((?:[^{}]|\{\d+\})+))?\}/', $pattern, $m, PREG_OFFSET_CAPTURE);
        if ($found !== 1) {
            return [$pattern];
        }
        $name = $m[1][0];
        $regex = $m[2][0] ?? '[0-9]+';
        $before = substr($pattern, 0, $m[0][1]);
        $after = substr($pattern, $m[0][1] + strlen($m[0][0]));
        $values = $name === 'id' ? [(string) $vehicleId] : $this->values($route, $name, $regex, $before, $vehicleId);

        $urls = [];
        foreach ($values as $value) {
            foreach ($this->expand($route, $after, $vehicleId) as $rest) {
                $urls[] = $before . $value . $rest;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function values(RouteInterface $route, string $name, string $regex, string $before, ?int $vehicleId): array
    {
        if (!array_key_exists($name, self::PLACEHOLDERS)) {
            throw new RuntimeException(sprintf(
                'The leak sweep cannot fill {%s} in %s (%s): name it in %s::PLACEHOLDERS',
                $name,
                $route->getPattern(),
                (string) $route->getName(),
                self::class,
            ));
        }
        $table = self::PLACEHOLDERS[$name];
        if ($table === null) {
            return self::fromPattern($regex);
        }
        if ($name === 'entry') {
            $segments = explode('/', trim($before, '/'));
            $segment = (string) end($segments);
            $table = self::ENTRIES[$segment] ?? throw new RuntimeException(
                "The leak sweep has no table for {entry} after /{$segment}/ in " . $route->getPattern(),
            );
        }
        if ($name === 'set') {
            $table = str_contains($before, '/tyres/') ? 'tyre_sets' : 'mileage_rate_sets';
        }
        $ids = array_map('strval', $this->ids($table, str_contains($route->getPattern(), '{id') ? $vehicleId : null));

        // Nothing of that kind: an id that finds nothing, which must answer 404 and say nothing.
        return $ids === [] ? ['999999'] : $ids;
    }

    /**
     * The first and last id of a table, on one vehicle when it has a
     * vehicle_id column and a vehicle is given.
     *
     * @return list<int>
     */
    private function ids(string $table, ?int $vehicleId): array
    {
        $key = $table . '|' . ($vehicleId ?? '');
        if (isset($this->ids[$key])) {
            return $this->ids[$key];
        }
        $query = $this->db->createQueryBuilder()->select('MIN(id) AS first', 'MAX(id) AS last')->from($table);
        if ($vehicleId !== null && !in_array($table, self::NOT_PER_VEHICLE, true)) {
            $query->where('vehicle_id = :vehicle')->setParameter('vehicle', $vehicleId);
        }
        $ids = [];
        foreach ($query->executeQuery()->fetchAssociative() ?: [] as $id) {
            // Each engine returns MIN and MAX as an int or a numeric string; NULL on an empty table.
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        return $this->ids[$key] = $ids;
    }

    /**
     * Values a pattern accepts: each alternative of a|b|c, else one made
     * to fit (a token of the right length).
     *
     * @return list<string>
     */
    private static function fromPattern(string $regex): array
    {
        if (preg_match('/^[a-z0-9_-]+(\|[a-z0-9_-]+)+$/', $regex) === 1) {
            return explode('|', $regex);
        }
        $value = (string) preg_replace_callback(
            '/\[([^\]]+)\](?:\{(\d+)\}|(\+))?/',
            static function (array $m): string {
                $class = $m[1];
                $char = match (true) {
                    str_contains($class, 'a-f') || str_contains($class, '0-9a') => 'a',
                    str_contains($class, '0-9') => '1',
                    default => 'a',
                };
                $times = ($m[2] ?? '') !== '' ? (int) $m[2] : 1;

                return str_repeat($char, $times);
            },
            $regex,
        );

        return [str_replace(['\\.', '\\'], ['.', ''], $value)];
    }
}
