<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Support\View\AssetPackage;
use PHPUnit\Framework\TestCase;

final class AssetPackageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logbook-assets-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/manifest.json', '{"css/app.css": "abc123"}');
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/manifest.json');
        rmdir($this->dir);
    }

    public function testVersionedUrlUnderBasePath(): void
    {
        $assets = new AssetPackage('/logbook', $this->dir);

        self::assertSame('/logbook/assets/css/app.css?v=abc123', $assets->url('css/app.css'));
        self::assertSame('/logbook/assets/css/app.css?v=abc123', $assets->url('/css/app.css'));
    }

    public function testUnknownAssetHasNoVersion(): void
    {
        $assets = new AssetPackage('', $this->dir);

        self::assertSame('/assets/img/logo.svg', $assets->url('img/logo.svg'));
    }

    public function testMissingManifestIsTolerated(): void
    {
        $assets = new AssetPackage('', $this->dir . '/nope');

        self::assertSame('/assets/css/app.css', $assets->url('css/app.css'));
    }
}
