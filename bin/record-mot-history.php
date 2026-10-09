<?php

declare(strict_types=1);

/*
 * Record real DVSA MOT history answers for the tests (spec.md §4, §7.38;
 * Phase 41, tests/Fixtures/mot-history/README.md).
 *
 *   DVSA_CLIENT_ID=… DVSA_CLIENT_SECRET=… DVSA_API_KEY=… DVSA_TOKEN_URL=… \
 *       php bin/record-mot-history.php AB12CDE [MORE PLATES…]
 *
 * Signs in, asks for the bulk download list (#327: does it accept this
 * key?) and each registration, and writes them to
 * tests/Fixtures/mot-history/recorded-*.json, which DvsaRecordedTest then
 * checks. Before anything is written the registration, VIN and test
 * numbers are replaced by placeholders, and the bulk list keeps only its
 * shape (its links are signed). The credentials are read from the
 * environment only and never written anywhere. Maintainers only: the app
 * itself never runs this.
 *
 * Exit code: 0 recorded, 1 DVSA could not be read, 3 usage.
 */

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = 'https://history.mot.api.gov.uk';
$id = (string) getenv('DVSA_CLIENT_ID');
$secret = (string) getenv('DVSA_CLIENT_SECRET');
$key = (string) getenv('DVSA_API_KEY');
$tokenUrl = (string) getenv('DVSA_TOKEN_URL');
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$plates = [];
foreach (array_slice($args, 1) as $arg) {
    $plate = strtoupper((string) preg_replace('/[\s-]+/', '', $arg));
    if (preg_match('/^[A-Z0-9]{1,8}$/', $plate) !== 1) {
        fwrite(STDERR, "Usage: php bin/record-mot-history.php REGISTRATION [MORE…]\n");
        exit(3);
    }
    $plates[] = $plate;
}
if ($plates === [] || $id === '' || $secret === '' || $key === '' || $tokenUrl === '') {
    fwrite(STDERR, "Set DVSA_CLIENT_ID, DVSA_CLIENT_SECRET, DVSA_API_KEY and DVSA_TOKEN_URL, and name a registration.\n");
    exit(3);
}
if (preg_match('#^https://login\.microsoftonline\.com/[A-Za-z0-9.-]{1,100}/oauth2/v2\.0/token$#', $tokenUrl) !== 1) {
    fwrite(STDERR, "DVSA_TOKEN_URL must be https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token.\n");
    exit(3);
}

$http = HttpClient::create(['timeout' => 10, 'max_redirects' => 0, 'headers' => ['User-Agent' => 'Logbook fixture recorder']]);

/**
 * Replaces what identifies a vehicle with placeholders, keeping the shape.
 *
 * @param array<mixed> $vehicle
 * @return array<mixed>
 */
$scrub = static function (array $vehicle, int $n): array {
    $plate = sprintf('REC%03d', $n);
    $vehicle['registration'] = $plate;
    unset($vehicle['vin']);
    $tests = [];
    foreach (is_array($vehicle['motTests'] ?? null) ? array_values($vehicle['motTests']) : [] as $i => $test) {
        if (is_array($test)) {
            if (is_string($test['registrationAtTimeOfTest'] ?? null)) {
                $test['registrationAtTimeOfTest'] = $plate;
            }
            if (is_string($test['motTestNumber'] ?? null)) {
                $test['motTestNumber'] = sprintf('9%03d%08d', $n, $i);
            }
            if (is_string($test['location'] ?? null)) {
                $test['location'] = 'Test facility';
            }
        }
        $tests[] = $test;
    }
    $vehicle['motTests'] = $tests;

    return $vehicle;
};

$dir = dirname(__DIR__) . '/tests/Fixtures/mot-history';
try {
    $token = $http->request('POST', $tokenUrl, ['body' => [
        'grant_type' => 'client_credentials',
        'client_id' => $id,
        'client_secret' => $secret,
        'scope' => 'https://tapi.dvsa.gov.uk/.default',
    ]])->toArray()['access_token'] ?? null;
    if (!is_string($token)) {
        throw new RuntimeException('No access token in the answer.');
    }
    $signed = ['auth_bearer' => $token, 'headers' => ['X-API-Key' => $key]];

    $bulk = $http->request('GET', $base . '/v1/trade/vehicles/bulk-download', $signed);
    $status = $bulk->getStatusCode();
    printf("bulk-download: HTTP %d%s\n", $status, $status === 200 ? ' (the keep-alive works with this key)' : '');
    if ($status === 200) {
        $list = $bulk->toArray();
        $shape = [];
        foreach (['bulk', 'delta'] as $kind) {
            $shape[$kind] = array_map(static fn (mixed $file): array => [
                'filename' => is_array($file) && is_string($file['filename'] ?? null) ? $file['filename'] : '',
                'downloadUrl' => 'https://example.invalid/signed-link-removed',
                'fileSize' => is_array($file) ? ($file['fileSize'] ?? 0) : 0,
                'fileCreatedOn' => is_array($file) ? ($file['fileCreatedOn'] ?? '') : '',
            ], array_slice(is_array($list[$kind] ?? null) ? $list[$kind] : [], 0, 2));
        }
        $json = json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($dir . '/recorded-bulk-download.json', $json . "\n");
    }

    foreach ($plates as $n => $plate) {
        usleep(200_000);
        $response = $http->request('GET', $base . '/v1/trade/vehicles/registration/' . rawurlencode($plate), $signed);
        if ($response->getStatusCode() !== 200) {
            printf("vehicle %d: HTTP %d, not recorded\n", $n + 1, $response->getStatusCode());
            continue;
        }
        $vehicle = $scrub($response->toArray(), $n + 1);
        $tests = $vehicle['motTests'];
        file_put_contents(
            sprintf('%s/recorded-vehicle-%d.json', $dir, $n + 1),
            json_encode($vehicle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
        printf("vehicle %d: %d test(s) recorded\n", $n + 1, count($tests));
    }
} catch (Throwable $e) {
    $message = str_replace([$secret, $key], '••••', $e->getMessage());
    fwrite(STDERR, 'DVSA could not be read: ' . $message . "\n");
    exit(1);
}
