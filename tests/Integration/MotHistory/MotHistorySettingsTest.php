<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Closure;
use DI\Container;
use Logbook\Domain\Feature\Feature;
use Logbook\Repository\MotHistorySecretRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Jobs\OutputRedactor;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryErrorCode;
use Logbook\Service\MotHistory\Uk\DvsaProvider;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Settings → MOT history (spec.md §7.38): off by default; admins only, a
 * 404 to anyone else, with `compliance` off and in demo mode; credentials
 * sealed or `env:`, never shown back; the token URL pinned to Microsoft's
 * host; *Test* signs in and asks for the bulk list (no vehicle sent, #327)
 * and its outcome is the last call, redacted. Nothing leaves the machine.
 */
final class MotHistorySettingsTest extends AppTestCase
{
    private const string TOKEN_URL = 'https://login.microsoftonline.com/a1b2c3d4-tenant/oauth2/v2.0/token';
    private const string FIXTURES = __DIR__ . '/../../Fixtures/mot-history/';

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];
    /** @var (Closure(string): ResponseInterface)|null */
    private ?Closure $answer = null;

    public function testOffByDefaultAndForAdminsOnly(): void
    {
        $app = $this->app();
        $this->createMember($app);

        self::assertFalse($this->service($app, MotHistoryConfig::class)->enabled());
        self::assertSame(404, $this->browserFor($app, 'partner')->get('/settings/mot-history')->getStatusCode());
        $browser = $this->browserFor($app, 'owner');
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringContainsString('Sends the registration (or VIN) of vehicles whose owners choose to fetch', $page);
        self::assertStringContainsString('Open Government Licence v3.0', $page);
        $settings = (string) $browser->get('/settings')->getBody();
        self::assertStringContainsString('Off: choose a provider to fetch MOT history', $settings);
        self::assertSame([], $this->requests, 'nothing was sent');
    }

    public function testEnablingNeedsEveryCredentialAndNoneIsShownBack(): void
    {
        $app = $this->app();
        $browser = $this->browserFor($app, 'owner');

        $refused = $browser->post('/settings/mot-history', [
            'provider' => DvsaProvider::CODE,
            'secret' => ['client_id' => 'env:DVSA_ID', 'client_secret' => 'a-real-client-secret-1234'],
        ]);
        self::assertSame(200, $refused->getStatusCode());
        self::assertStringContainsString('Enter all four credentials before enabling', (string) $refused->getBody());
        self::assertFalse($this->service($app, MotHistoryConfig::class)->enabled());

        $saved = $browser->post('/settings/mot-history', [
            'provider' => DvsaProvider::CODE,
            'secret' => ['api_key' => 'the-real-api-key-5678', 'token_url' => self::TOKEN_URL],
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertTrue($this->service($app, MotHistoryConfig::class)->enabled());

        $stored = $this->service($app, MotHistorySecretRepository::class)->forProvider(DvsaProvider::CODE);
        self::assertSame('env:DVSA_ID', $stored['client_id']);
        self::assertStringStartsWith('v1:', $stored['client_secret'], 'sealed');
        self::assertStringStartsWith('v1:', $stored['api_key'], 'sealed');
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringNotContainsString('a-real-client-secret-1234', $page);
        self::assertStringNotContainsString('the-real-api-key-5678', $page);
        self::assertStringNotContainsString('a1b2c3d4-tenant', $page);
        self::assertStringContainsString('Read from the environment variable DVSA_ID.', $page);
        self::assertStringContainsString('MOT history is on', (string) $browser->get('/settings')->getBody());
        // The job log never prints them.
        self::assertSame('x •••• y', $this->service($app, OutputRedactor::class)->redact('x the-real-api-key-5678 y'));

        // Off again keeps the credentials.
        self::assertSame(303, $browser->post('/settings/mot-history', ['provider' => ''])->getStatusCode());
        self::assertFalse($this->service($app, MotHistoryConfig::class)->enabled());
        self::assertCount(4, $this->service($app, MotHistorySecretRepository::class)->forProvider(DvsaProvider::CODE));
        self::assertSame([], $this->requests, 'saving sends nothing');
    }

    public function testTheTokenUrlMustBeMicrosofts(): void
    {
        $app = $this->app();
        $browser = $this->browserFor($app, 'owner');

        $refused = $browser->post('/settings/mot-history', [
            'provider' => '',
            'secret' => ['token_url' => 'https://attacker.example/oauth2/v2.0/token'],
        ]);
        self::assertSame(200, $refused->getStatusCode());
        self::assertStringContainsString(
            'The token URL must be https://login.microsoftonline.com/&lt;tenant&gt;/oauth2/v2.0/token',
            (string) $refused->getBody(),
        );
        self::assertSame([], $this->service($app, MotHistorySecretRepository::class)->forProvider(DvsaProvider::CODE));

        $unknown = $browser->post('/settings/mot-history', ['provider' => 'nope']);
        self::assertStringContainsString('Choose a provider from the list.', (string) $unknown->getBody());

        $long = $browser->post('/settings/mot-history', ['provider' => '', 'secret' => ['api_key' => str_repeat('k', 2001)]]);
        self::assertStringContainsString('That is too long to be a credential.', (string) $long->getBody());
    }

    public function testATokenUrlFromTheEnvironmentIsCheckedWhenRead(): void
    {
        $app = $this->app(['DVSA_TOKEN_URL' => 'https://attacker.example/token']);
        $this->saveCredentials($app, ['token_url' => 'env:DVSA_TOKEN_URL']);
        $browser = $this->browserFor($app, 'owner');

        $browser->post('/settings/mot-history/test', ['provider' => DvsaProvider::CODE]);

        self::assertSame([], $this->requests, 'the secret never went to that host');
        self::assertSame(MotHistoryErrorCode::TokenUrl, $this->service($app, MotHistoryConfig::class)->status()->error);
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringContainsString('The token URL is not Microsoft', $page);
    }

    public function testTestSignsInAndSendsNoVehicle(): void
    {
        $app = $this->app();
        $this->saveCredentials($app);
        $browser = $this->browserFor($app, 'owner');

        $response = $browser->post('/settings/mot-history/test', ['provider' => DvsaProvider::CODE]);

        self::assertSame(303, $response->getStatusCode());
        $bulk = 'GET https://history.mot.api.gov.uk/v1/trade/vehicles/bulk-download';
        self::assertSame(['POST ' . self::TOKEN_URL, $bulk], array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->requests,
        ));
        $status = $this->service($app, MotHistoryConfig::class)->status();
        self::assertTrue($status->ok());
        self::assertNotNull($status->lastSuccessAt);
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringContainsString('DVSA accepted the credentials.', $page);
        self::assertStringContainsString('The last call worked.', $page);
    }

    public function testAFailedTestShowsTheReasonWithoutTheSecrets(): void
    {
        $app = $this->app();
        $this->saveCredentials($app);
        $this->answer = static fn (string $url): ResponseInterface => str_contains($url, 'bulk-download')
            ? throw new TransportException('refused by the-real-api-key-5678 at host')
            : new MockResponse((string) file_get_contents(self::FIXTURES . 'token.json'));
        $browser = $this->browserFor($app, 'owner');

        $browser->post('/settings/mot-history/test', ['provider' => DvsaProvider::CODE]);

        $status = $this->service($app, MotHistoryConfig::class)->status();
        self::assertSame(MotHistoryErrorCode::Network, $status->error);
        self::assertSame(['reason' => 'refused by •••• at host'], $status->parameters);
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringContainsString('The test failed. The reason is under Last call.', $page);
        self::assertStringContainsString('DVSA could not be reached: refused by •••• at host', $page);
        self::assertStringNotContainsString('the-real-api-key-5678', $page);
    }

    public function testTestSendsNothingWhileTheProviderIsOff(): void
    {
        $app = $this->app();
        $this->saveCredentials($app);
        $browser = $this->browserFor($app, 'owner');
        self::assertSame(303, $browser->post('/settings/mot-history', ['provider' => ''])->getStatusCode());

        $off = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringNotContainsString('settings/mot-history/test', $off, 'no Test button');
        $browser->post('/settings/mot-history/test', ['provider' => DvsaProvider::CODE]);

        self::assertSame([], $this->requests, 'nothing was sent with the provider off');
        $page = (string) $browser->get('/settings/mot-history')->getBody();
        self::assertStringContainsString('with MOT history off nothing is sent', $page);

        $this->createMember($app);
        self::assertSame(404, $this->browserFor($app, 'partner')->post('/settings/mot-history/test', [])->getStatusCode());
    }

    public function testOffWithComplianceOff(): void
    {
        $app = $this->app();
        $this->saveCredentials($app);
        $this->service($app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Compliance,
        )));
        $browser = $this->browserFor($app, 'owner');

        self::assertSame(404, $browser->get('/settings/mot-history')->getStatusCode());
        self::assertSame(404, $browser->post('/settings/mot-history/test', ['provider' => DvsaProvider::CODE])->getStatusCode());
        self::assertFalse($this->service($app, MotHistoryConfig::class)->enabled());
        self::assertStringNotContainsString('MOT history', (string) $browser->get('/settings')->getBody());
        self::assertSame([], $this->requests);
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = []): App
    {
        $app = $this->createApp($env + [
            'DVSA_ID' => 'client-id-from-env',
            'SESSION_SECRET' => 'a-session-secret-for-sealing-credentials-in-tests',
        ]);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url): ResponseInterface {
                $this->requests[] = ['method' => $method, 'url' => $url];
                if ($this->answer !== null) {
                    return ($this->answer)($url);
                }

                return new MockResponse((string) file_get_contents(self::FIXTURES . (str_contains($url, 'microsoftonline')
                    ? 'token.json'
                    : 'bulk-download.json')));
            },
        ));

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $override
     */
    private function saveCredentials(App $app, array $override = []): void
    {
        $response = $this->browserFor($app, 'owner')->post('/settings/mot-history', [
            'provider' => DvsaProvider::CODE,
            'secret' => $override + [
                'client_id' => 'env:DVSA_ID',
                'client_secret' => 'a-real-client-secret-1234',
                'api_key' => 'the-real-api-key-5678',
                'token_url' => self::TOKEN_URL,
            ],
        ]);
        self::assertSame(303, $response->getStatusCode());
    }
}
