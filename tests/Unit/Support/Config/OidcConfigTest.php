<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Domain\Auth\OidcLinkMode;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Config\OidcConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The OIDC_* variables (spec.md §9): off without an issuer, the documented
 * defaults, and a half-set configuration stopping the app with a message
 * that names the variable.
 */
final class OidcConfigTest extends TestCase
{
    private const array MINIMAL = [
        'OIDC_ISSUER' => 'https://auth.example.com/application/o/logbook/',
        'OIDC_CLIENT_ID' => 'logbook',
        'OIDC_CLIENT_SECRET' => 's3cret',
    ];

    public function testWithoutAnIssuerSsoIsOff(): void
    {
        $config = OidcConfig::fromEnv(new Env(['OIDC_CLIENT_ID' => 'ignored']));

        self::assertFalse($config->isConfigured());
        self::assertTrue(AppSettings::fromEnv(new Env([]), '/tmp')->localLogin, 'password sign-in is on by default');
    }

    public function testFourVariablesAndTheDefaults(): void
    {
        $config = OidcConfig::fromEnv(new Env(self::MINIMAL + ['OIDC_PROVIDER_NAME' => 'Authentik']));

        self::assertTrue($config->isConfigured());
        self::assertSame('https://auth.example.com/application/o/logbook/', $config->issuer, 'kept exactly, trailing slash too');
        self::assertSame('Authentik', $config->providerName);
        self::assertSame(['openid', 'profile', 'email'], $config->scopes);
        self::assertSame('preferred_username', $config->usernameClaim);
        self::assertSame('groups', $config->groupsClaim);
        self::assertSame(OidcLinkMode::Explicit, $config->link);
        self::assertFalse($config->autoCreate);
        self::assertSame([], $config->allowedGroups);
        self::assertSame([], $config->adminGroups);
        self::assertFalse($config->usesGroups());
        self::assertFalse($config->logout);
        self::assertSame('SSO', OidcConfig::fromEnv(new Env(self::MINIMAL))->providerName);
    }

    public function testListsAndSwitches(): void
    {
        $config = OidcConfig::fromEnv(new Env(self::MINIMAL + [
            'OIDC_SCOPES' => 'openid, profile groups',
            'OIDC_LINK' => 'Username',
            'OIDC_AUTO_CREATE' => 'true',
            'OIDC_ALLOWED_GROUPS' => 'logbook, Family Members ,',
            'OIDC_ADMIN_GROUPS' => 'logbook-admins',
            'OIDC_LOGOUT' => '1',
        ]));

        self::assertSame(['openid', 'profile', 'groups'], $config->scopes);
        self::assertSame(OidcLinkMode::Username, $config->link);
        self::assertTrue($config->autoCreate);
        self::assertSame(['logbook', 'Family Members'], $config->allowedGroups, 'group names may hold spaces');
        self::assertSame(['logbook-admins'], $config->adminGroups);
        self::assertTrue($config->usesGroups());
        self::assertTrue($config->logout);
        self::assertFalse(AppSettings::fromEnv(new Env(self::MINIMAL + ['AUTH_LOCAL_LOGIN' => 'false']), '/tmp')->localLogin);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'no client id' => [['OIDC_CLIENT_ID' => ''], 'OIDC_CLIENT_ID'];
        yield 'no client secret' => [['OIDC_CLIENT_SECRET' => ''], 'OIDC_CLIENT_SECRET'];
        yield 'not a URL' => [['OIDC_ISSUER' => 'auth.example.com'], 'OIDC_ISSUER'];
        yield 'no openid scope' => [['OIDC_SCOPES' => 'profile email'], 'OIDC_SCOPES'];
        yield 'unknown link mode' => [['OIDC_LINK' => 'email'], 'OIDC_LINK'];
        yield 'not a boolean' => [['OIDC_AUTO_CREATE' => 'sometimes'], 'OIDC_AUTO_CREATE'];
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('mistakes')]
    public function testAHalfSetConfigurationIsRefusedNamingTheVariable(array $env, string $variable): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($variable);

        OidcConfig::fromEnv(new Env($env + self::MINIMAL));
    }
}
