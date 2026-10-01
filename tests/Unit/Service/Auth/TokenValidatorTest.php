<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Auth;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Logbook\Service\Auth\Oidc\Discovery;
use Logbook\Service\Auth\Oidc\OidcCache;
use Logbook\Service\Auth\Oidc\OidcFailure;
use Logbook\Service\Auth\Oidc\TokenValidator;
use Logbook\Support\Config\OidcConfig;
use Logbook\Tests\Support\FakeIdentityProvider;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every ID token check (spec.md §7.9 *Flow*): a good token passes with each
 * accepted algorithm; each listed fault is refused, naming the check.
 */
final class TokenValidatorTest extends TestCase
{
    private const string NONCE = 'the-nonce';

    private FakeIdentityProvider $idp;
    private MutableClock $clock;
    private string $cacheDir;
    private TokenValidator $validator;
    private Discovery $discovery;

    protected function setUp(): void
    {
        $this->idp = new FakeIdentityProvider();
        $this->idp->now = 1_790_000_000;
        $this->clock = new MutableClock(new DateTimeImmutable('@' . $this->idp->now));
        $this->cacheDir = sys_get_temp_dir() . '/logbook-oidc-' . bin2hex(random_bytes(4));
        $config = new OidcConfig(
            FakeIdentityProvider::ISSUER,
            FakeIdentityProvider::CLIENT_ID,
            FakeIdentityProvider::CLIENT_SECRET,
        );
        $this->discovery = new Discovery($config, $this->idp->client, new OidcCache($this->cacheDir), $this->clock);
        $this->validator = new TokenValidator($config, $this->discovery, $this->clock);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function algorithms(): iterable
    {
        yield 'RS256' => ['RS256', 'rsa-1'];
        yield 'PS256' => ['PS256', 'rsa-1'];
        yield 'ES256' => ['ES256', 'ec-1'];
        yield 'EdDSA' => ['EdDSA', 'ed-1'];
    }

    #[DataProvider('algorithms')]
    public function testAGoodTokenPasses(string $alg, string $kid): void
    {
        $this->idp->alg = $alg;
        $this->idp->kid = $kid;

        $token = $this->validator->validate($this->token(['sub' => 'abc', 'name' => 'Pat']), $this->metadata(), self::NONCE);

        self::assertSame('abc', $token->subject);
        self::assertSame(FakeIdentityProvider::ISSUER, $token->issuer);
        self::assertSame('Pat', $token->claims['name']);
        self::assertNull(JWT::$timestamp, 'the library\'s clock is put back');
    }

    public function testATokenWithoutAKidUsesTheOnlyKeyOfItsType(): void
    {
        $this->idp->alg = 'ES256';
        $this->idp->kid = null;

        self::assertSame('subject-1', $this->validator->validate($this->token(), $this->metadata(), self::NONCE)->subject);
    }

    public function testAWrongSignatureIsRefused(): void
    {
        $this->idp->tamperToken = static function (string $token): string {
            [$header, $payload, $signature] = explode('.', $token);
            $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
            assert(is_array($claims));
            $claims['sub'] = 'someone-else';

            return $header . '.' . FakeIdentityProvider::b64((string) json_encode($claims)) . '.' . $signature;
        };

        $this->assertRefused($this->token(), 'Signature verification failed');
    }

    public function testAlgNoneIsRefused(): void
    {
        $this->idp->tamperToken = static function (string $token): string {
            [, $payload] = explode('.', $token);

            return FakeIdentityProvider::b64('{"alg":"none","typ":"JWT"}') . '.' . $payload . '.';
        };

        $this->assertRefused($this->token(), 'algorithm "none"');
    }

    public function testHs256IsRefusedEvenWithThePublicKeyAsTheSecret(): void
    {
        // The classic confusion: an HMAC signed with the RSA public key.
        $this->idp->tamperToken = static function (string $token): string {
            [, $payload] = explode('.', $token);
            $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
            assert(is_array($claims));

            return JWT::encode($claims, str_repeat(FakeIdentityProvider::key('RS256')['public']['n'], 1), 'HS256', 'rsa-1');
        };

        $this->assertRefused($this->token(), 'algorithm "HS256"');
    }

    public function testAnUnknownKidIsFetchedAgainOnceThenRefused(): void
    {
        $this->idp->kid = 'rotated-away';
        $metadata = $this->metadata();
        $this->discovery->keys($metadata);
        $before = count($this->idp->requestsTo('/certs'));

        $this->assertRefused($this->token(), 'even after fetching it again');
        self::assertCount($before + 1, $this->idp->requestsTo('/certs'), 'one more fetch of the key set');
    }

    public function testARotatedKeyIsFoundByFetchingTheSetAgain(): void
    {
        $metadata = $this->metadata();
        $this->idp->publishedKids = ['ec-1'];
        $this->discovery->keys($metadata);
        $this->idp->publishedKids = null;

        self::assertSame('subject-1', $this->validator->validate($this->token(), $metadata, self::NONCE)->subject);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badClaims(): iterable
    {
        yield 'wrong iss' => [['iss' => 'https://evil.example/'], 'issuer'];
        yield 'iss with a trailing slash' => [['iss' => FakeIdentityProvider::ISSUER . '/'], 'issuer'];
        yield 'wrong aud' => [['aud' => 'another-app'], 'aud'];
        yield 'aud list without us' => [['aud' => ['a', 'b']], 'aud'];
        yield 'wrong azp' => [['aud' => [FakeIdentityProvider::CLIENT_ID, 'other'], 'azp' => 'other'], 'azp'];
        yield 'no sub' => [['sub' => ''], 'subject'];
        yield 'nonce mismatch' => [['nonce' => 'another'], 'nonce'];
        yield 'no nonce' => [['nonce' => null], 'nonce'];
        yield 'no exp' => [['exp' => null], 'expiry'];
        yield 'no iat' => [['iat' => null], 'issue time'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('badClaims')]
    public function testEachBadClaimIsRefused(array $claims, string $reason): void
    {
        $this->idp->tamperClaims = static fn (array $built): array => array_filter(
            array_merge($built, $claims),
            static fn (mixed $value): bool => $value !== null,
        );

        $this->assertRefused($this->token(), $reason);
    }

    public function testAnAudienceListWithUsAndAMatchingAzpPasses(): void
    {
        $token = $this->token(['aud' => ['other', FakeIdentityProvider::CLIENT_ID], 'azp' => FakeIdentityProvider::CLIENT_ID]);

        self::assertSame('subject-1', $this->validator->validate($token, $this->metadata(), self::NONCE)->subject);
    }

    public function testExpiryAndIssueTimeHaveSixtySecondsOfLeeway(): void
    {
        $now = $this->idp->now;
        $metadata = $this->metadata();

        $this->validator->validate($this->token(['exp' => $now - 59]), $metadata, self::NONCE);
        $this->validator->validate($this->token(['iat' => $now + 59, 'exp' => $now + 600]), $metadata, self::NONCE);
        $this->assertRefused($this->token(['exp' => $now - 61]), 'xpired');
        $this->assertRefused($this->token(['iat' => $now + 61, 'exp' => $now + 600]), 'iat');
        // The library checks iat only without nbf; this check never skips it.
        $this->assertRefused($this->token(['iat' => $now + 61, 'nbf' => $now - 10, 'exp' => $now + 600]), 'iat');
        self::assertSame(0, JWT::$leeway, 'the library\'s leeway is put back');
    }

    public function testADiscoveredIssuerThatDiffersIsRefused(): void
    {
        $this->idp->discoveryOverrides = ['issuer' => FakeIdentityProvider::ISSUER . '/'];

        $this->expectException(OidcFailure::class);
        $this->expectExceptionMessageMatches('/character for character/');
        $this->discovery->metadata();
    }

    public function testDiscoveryAndTheKeySetAreCachedForADay(): void
    {
        $metadata = $this->discovery->metadata();
        $this->discovery->metadata();
        $this->discovery->keys($metadata);
        $this->discovery->keys($metadata);
        self::assertCount(1, $this->idp->requestsTo('openid-configuration'));
        self::assertCount(1, $this->idp->requestsTo('/certs'));

        $this->clock->set(new DateTimeImmutable('@' . ($this->idp->now + 86_400)));
        $this->discovery->keys($this->discovery->metadata());
        self::assertCount(2, $this->idp->requestsTo('openid-configuration'), 'fetched again after 24 hours');
        self::assertCount(2, $this->idp->requestsTo('/certs'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims = []): string
    {
        return $this->idp->idToken($claims, self::NONCE);
    }

    private function metadata(): \Logbook\Service\Auth\Oidc\ProviderMetadata
    {
        return $this->discovery->metadata();
    }

    private function assertRefused(string $token, string $reason): void
    {
        try {
            $this->validator->validate($token, $this->metadata(), self::NONCE);
            self::fail('The token must be refused: ' . $reason);
        } catch (OidcFailure $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }
}
