<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory\Uk;

use JsonException;
use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Service\MotHistory\MotHistoryClient;
use Logbook\Service\MotHistory\MotHistoryErrorCode;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\VehicleIdentifier;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * DVSA signed in, for one fetch or one job run (spec.md §7.38 *Requests*):
 * 10 s and 2 MiB per answer, no redirects, no retries (a `429` stops the
 * job until the next day). A `404` is an answer: no record.
 */
final readonly class DvsaClient implements MotHistoryClient
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private string $userAgent,
        #[SensitiveParameter] private string $apiKey,
        #[SensitiveParameter] private string $token,
    ) {
    }

    public function byRegistration(string $registration): ?MotVehicleRecord
    {
        $plate = VehicleIdentifier::registration($registration)
            ?? throw new MotHistoryFailure(MotHistoryErrorCode::InvalidIdentifier);

        return $this->vehicle('/v1/trade/vehicles/registration/' . rawurlencode($plate));
    }

    public function byVin(string $vin): ?MotVehicleRecord
    {
        $clean = VehicleIdentifier::vin($vin)
            ?? throw new MotHistoryFailure(MotHistoryErrorCode::InvalidIdentifier);

        return $this->vehicle('/v1/trade/vehicles/vin/' . rawurlencode($clean));
    }

    public function ping(): void
    {
        $this->logger->info('GET DVSA bulk-download (no vehicle sent)');
        $data = $this->send('GET', DvsaProvider::BASE . '/v1/trade/vehicles/bulk-download', $this->signed(), true);
        if (!array_key_exists('bulk', $data)) {
            throw new MotHistoryFailure(MotHistoryErrorCode::InvalidResponse);
        }
    }

    private function vehicle(string $path): ?MotVehicleRecord
    {
        $this->logger->info('GET DVSA vehicle');
        $data = $this->send('GET', DvsaProvider::BASE . $path, $this->signed(), false);
        if ($data === null) {
            return null;
        }

        return DvsaParser::vehicle($data) ?? throw new MotHistoryFailure(MotHistoryErrorCode::InvalidResponse);
    }

    /**
     * @return array<string, mixed>
     */
    private function signed(): array
    {
        return ['auth_bearer' => $this->token, 'headers' => ['X-API-Key' => $this->apiKey]];
    }

    /**
     * One request and its decoded answer: null for a `404` unless
     * $required, a failure for anything else but `200`.
     *
     * @param array<string, mixed> $options
     * @return ($required is true ? array<mixed> : array<mixed>|null)
     */
    public function send(string $method, string $url, array $options, bool $required): ?array
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options = [
            'headers' => $headers + ['Accept' => 'application/json', 'User-Agent' => $this->userAgent],
            'timeout' => DvsaProvider::TIMEOUT,
            'max_duration' => DvsaProvider::TIMEOUT,
            'max_redirects' => 0,
        ] + $options;
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status === 404 && !$required) {
                $response->cancel();

                return null;
            }
            if ($status !== 200) {
                $response->cancel();
                throw new MotHistoryFailure(match (true) {
                    $status === 429 => MotHistoryErrorCode::RateLimited,
                    $status === 401, $status === 403, $status === 400 && $method === 'POST' => MotHistoryErrorCode::Unauthorised,
                    $status === 400 => MotHistoryErrorCode::BadRequest,
                    default => MotHistoryErrorCode::HttpStatus,
                }, in_array($status, [400, 401, 403, 429], true) ? [] : ['status' => (string) $status]);
            }

            return $this->decode($this->body($response));
        } catch (TimeoutExceptionInterface) {
            throw new MotHistoryFailure(MotHistoryErrorCode::Timeout);
        } catch (TransportExceptionInterface $e) {
            throw new MotHistoryFailure(MotHistoryErrorCode::Network, ['reason' => self::reason($e->getMessage(), $url)]);
        }
    }

    /**
     * A transport error's message without the vehicle: the request URL
     * (whose last segment is the registration or VIN) is cut to its host,
     * and any `/registration/…` or `/vin/…` path left is dropped (spec.md
     * §7.38 *Requests*). The stored status and the job output never carry
     * a plate.
     */
    private static function reason(string $message, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $message = str_replace($url, is_string($host) ? $host : '', $message);
        $message = (string) preg_replace('#/(?:registration|vin)/[^\s"\'<>?&]*#i', '/…', $message);

        return mb_substr($message, 0, 200);
    }

    private function body(ResponseInterface $response): string
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if ($length !== null && ctype_digit($length) && (int) $length > DvsaProvider::MAX_BYTES) {
            $response->cancel();
            throw new MotHistoryFailure(MotHistoryErrorCode::TooLarge);
        }
        $body = '';
        foreach ($this->http->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > DvsaProvider::MAX_BYTES) {
                $response->cancel();
                throw new MotHistoryFailure(MotHistoryErrorCode::TooLarge);
            }
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new MotHistoryFailure(MotHistoryErrorCode::InvalidResponse);
        }

        return $data;
    }
}
