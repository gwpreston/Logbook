<?php

declare(strict_types=1);

/*
 * Record a trimmed real UK Fuel Finder download for the tests (spec.md §4,
 * §7.34; decided 2026-10-03, docs/phases/open-questions.md #139).
 *
 *   FUEL_FINDER_CLIENT_ID=… FUEL_FINDER_CLIENT_SECRET=… \
 *       php bin/record-fuel-finder.php [--stations=20]
 *
 * Fetches a token and the first page of stations and of prices, keeps the
 * first N stations (and the prices of those), and writes them to
 * tests/Fixtures/fuel-finder/recorded-pfs.json and recorded-fuel-prices.json,
 * which FuelFinderRecordedTest then checks. The credentials are read from
 * the environment only and never written anywhere. Maintainers only: the
 * app itself never runs this.
 *
 * Exit code: 0 recorded, 1 the feed could not be read, 3 usage.
 */

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = 'https://www.fuel-finder.service.gov.uk';
$id = (string) getenv('FUEL_FINDER_CLIENT_ID');
$secret = (string) getenv('FUEL_FINDER_CLIENT_SECRET');
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$count = 20;
foreach (array_slice($args, 1) as $arg) {
    if (preg_match('/^--stations=(\d{1,3})$/', $arg, $m) === 1) {
        $count = max(1, (int) $m[1]);
    } else {
        fwrite(STDERR, "Usage: php bin/record-fuel-finder.php [--stations=20]\n");
        exit(3);
    }
}
if ($id === '' || $secret === '') {
    fwrite(STDERR, "Set FUEL_FINDER_CLIENT_ID and FUEL_FINDER_CLIENT_SECRET.\n");
    exit(3);
}

$http = HttpClient::create(['timeout' => 30, 'max_redirects' => 0, 'headers' => ['User-Agent' => 'Logbook fixture recorder']]);

try {
    $token = $http->request('POST', $base . '/api/v1/oauth/generate_access_token', [
        'json' => ['client_id' => $id, 'client_secret' => $secret],
    ])->toArray();
    $payload = is_array($token['data'] ?? null) ? $token['data'] : $token;
    $access = $payload['access_token'] ?? null;
    if (!is_string($access)) {
        throw new RuntimeException('No access token in the answer.');
    }
    $page = static function (string $path) use ($http, $base, $access): array {
        usleep(2_500_000);
        $data = $http->request('GET', $base . $path, [
            'query' => ['batch-number' => '1'],
            'auth_bearer' => $access,
        ])->toArray();

        return array_is_list($data) ? $data : (is_array($data['data'] ?? null) ? $data['data'] : []);
    };
    $stations = array_slice($page('/api/v1/pfs'), 0, $count);
    $ids = array_flip(array_filter(array_column($stations, 'node_id'), 'is_string'));
    $prices = array_values(array_filter(
        $page('/api/v1/pfs/fuel-prices'),
        static fn (mixed $record): bool => is_array($record)
            && is_string($record['node_id'] ?? null)
            && isset($ids[$record['node_id']]),
    ));
} catch (Throwable $e) {
    fwrite(STDERR, 'The feed could not be read: ' . $e->getMessage() . "\n");
    exit(1);
}

$dir = dirname(__DIR__) . '/tests/Fixtures/fuel-finder';
file_put_contents(
    $dir . '/recorded-pfs.json',
    json_encode($stations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);
file_put_contents(
    $dir . '/recorded-fuel-prices.json',
    json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);
printf("Recorded %d station(s) and the prices of %d.\n", count($stations), count($prices));
