<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\MotHistory;

use Closure;
use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\MotHistory\MotHistoryClient;
use Logbook\Service\MotHistory\MotHistoryErrorCode;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\Uk\DvsaProvider;
use Logbook\Support\Version\InstalledVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * DVSA's adapter against a mocked API (spec.md §4, §7.38): the sign-in is
 * Microsoft's form-encoded client-credentials request, every call carries
 * the token and the API key, a 404 is "no record", and every failure maps
 * to its own message. Nothing leaves the machine.
 */
final class DvsaProviderTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../Fixtures/mot-history/';
    private const string TOKEN_URL = 'https://login.microsoftonline.com/a1b2c3d4-tenant/oauth2/v2.0/token';
    private const string TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.synthetic.token';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testSignsInWithAFormEncodedClientCredentialsRequest(): void
    {
        $this->provider($this->answers())->connect($this->credentials());

        self::assertCount(1, $this->requests);
        [$token] = $this->requests;
        self::assertSame('POST', $token['method']);
        self::assertSame(self::TOKEN_URL, $token['url']);
        $body = $token['options']['body'] ?? null;
        self::assertIsString($body);
        parse_str($body, $form);
        self::assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'https://tapi.dvsa.gov.uk/.default',
        ], $form);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $this->headers($token));
        self::assertSame(0, $token['options']['max_redirects'] ?? null);
    }

    public function testALookupSendsTheTokenAndTheKeyAndReadsTheVehicle(): void
    {
        $vehicle = $this->provider($this->answers())->connect($this->credentials())->byRegistration('ab12 cde');

        self::assertNotNull($vehicle);
        self::assertSame('AB12CDE', $vehicle->registration);
        $lookup = $this->requests[1];
        self::assertSame('GET', $lookup['method']);
        self::assertSame('https://history.mot.api.gov.uk/v1/trade/vehicles/registration/AB12CDE', $lookup['url']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $this->headers($lookup));
        self::assertContains('X-API-Key: the-api-key', $this->headers($lookup));
    }

    public function testByVin(): void
    {
        $vehicle = $this->provider($this->answers())->connect($this->credentials())->byVin('wvwzzz1kzcw123456');

        self::assertNotNull($vehicle);
        self::assertSame('https://history.mot.api.gov.uk/v1/trade/vehicles/vin/WVWZZZ1KZCW123456', $this->requests[1]['url']);
    }

    public function testNotFoundIsNoRecord(): void
    {
        $client = $this->provider($this->answers(vehicle: fn (): ResponseInterface => new MockResponse(
            (string) file_get_contents(self::DIR . 'not-found.json'),
            ['http_code' => 404],
        )))->connect($this->credentials());

        self::assertNull($client->byRegistration('AB12CDE'));
    }

    public function testPingAsksForTheBulkListAndSendsNoVehicle(): void
    {
        $this->provider($this->answers())->connect($this->credentials())->ping();

        self::assertSame('https://history.mot.api.gov.uk/v1/trade/vehicles/bulk-download', $this->requests[1]['url']);
        self::assertContains('X-API-Key: the-api-key', $this->headers($this->requests[1]));
    }

    public function testPingNeedsTheBulkList(): void
    {
        $client = $this->provider($this->answers(vehicle: fn (): ResponseInterface => new MockResponse('{"other": []}')))
            ->connect($this->credentials());

        $this->expectFailure(MotHistoryErrorCode::InvalidResponse, static fn () => $client->ping());
    }

    public function testAPingNotFoundIsAFailure(): void
    {
        $client = $this->answering(new MockResponse('{}', ['http_code' => 404]));

        $this->expectFailure(MotHistoryErrorCode::HttpStatus, static fn () => $client->ping());
    }

    /**
     * @return iterable<string, array{int, MotHistoryErrorCode}>
     */
    public static function lookupStatuses(): iterable
    {
        yield 'throttled' => [429, MotHistoryErrorCode::RateLimited];
        yield 'refused' => [401, MotHistoryErrorCode::Unauthorised];
        yield 'forbidden' => [403, MotHistoryErrorCode::Unauthorised];
        yield 'bad registration' => [400, MotHistoryErrorCode::BadRequest];
        yield 'server error' => [500, MotHistoryErrorCode::HttpStatus];
    }

    #[DataProvider('lookupStatuses')]
    public function testLookupFailures(int $status, MotHistoryErrorCode $error): void
    {
        $client = $this->answering(new MockResponse('{}', ['http_code' => $status]));

        $failure = $this->expectFailure($error, static fn () => $client->byRegistration('AB12CDE'));
        self::assertSame($status === 500 ? ['status' => '500'] : [], $failure->parameters);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tokenStatuses(): iterable
    {
        // Microsoft answers a wrong secret with 400 (AADSTS…), as well as 401.
        yield 'bad request' => [400];
        yield 'unauthorised' => [401];
    }

    #[DataProvider('tokenStatuses')]
    public function testARefusedSignIn(int $status): void
    {
        $provider = $this->provider($this->answers(token: fn (): ResponseInterface => new MockResponse(
            '{"error": "invalid_client"}',
            ['http_code' => $status],
        )));

        $this->expectFailure(MotHistoryErrorCode::Unauthorised, fn () => $provider->connect($this->credentials()));
    }

    public function testASignInWithNoToken(): void
    {
        $noToken = new MockResponse('{"token_type": "Bearer"}');
        $provider = $this->provider($this->answers(token: static fn (): ResponseInterface => $noToken));

        $this->expectFailure(MotHistoryErrorCode::Unauthorised, fn () => $provider->connect($this->credentials()));
    }

    public function testMissingCredentialsSendNothing(): void
    {
        $provider = $this->provider($this->answers());

        $noKey = $this->credentials(['api_key' => '']);
        $this->expectFailure(MotHistoryErrorCode::Credentials, static fn () => $provider->connect($noKey));
        self::assertSame([], $this->requests);
    }

    public function testATokenUrlOffMicrosoftsHostSendsNothing(): void
    {
        $provider = $this->provider($this->answers());

        $this->expectFailure(
            MotHistoryErrorCode::TokenUrl,
            fn () => $provider->connect($this->credentials(['token_url' => 'https://attacker.example/oauth2/v2.0/token'])),
        );
        self::assertSame([], $this->requests);
        self::assertFalse($provider->acceptsCredential('token_url', 'http://login.microsoftonline.com/t/oauth2/v2.0/token'));
        $lookalike = 'https://login.microsoftonline.com.evil.example/t/oauth2/v2.0/token';
        self::assertFalse($provider->acceptsCredential('token_url', $lookalike));
        self::assertTrue($provider->acceptsCredential('token_url', self::TOKEN_URL));
        self::assertTrue($provider->acceptsCredential('client_id', 'anything'));
    }

    public function testARefusedIdentifierIsNeverSent(): void
    {
        $client = $this->provider($this->answers())->connect($this->credentials());

        $this->expectFailure(MotHistoryErrorCode::InvalidIdentifier, static fn () => $client->byRegistration('AB12/../x'));
        $this->expectFailure(MotHistoryErrorCode::InvalidIdentifier, static fn () => $client->byVin('?'));
        self::assertCount(1, $this->requests, 'only the sign-in');
    }

    public function testTimeoutsAndNetworkErrors(): void
    {
        $timeout = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => throw new TimeoutException('slow')))
            ->connect($this->credentials());
        $this->expectFailure(MotHistoryErrorCode::Timeout, static fn () => $timeout->byRegistration('AB12CDE'));

        $network = $this->provider($this->answers(
            vehicle: static fn (): ResponseInterface => throw new TransportException('no route'),
        ))->connect($this->credentials());
        $failure = $this->expectFailure(MotHistoryErrorCode::Network, static fn () => $network->byRegistration('AB12CDE'));
        self::assertSame(['reason' => 'no route'], $failure->parameters);
    }

    public function testATransportErrorNeverCarriesTheRegistrationOrTheVin(): void
    {
        $message = static fn (string $url): string => 'Could not resolve host for "' . $url . '?x=1": timed out, GET ' . $url;
        $registration = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => throw new TransportException(
            $message('https://history.mot.api.gov.uk/v1/trade/vehicles/registration/AB12CDE'),
        )))->connect($this->credentials());
        $failure = $this->expectFailure(MotHistoryErrorCode::Network, static fn () => $registration->byRegistration('ab12 cde'));
        $reason = $failure->parameters['reason'] ?? '';
        self::assertStringNotContainsString('AB12CDE', $reason);
        self::assertStringNotContainsString('/registration/', $reason);
        self::assertStringContainsString('history.mot.api.gov.uk', $reason, 'the host stays: it is what an admin needs');
        self::assertStringNotContainsString('AB12CDE', $failure->getMessage());

        $vin = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => throw new TransportException(
            $message('https://history.mot.api.gov.uk/v1/trade/vehicles/vin/WVWZZZ1KZAW123456'),
        )))->connect($this->credentials());
        $failure = $this->expectFailure(MotHistoryErrorCode::Network, static fn () => $vin->byVin('WVWZZZ1KZAW123456'));
        self::assertStringNotContainsString('WVWZZZ1KZAW123456', $failure->parameters['reason'] ?? '');
    }

    public function testUnreadableAndOversizedAnswers(): void
    {
        $garbled = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => new MockResponse('<html>')))
            ->connect($this->credentials());
        $this->expectFailure(MotHistoryErrorCode::InvalidResponse, static fn () => $garbled->byRegistration('AB12CDE'));

        $notVehicle = $this->answering(new MockResponse('{"make": "Ford"}'));
        $this->expectFailure(MotHistoryErrorCode::InvalidResponse, static fn () => $notVehicle->byRegistration('AB12CDE'));

        // Refused on its declared length, before the body is read.
        $declared = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => new MockResponse(
            str_repeat(' ', DvsaProvider::MAX_BYTES + 1),
            ['response_headers' => ['content-length' => (string) (DvsaProvider::MAX_BYTES + 1)]],
        )))->connect($this->credentials());
        $this->expectFailure(MotHistoryErrorCode::TooLarge, static fn () => $declared->byRegistration('AB12CDE'));

        $streamed = $this->provider($this->answers(vehicle: static fn (): ResponseInterface => new MockResponse(
            str_repeat(' ', DvsaProvider::MAX_BYTES + 1),
        )))->connect($this->credentials());
        $this->expectFailure(MotHistoryErrorCode::TooLarge, static fn () => $streamed->byRegistration('AB12CDE'));
    }

    public function testDescribesItself(): void
    {
        $provider = $this->provider($this->answers());

        self::assertSame('uk_dvsa', $provider->code());
        self::assertSame(['client_id', 'client_secret', 'api_key', 'token_url'], array_keys($provider->credentials()));
        self::assertSame(['GB'], $provider->countries());
        self::assertSame('Open Government Licence v3.0', $provider->licence()->name);
        self::assertSame('mot_history.provider.uk_dvsa.sends', $provider->sendsKey());
        self::assertSame('mot_history.provider.uk_dvsa.name', $provider->nameKey());
        self::assertSame('mot_history.provider.uk_dvsa.description', $provider->descriptionKey());
    }

    private function answering(MockResponse $response): MotHistoryClient
    {
        $answers = $this->answers(vehicle: static fn (): ResponseInterface => $response);

        return $this->provider($answers)->connect($this->credentials());
    }

    /**
     * @param Closure(MotHistoryFailure|null): mixed $call
     */
    private function expectFailure(MotHistoryErrorCode $error, Closure $call): MotHistoryFailure
    {
        try {
            $call(null);
        } catch (MotHistoryFailure $failure) {
            self::assertSame($error, $failure->error);

            return $failure;
        }
        self::fail('Expected ' . $error->value);
    }

    /**
     * @param (Closure(): ResponseInterface)|null $token
     * @param (Closure(): ResponseInterface)|null $vehicle
     * @return Closure(string, string, array<string, mixed>): ResponseInterface
     */
    private function answers(?Closure $token = null, ?Closure $vehicle = null): Closure
    {
        return function (string $method, string $url, array $options) use ($token, $vehicle): ResponseInterface {
            /** @var array<string, mixed> $options */
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if (str_starts_with($url, 'https://login.microsoftonline.com/')) {
                return $token !== null ? $token() : new MockResponse((string) file_get_contents(self::DIR . 'token.json'));
            }
            if ($vehicle !== null) {
                return $vehicle();
            }

            return new MockResponse((string) file_get_contents(self::DIR . (str_ends_with($url, '/bulk-download')
                ? 'bulk-download.json'
                : 'vehicle-with-tests.json')));
        };
    }

    /**
     * @param Closure(string, string, array<string, mixed>): ResponseInterface $answers
     */
    private function provider(Closure $answers): DvsaProvider
    {
        return new DvsaProvider(new MockHttpClient($answers), new InstalledVersion('3.7.0'), new NullLogger());
    }

    /**
     * @param array<string, string> $override
     */
    private function credentials(array $override = []): ProviderCredentials
    {
        return new ProviderCredentials($override + [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'api_key' => 'the-api-key',
            'token_url' => self::TOKEN_URL,
        ]);
    }

    /**
     * @param array{options: array<string, mixed>} $request
     * @return list<string>
     */
    private function headers(array $request): array
    {
        $headers = $request['options']['headers'] ?? [];

        return is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [];
    }
}
