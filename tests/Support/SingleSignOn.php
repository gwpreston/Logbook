<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Logbook\Service\Auth\Oidc\OidcCache;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An app with single sign-on configured against FakeIdentityProvider, and
 * the browser's round trip through it (spec.md §7.9). For AppTestCase
 * subclasses.
 */
trait SingleSignOn
{
    /** @var list<string> */
    private array $oidcCacheDirs = [];

    /**
     * @param array<string, string> $env more OIDC_* or AUTH_* variables
     * @return array{App<ContainerInterface>, FakeIdentityProvider}
     */
    protected function ssoApp(array $env = [], string $issuer = FakeIdentityProvider::ISSUER): array
    {
        $app = $this->createApp($env + [
            // Pinned: the redirect URI is built from it, and dev containers set their own.
            'APP_URL' => 'http://localhost:8080',
            'OIDC_ISSUER' => $issuer,
            'OIDC_CLIENT_ID' => FakeIdentityProvider::CLIENT_ID,
            'OIDC_CLIENT_SECRET' => FakeIdentityProvider::CLIENT_SECRET,
            'OIDC_PROVIDER_NAME' => 'Authentik',
        ]);

        return [$app, $this->attachProvider($app, $issuer)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function attachProvider(App $app, string $issuer = FakeIdentityProvider::ISSUER): FakeIdentityProvider
    {
        $idp = new FakeIdentityProvider($issuer);
        $container = $app->getContainer();
        Assert::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, $idp->client);
        $dir = sys_get_temp_dir() . '/logbook-oidc-' . bin2hex(random_bytes(4));
        $this->oidcCacheDirs[] = $dir;
        $container->set(OidcCache::class, new OidcCache($dir));

        return $idp;
    }

    /**
     * Click *Sign in with …*, sign in at the provider as $claims, come back.
     *
     * @param array<string, mixed> $claims
     * @return ResponseInterface the callback's answer
     */
    protected function ssoSignIn(
        TestBrowser $browser,
        FakeIdentityProvider $idp,
        array $claims,
        ?string $next = null,
    ): ResponseInterface {
        $start = $browser->get('/auth/oidc/start' . ($next !== null ? '?next=' . rawurlencode($next) : ''));
        Assert::assertSame(303, $start->getStatusCode(), 'the start goes to the provider');

        return $browser->get($idp->authorize($start->getHeaderLine('Location'), $claims));
    }

    protected function removeOidcCaches(): void
    {
        foreach ($this->oidcCacheDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->oidcCacheDirs = [];
    }
}
