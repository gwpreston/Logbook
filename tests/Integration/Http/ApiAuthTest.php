<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Api\ApiScope;
use Logbook\Repository\ApiKeyRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Support\Api\FailedKeyThrottle;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * API keys at the door (spec.md §7.20): only a live bearer key opens the
 * API, a read key never writes, guessing is throttled per address, the
 * token never reaches the log, and no API request ever makes a session.
 */
final class ApiAuthTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testAMissingMalformedUnknownOrRevokedKeyIsUnauthorized(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $keys = $this->service($app, ApiKeyService::class);
        $revoked = $keys->create($owner, 'Old', ApiScope::Read);
        $keys->revoke($owner, $revoked->key->id);
        $unknown = 'lbk_' . str_repeat('A', 43);

        $cases = [
            'missing' => [null, 'missing_key'],
            'malformed' => ['not-a-key', 'invalid_key'],
            'unknown' => [$unknown, 'invalid_key'],
            'revoked' => [$revoked->token, 'invalid_key'],
        ];
        foreach ($cases as $case => [$token, $code]) {
            $response = $this->api($app, $token)->get('/vehicles');
            self::assertSame(401, $response->getStatusCode(), $case);
            self::assertSame('Bearer realm="Logbook"', $response->getHeaderLine('WWW-Authenticate'), $case);
            self::assertSame($code, ApiClient::json($response)->get('code'), $case);
        }

        $basic = $this->api($app, null)->get('/vehicles', [
            'Authorization' => 'Basic ' . base64_encode('owner:' . self::PASSWORD),
        ]);
        self::assertSame(401, $basic->getStatusCode(), 'only a bearer key opens the API');
    }

    public function testAReadKeyReadsButNeverWrites(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner, ApiScope::Read));

        self::assertSame(200, $api->get('/vehicles/' . $golf->id)->getStatusCode());
        foreach (['/fuel', '/odometer'] as $path) {
            $response = $api->post('/vehicles/' . $golf->id . $path, ['odometer' => '10000']);
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame('insufficient_scope', ApiClient::json($response)->get('code'));
        }
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
    }

    public function testASignedInBrowserWithoutAKeyCannotUseTheApi(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        // The session cookie goes along, and there is no CSRF check to stop it: the key is what counts.
        $response = $browser->post('/api/v1/vehicles/' . $golf->id . '/odometer', ['odometer' => '10000'], [], false);

        self::assertSame(401, $response->getStatusCode());
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
    }

    public function testAKeyGivesItsOwnUserAndLastUseIsRecordedAtMostOnceAMinute(): void
    {
        $app = $this->createApp();
        $clock = $this->pinClock($app, '2026-09-29T07:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $created = $this->service($app, ApiKeyService::class)->create($owner, 'Shortcuts', ApiScope::ReadWrite);
        $api = $this->api($app, $created->token);
        $keys = $this->service($app, ApiKeyRepository::class);
        $lastUsed = static fn (): ?string => $keys->find($created->key->id)?->lastUsedAt?->format('c');
        self::assertNull($lastUsed());

        $me = ApiClient::json($api->get('/me'));
        self::assertSame('owner', $me->get('user', 'username'));
        self::assertSame([
            'id' => $created->key->id,
            'name' => 'Shortcuts',
            'scope' => 'read_write',
            'created_at' => '2026-09-29T07:00:00Z',
        ], $me->get('key'));
        self::assertSame('2026-09-29T07:00:00+00:00', $lastUsed());

        $clock->set(new DateTimeImmutable('2026-09-29T07:00:59Z'));
        $api->get('/me');
        self::assertSame('2026-09-29T07:00:00+00:00', $lastUsed(), 'not within the minute');
        $clock->set(new DateTimeImmutable('2026-09-29T07:01:00Z'));
        $api->get('/me');
        self::assertSame('2026-09-29T07:01:00+00:00', $lastUsed());
    }

    public function testTwentyFailuresFromOneAddressAreThrottledForTenMinutes(): void
    {
        $app = $this->createApp();
        $clock = $this->pinClock($app, '2026-09-29T07:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $good = $this->api($app, $this->apiKey($app, $owner));
        $bad = $good->withToken('lbk_' . str_repeat('B', 43));
        $elsewhere = $this->api($app, $this->apiKey($app, $owner, ApiScope::Read, 'Elsewhere'));

        for ($i = 1; $i < FailedKeyThrottle::MAX_FAILURES; ++$i) {
            self::assertSame(401, $bad->get('/me')->getStatusCode(), 'failure ' . $i);
        }
        self::assertSame(200, $good->get('/me')->getStatusCode(), 'nineteen failures still let a good key in');
        self::assertSame(401, $bad->get('/me')->getStatusCode(), 'the twentieth');

        $throttled = $good->get('/me');
        self::assertSame(429, $throttled->getStatusCode(), 'then even a good key from that address waits');
        self::assertSame('too_many_failures', ApiClient::json($throttled)->get('code'));
        self::assertSame('600', $throttled->getHeaderLine('Retry-After'));
        self::assertSame(200, $elsewhere->get('/me')->getStatusCode(), 'other addresses are not affected');

        $clock->set(new DateTimeImmutable('2026-09-29T07:09:59Z'));
        self::assertSame(429, $good->get('/me')->getStatusCode());
        self::assertSame('1', $good->get('/me')->getHeaderLine('Retry-After'));
        $clock->set(new DateTimeImmutable('2026-09-29T07:10:00Z'));
        self::assertSame(200, $good->get('/me')->getStatusCode(), 'ten minutes later');
    }

    public function testFailuresOlderThanTenMinutesDoNotCount(): void
    {
        $app = $this->createApp();
        $clock = $this->pinClock($app, '2026-09-29T07:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $good = $this->api($app, $this->apiKey($app, $owner));
        $bad = $good->withToken('lbk_' . str_repeat('C', 43));

        for ($i = 1; $i < FailedKeyThrottle::MAX_FAILURES; ++$i) {
            $bad->get('/me');
        }
        $clock->set(new DateTimeImmutable('2026-09-29T07:10:01Z'));
        self::assertSame(401, $bad->get('/me')->getStatusCode());
        self::assertSame(200, $good->get('/me')->getStatusCode(), 'the old failures fell out of the window');
    }

    public function testFailuresAreLoggedWithTheAddressButNeverTheToken(): void
    {
        $log = sys_get_temp_dir() . '/logbook-api-log-' . bin2hex(random_bytes(4)) . '.log';
        $app = $this->createApp(['LOG_PATH' => $log, 'LOG_LEVEL' => 'debug']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $keys = $this->service($app, ApiKeyService::class);
        $revoked = $keys->create($owner, 'Old', ApiScope::Read);
        $keys->revoke($owner, $revoked->key->id);
        $live = $this->apiKey($app, $owner);
        $guess = 'lbk_' . str_repeat('D', 43);

        $api = $this->api($app, $guess);
        $api->get('/me');
        $api->withToken($revoked->token)->get('/me');
        $api->withToken($live)->get('/me');

        $contents = (string) file_get_contents($log);
        unlink($log);
        self::assertStringContainsString($api->address, $contents);
        self::assertStringContainsString('unknown or revoked', $contents);
        foreach ([$guess, $revoked->token, $live, substr($live, 4)] as $token) {
            self::assertStringNotContainsString($token, $contents);
        }
    }

    public function testNoApiRequestCreatesASession(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $api = $this->api($app, $this->apiKey($app, $owner));

        $responses = [
            $api->get('/vehicles'),
            $api->get('/vehicles/' . $golf->id . '/summary'),
            $api->post('/vehicles/' . $golf->id . '/odometer', ['odometer' => '12000', 'recorded_at' => '2026-09-29T07:00:00Z']),
            $api->post('/vehicles/' . $golf->id . '/fuel', ['odometer' => 'x']),
            $api->withToken(null)->get('/vehicles'),
            $api->get('/nowhere'),
        ];

        foreach ($responses as $response) {
            self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        }
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM sessions'));
    }

    public function testTokensAreHashedAtRestAndWellFormed(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $created = $this->service($app, ApiKeyService::class)->create($owner, 'HA', ApiScope::Read);

        self::assertMatchesRegularExpression('/^lbk_[A-Za-z0-9_-]{43}$/', $created->token);
        $stored = $this->connection($app)->fetchAssociative('SELECT * FROM api_keys');
        self::assertIsArray($stored);
        self::assertNotContains(
            $created->token,
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stored),
        );
        self::assertSame(
            hash_hmac('sha256', $created->token, ''),
            $stored['token_hash'],
            'HMAC-SHA256 keyed with SESSION_SECRET (empty in the tests)',
        );
    }

    public function testChangingTheSessionSecretDisablesEveryKey(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => 'first secret']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $token = $this->apiKey($app, $owner);
        self::assertSame(200, $this->api($app, $token)->get('/me')->getStatusCode());

        $other = $this->createApp(['SESSION_SECRET' => 'second secret']);
        self::assertSame(401, $this->api($other, $token)->get('/me')->getStatusCode());
    }
}
