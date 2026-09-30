<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\AbsoluteUrl;
use RuntimeException;

/**
 * The OpenAPI 3.1 description, `docs/api/openapi.json`: the single source
 * the app serves and the tests validate against (spec.md §7.20).
 */
final readonly class OpenApiDocument
{
    public const string PATH = '/docs/api/openapi.json';

    public function __construct(private AppSettings $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $rootDir): array
    {
        $contents = file_get_contents($rootDir . self::PATH);
        $document = $contents === false ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new RuntimeException('docs/api/openapi.json is missing or not an object.');
        }

        $out = [];
        foreach ($document as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The description with `servers` set to this install's API base.
     *
     * @return array<string, mixed>
     */
    public function forThisInstall(): array
    {
        $document = self::load($this->settings->rootDir);
        $document['servers'] = [[
            'url' => AbsoluteUrl::origin($this->settings->url, $this->settings->basePath) . $this->settings->basePath . '/api/v1',
            'description' => 'This Logbook',
        ]];

        return $document;
    }
}
