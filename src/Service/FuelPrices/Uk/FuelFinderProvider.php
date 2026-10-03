<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices\Uk;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\ProviderKind;
use Logbook\Service\FuelPrices\BulkPriceProvider;
use Logbook\Service\FuelPrices\FeedErrorCode;
use Logbook\Service\FuelPrices\FeedFailure;
use Logbook\Service\FuelPrices\FeedReport;
use Logbook\Service\FuelPrices\FeedSink;
use Logbook\Service\FuelPrices\Pause;
use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\FuelPrices\ProviderLicence;
use Logbook\Support\Version\InstalledVersion;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * UK Fuel Finder (spec.md §4, §7.34): the statutory open price feed under
 * the Motor Fuel Price (Open Data) Regulations 2025, read through its
 * Information Recipient API.
 *
 * - A bearer token from the client ID and secret at the start of each run,
 *   never stored.
 * - Stations (`/api/v1/pfs`), then prices (`/api/v1/pfs/fuel-prices`), page
 *   by page (`batch-number` from 1; a page of fewer than 500 records is the
 *   last), with `effective-start-timestamp` for changes only.
 * - One request at a time, 2.5 s apart (30 a minute are allowed), backing
 *   off on 429; 30 s and 16 MiB per response; no redirects.
 */
final readonly class FuelFinderProvider implements BulkPriceProvider
{
    public const string CODE = 'uk_fuel_finder';
    public const string BASE = 'https://www.fuel-finder.service.gov.uk';
    public const string TOKEN_PATH = '/api/v1/oauth/generate_access_token';
    public const string STATIONS_PATH = '/api/v1/pfs';
    public const string PRICES_PATH = '/api/v1/pfs/fuel-prices';
    public const int PAGE_SIZE = 500;
    /** Far beyond the UK's ~8,500 stations: a feed that never ends stops here. */
    public const int MAX_PAGES = 60;
    public const int TIMEOUT = 30;
    public const int MAX_BYTES = 16 * 1024 * 1024;
    public const float SPACING = 2.5;
    /** Waits after a 429 that names none, in turn; then the run fails. */
    private const array BACKOFF = [10, 30, 60];
    private const int MAX_WAIT = 120;

    public function __construct(
        private HttpClientInterface $http,
        private Pause $pause,
        private InstalledVersion $installed,
        private LoggerInterface $logger,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function kind(): ProviderKind
    {
        return ProviderKind::Bulk;
    }

    public function nameKey(): string
    {
        return 'fuel_prices.provider.uk_fuel_finder.name';
    }

    public function descriptionKey(): string
    {
        return 'fuel_prices.provider.uk_fuel_finder.description';
    }

    public function sendsKey(): string
    {
        return 'fuel_prices.provider.bulk_sends';
    }

    public function licence(): ProviderLicence
    {
        return new ProviderLicence(
            'Open Government Licence v3.0',
            'fuel_prices.attribution.ogl',
            'https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/',
        );
    }

    public function credentials(): array
    {
        return [
            'client_id' => 'fuel_prices.credentials.client_id',
            'client_secret' => 'fuel_prices.credentials.client_secret',
        ];
    }

    public function host(): string
    {
        return 'www.fuel-finder.service.gov.uk';
    }

    public function minimumRefreshMinutes(): int
    {
        return 30;
    }

    public function currency(): string
    {
        return 'GBP';
    }

    public function gradeChoices(): array
    {
        // One E5 price; UK super unleaded is 97 RON at most forecourts (#136).
        return ['E5' => [FuelGrade::E5_97, FuelGrade::E5_98, FuelGrade::E5_99]];
    }

    public function gradeMap(array $chosen = []): array
    {
        $e5 = $chosen['E5'] ?? FuelGrade::E5_97;
        if (!in_array($e5, $this->gradeChoices()['E5'], true)) {
            $e5 = FuelGrade::E5_97;
        }

        return [
            'E10' => FuelGrade::E10_95,
            'E5' => $e5,
            'B7_STANDARD' => FuelGrade::B7,
            'B7' => FuelGrade::B7,
            'B7_PREMIUM' => FuelGrade::B7Premium,
            'SDV' => FuelGrade::B7Premium,
            'B10' => FuelGrade::B10,
            'HVO' => FuelGrade::Xtl,
        ];
    }

    public function sync(
        ProviderCredentials $credentials,
        array $gradeMap,
        ?DateTimeImmutable $since,
        FeedSink $sink,
        Closure $cancelled,
    ): FeedReport {
        $report = new FeedReport();
        $token = $this->token($credentials, $report);
        $query = $since === null
            ? []
            : ['effective-start-timestamp' => $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];

        $this->pages(self::STATIONS_PATH, $query, $token, $report, $cancelled, function (
            array $records,
        ) use (
            $sink,
            $gradeMap,
            $report,
        ): void {
            $stations = FuelFinderParser::stations($records, $gradeMap, $report);
            $report->stations += count($stations);
            $sink->stations($stations);
        });
        $this->pages(self::PRICES_PATH, $query, $token, $report, $cancelled, function (
            array $records,
        ) use (
            $sink,
            $gradeMap,
            $report,
        ): void {
            $prices = FuelFinderParser::prices($records, $gradeMap, $report);
            $report->prices += count($prices);
            $sink->prices($prices);
        });

        return $report;
    }

    private function token(ProviderCredentials $credentials, FeedReport $report): string
    {
        $id = trim($credentials->get('client_id'));
        $secret = trim($credentials->get('client_secret'));
        if ($id === '' || $secret === '') {
            throw new FeedFailure(FeedErrorCode::Credentials);
        }

        $this->logger->info('POST {url}', ['url' => self::BASE . self::TOKEN_PATH]);
        $data = $this->json($this->send('POST', self::TOKEN_PATH, [
            'json' => ['client_id' => $id, 'client_secret' => $secret],
        ], $report), true);
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || preg_match('/^[\x21-\x7E]{8,4096}$/', $token) !== 1) {
            throw new FeedFailure(FeedErrorCode::Unauthorised);
        }

        return $token;
    }

    /**
     * @param array<string, string> $query
     * @param Closure(): bool $cancelled
     * @param Closure(array<mixed>): void $page
     */
    private function pages(
        string $path,
        array $query,
        string $token,
        FeedReport $report,
        Closure $cancelled,
        Closure $page,
    ): void {
        for ($batch = 1; $batch <= self::MAX_PAGES; $batch++) {
            if ($cancelled()) {
                throw new FeedFailure(FeedErrorCode::Cancelled);
            }
            $this->pause->seconds(self::SPACING);
            $this->logger->info('GET {path} page {batch}', ['path' => $path, 'batch' => $batch]);
            $response = $this->send('GET', $path, [
                'query' => ['batch-number' => (string) $batch] + $query,
                'auth_bearer' => $token,
            ], $report, $batch > 1);
            if ($response === null) {
                // A page past the last answers 404 on some days.
                return;
            }
            $data = $this->json($response, false);
            $records = array_is_list($data) ? $data : ($data['data'] ?? null);
            if (!is_array($records) || !array_is_list($records)) {
                throw new FeedFailure(FeedErrorCode::InvalidResponse);
            }
            $page($records);
            if (count($records) < self::PAGE_SIZE) {
                return;
            }
        }

        throw new FeedFailure(FeedErrorCode::TooManyPages);
    }

    /**
     * One request, retried after a 429 with the wait it names (or the next
     * of BACKOFF), and the body read within the size limit.
     *
     * @param array<string, mixed> $options
     * @return ($endOnNotFound is true ? string|null : string)
     */
    private function send(string $method, string $path, array $options, FeedReport $report, bool $endOnNotFound = false): ?string
    {
        $options += [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => sprintf('Logbook/%s (self-hosted vehicle log)', $this->installed->version),
            ],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ];
        foreach ([...self::BACKOFF, null] as $wait) {
            $report->requests++;
            try {
                $response = $this->http->request($method, self::BASE . $path, $options);
                $status = $response->getStatusCode();
                if ($status === 429) {
                    $named = trim($response->getHeaders(false)['retry-after'][0] ?? '');
                    $response->cancel();
                    if ($wait === null) {
                        throw new FeedFailure(FeedErrorCode::RateLimited);
                    }
                    $seconds = ctype_digit($named) ? min((int) $named, self::MAX_WAIT) : $wait;
                    $this->logger->warning('Rate limited (429); waiting {seconds} s.', ['seconds' => $seconds]);
                    $this->pause->seconds($seconds);
                    continue;
                }
                if ($status === 404 && $endOnNotFound) {
                    $response->cancel();

                    return null;
                }
                if ($status === 401 || $status === 403 || ($status === 400 && $method === 'POST')) {
                    $response->cancel();
                    throw new FeedFailure(FeedErrorCode::Unauthorised);
                }
                if ($status !== 200) {
                    $response->cancel();
                    throw new FeedFailure(FeedErrorCode::HttpStatus, ['status' => (string) $status]);
                }

                return $this->body($response);
            } catch (TimeoutExceptionInterface) {
                throw new FeedFailure(FeedErrorCode::Timeout);
            } catch (TransportExceptionInterface $e) {
                throw new FeedFailure(FeedErrorCode::Network, ['reason' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        throw new FeedFailure(FeedErrorCode::RateLimited);
    }

    private function body(ResponseInterface $response): string
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if ($length !== null && ctype_digit($length) && (int) $length > self::MAX_BYTES) {
            $response->cancel();
            throw new FeedFailure(FeedErrorCode::TooLarge);
        }
        $body = '';
        foreach ($this->http->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                $response->cancel();
                throw new FeedFailure(FeedErrorCode::TooLarge);
            }
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function json(?string $body, bool $object): array
    {
        try {
            $data = json_decode($body ?? '', true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($object && array_is_list($data) && $data !== [])) {
            throw new FeedFailure(FeedErrorCode::InvalidResponse);
        }

        return $data;
    }
}
