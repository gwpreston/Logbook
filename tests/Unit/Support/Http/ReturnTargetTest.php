<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Http;

use Logbook\Support\Http\ReturnTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The `return` a form keeps (spec.md §5) is checked like the sign-in
 * redirect: local paths under the base path only.
 */
final class ReturnTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function targets(): iterable
    {
        yield 'a history page' => ['/vehicles/3/history?year=2025&kind=fuel', '', '/vehicles/3/history?year=2025&kind=fuel'];
        yield 'at a subpath' => ['/logbook/history?vehicle=2', '/logbook', '/logbook/history?vehicle=2'];
        yield 'outside the subpath' => ['/other/history', '/logbook', null];
        yield 'another site' => ['https://evil.example/', '', null];
        yield 'protocol-relative' => ['//evil.example/', '', null];
        yield 'backslash trick' => ['/\\evil.example', '', null];
        yield 'a relative path' => ['history', '', null];
        yield 'empty' => ['', '', null];
    }

    #[DataProvider('targets')]
    public function testOnlyLocalPathsAreAccepted(string $target, string $basePath, ?string $expected): void
    {
        $query = (new ServerRequestFactory())->createServerRequest('GET', '/x')->withQueryParams(['return' => $target]);
        self::assertSame($expected, ReturnTarget::of($query, $basePath), 'from the query');

        $form = (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['return' => $target]);
        self::assertSame($expected, ReturnTarget::of($form, $basePath), 'from the form');
    }

    public function testAnArrayIsIgnored(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['return' => ['/a']]);
        self::assertNull(ReturnTarget::of($request, ''));
    }
}
