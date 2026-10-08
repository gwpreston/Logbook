<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory\Uk;

use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\FuelPrices\ProviderLicence;
use Logbook\Service\MotHistory\MotHistoryClient;
use Logbook\Service\MotHistory\MotHistoryErrorCode;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\MotHistoryProvider;
use Logbook\Support\Version\InstalledVersion;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DVSA's MOT history API (spec.md §4, §7.38): cars, motorcycles and vans in
 * Great Britain since 2005 and Northern Ireland since 2017. Free to
 * individuals (#320).
 *
 * Signing in is an OAuth 2 client-credentials token from the Microsoft
 * token URL DVSA issues, which must be that host (it receives the client
 * secret); every request then carries the token and the `X-API-Key`.
 */
final readonly class DvsaProvider implements MotHistoryProvider
{
    public const string CODE = 'uk_dvsa';
    public const string BASE = 'https://history.mot.api.gov.uk';
    public const string SCOPE = 'https://tapi.dvsa.gov.uk/.default';
    public const string TOKEN_URL = '#^https://login\.microsoftonline\.com/[A-Za-z0-9.-]{1,100}/oauth2/v2\.0/token$#';
    public const int TIMEOUT = 10;
    public const int MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private HttpClientInterface $http,
        private InstalledVersion $installed,
        private LoggerInterface $logger,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function nameKey(): string
    {
        return 'mot_history.provider.uk_dvsa.name';
    }

    public function descriptionKey(): string
    {
        return 'mot_history.provider.uk_dvsa.description';
    }

    public function sendsKey(): string
    {
        return 'mot_history.provider.uk_dvsa.sends';
    }

    public function licence(): ProviderLicence
    {
        return new ProviderLicence(
            'Open Government Licence v3.0',
            'mot_history.attribution',
            'https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/',
        );
    }

    public function credentials(): array
    {
        return [
            'client_id' => 'mot_history.credentials.client_id',
            'client_secret' => 'mot_history.credentials.client_secret',
            'api_key' => 'mot_history.credentials.api_key',
            'token_url' => 'mot_history.credentials.token_url',
        ];
    }

    public function acceptsCredential(string $slot, string $value): bool
    {
        return $slot !== 'token_url' || preg_match(self::TOKEN_URL, $value) === 1;
    }

    public function countries(): array
    {
        return ['GB'];
    }

    public function connect(ProviderCredentials $credentials): MotHistoryClient
    {
        $id = trim($credentials->get('client_id'));
        $secret = trim($credentials->get('client_secret'));
        $key = trim($credentials->get('api_key'));
        $url = trim($credentials->get('token_url'));
        if ($id === '' || $secret === '' || $key === '' || $url === '') {
            throw new MotHistoryFailure(MotHistoryErrorCode::Credentials);
        }
        // Checked again here: an `env:` value is read only now.
        if (!$this->acceptsCredential('token_url', $url)) {
            throw new MotHistoryFailure(MotHistoryErrorCode::TokenUrl);
        }

        $this->logger->info('POST DVSA token');
        $client = new DvsaClient($this->http, $this->logger, $this->userAgent(), $key, '');
        $data = $client->send('POST', $url, [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $id,
                'client_secret' => $secret,
                'scope' => self::SCOPE,
            ],
        ], true);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || preg_match('/^[\x21-\x7E]{8,8192}$/', $token) !== 1) {
            throw new MotHistoryFailure(MotHistoryErrorCode::Unauthorised);
        }

        return new DvsaClient($this->http, $this->logger, $this->userAgent(), $key, $token);
    }

    private function userAgent(): string
    {
        return sprintf('Logbook/%s (self-hosted vehicle log)', $this->installed->version);
    }
}
