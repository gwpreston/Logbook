<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Domain\Auth\ProxyAuthMode;
use Logbook\Domain\Auth\ProxyLinkMode;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Config\ProxyAuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The AUTH_PROXY_* variables (spec.md §9): off by default, the documented
 * defaults, and anything half-set stopping the app with a message naming
 * the variables, above all a header with no trusted proxies.
 */
final class ProxyAuthConfigTest extends TestCase
{
    private const string SECRET = 'a-proxy-provider-client-secret-0123456789';

    public function testOffUnlessAHeaderIsSet(): void
    {
        $config = ProxyAuthConfig::fromEnv(new Env(['AUTH_PROXY_TRUSTED' => '10.0.0.0/8', 'AUTH_PROXY_AUTO_CREATE' => 'true']));

        self::assertFalse($config->isEnabled());
        self::assertSame(ProxyAuthMode::Off, AppSettings::fromEnv(new Env([]), '/tmp')->proxy->mode);
    }

    public function testAPlainHeaderAndTheDefaults(): void
    {
        $config = ProxyAuthConfig::fromEnv(new Env([
            'AUTH_PROXY_HEADER' => 'Remote-User',
            'AUTH_PROXY_TRUSTED' => '172.18.0.0/16, 10.0.0.5 ,',
            'AUTH_PROXY_GROUPS_HEADER' => 'Remote-Groups',
            'AUTH_PROXY_ADMIN_GROUPS' => 'logbook-admins',
        ]));

        self::assertSame(ProxyAuthMode::Header, $config->mode);
        self::assertSame('Remote-User', $config->header);
        self::assertSame('remote-user', $config->headerIssuer());
        self::assertSame(['172.18.0.0/16', '10.0.0.5/32'], array_map(strval(...), $config->trusted));
        self::assertSame(ProxyLinkMode::Username, $config->link, 'username linking by default (#53)');
        self::assertFalse($config->autoCreate);
        self::assertSame([], $config->allowedGroups);
        self::assertSame(['logbook-admins'], $config->adminGroups);
        self::assertSame('Remote-Groups', $config->groupsHeader);
        self::assertNull($config->logoutUrl);
        self::assertTrue($config->trusts('172.18.3.4'));
        self::assertFalse($config->trusts('203.0.113.9'));
    }

    public function testTheJwtModeTrustsAnyAddressUnlessListed(): void
    {
        $jwt = [
            'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
            'AUTH_PROXY_JWT_SECRET' => self::SECRET,
            'AUTH_PROXY_JWT_ISSUER' => 'https://auth.example.com/application/o/logbook/',
            'AUTH_PROXY_JWT_AUDIENCE' => 'logbook-proxy',
            'AUTH_PROXY_GROUPS_HEADER' => 'X-authentik-groups',
        ];
        $open = ProxyAuthConfig::fromEnv(new Env($jwt));
        self::assertSame(ProxyAuthMode::Jwt, $open->mode);
        self::assertTrue($open->trusts('203.0.113.9'));
        self::assertFalse($open->isListedProxy('203.0.113.9'));
        self::assertSame('', $open->groupsHeader, 'groups come from the claims, never a plain header');

        $listed = ProxyAuthConfig::fromEnv(new Env($jwt + ['AUTH_PROXY_TRUSTED' => '10.0.0.0/8']));
        self::assertFalse($listed->trusts('203.0.113.9'));
        self::assertTrue($listed->isListedProxy('10.1.2.3'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refused(): iterable
    {
        yield 'a header without trusted proxies' => [
            ['AUTH_PROXY_HEADER' => 'Remote-User'],
            'AUTH_PROXY_HEADER is set but AUTH_PROXY_TRUSTED is empty',
        ];
        yield 'both headers' => [
            [
                'AUTH_PROXY_HEADER' => 'Remote-User',
                'AUTH_PROXY_TRUSTED' => '10.0.0.0/8',
                'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
            ],
            'AUTH_PROXY_HEADER and AUTH_PROXY_JWT_HEADER are both set',
        ];
        yield 'an invalid trusted entry' => [
            ['AUTH_PROXY_HEADER' => 'Remote-User', 'AUTH_PROXY_TRUSTED' => '10.0.0.0/8, proxy.local'],
            'AUTH_PROXY_TRUSTED: "proxy.local" is not an IP address or CIDR range.',
        ];
        yield 'a header name with an underscore' => [
            ['AUTH_PROXY_HEADER' => 'Remote_User', 'AUTH_PROXY_TRUSTED' => '10.0.0.0/8'],
            'AUTH_PROXY_HEADER "Remote_User" is not a header name',
        ];
        yield 'an unknown link mode' => [
            ['AUTH_PROXY_HEADER' => 'Remote-User', 'AUTH_PROXY_TRUSTED' => '10.0.0.0/8', 'AUTH_PROXY_LINK' => 'email'],
            'AUTH_PROXY_LINK "email" is invalid',
        ];
        yield 'a logout URL that is not http(s)' => [
            [
                'AUTH_PROXY_HEADER' => 'Remote-User',
                'AUTH_PROXY_TRUSTED' => '10.0.0.0/8',
                'AUTH_PROXY_LOGOUT_URL' => 'javascript:alert(1)',
            ],
            'AUTH_PROXY_LOGOUT_URL "javascript:alert(1)" is not an http(s) URL',
        ];
        yield 'a JWT header without its secret' => [
            [
                'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
                'AUTH_PROXY_JWT_ISSUER' => 'https://a.example/',
                'AUTH_PROXY_JWT_AUDIENCE' => 'x',
            ],
            'AUTH_PROXY_JWT_SECRET must be set when AUTH_PROXY_JWT_HEADER is set.',
        ];
        yield 'a JWT header without its issuer' => [
            [
                'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
                'AUTH_PROXY_JWT_SECRET' => self::SECRET,
                'AUTH_PROXY_JWT_AUDIENCE' => 'x',
            ],
            'AUTH_PROXY_JWT_ISSUER must be set',
        ];
        yield 'a JWT header without its audience' => [
            [
                'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
                'AUTH_PROXY_JWT_SECRET' => self::SECRET,
                'AUTH_PROXY_JWT_ISSUER' => 'https://a.example/',
            ],
            'AUTH_PROXY_JWT_AUDIENCE must be set',
        ];
        yield 'a short secret' => [
            [
                'AUTH_PROXY_JWT_HEADER' => 'X-authentik-jwt',
                'AUTH_PROXY_JWT_SECRET' => 'short',
                'AUTH_PROXY_JWT_ISSUER' => 'https://a.example/',
                'AUTH_PROXY_JWT_AUDIENCE' => 'x',
            ],
            'AUTH_PROXY_JWT_SECRET is shorter than 32 characters',
        ];
        yield 'a JWT secret without the JWT header' => [
            ['AUTH_PROXY_HEADER' => 'Remote-User', 'AUTH_PROXY_TRUSTED' => '10.0.0.0/8', 'AUTH_PROXY_JWT_SECRET' => self::SECRET],
            'AUTH_PROXY_JWT_SECRET is set but AUTH_PROXY_JWT_HEADER is not.',
        ];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('refused')]
    public function testHalfSetConfigurationStopsTheApp(array $env, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AppSettings::fromEnv(new Env($env), '/tmp');
    }
}
