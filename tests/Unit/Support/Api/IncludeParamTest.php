<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Api;

use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\IncludeParam;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `?include=` (spec.md §7.20, Phase 48.2): only `working` exists, and a typo
 * is a 400 rather than "nothing extra".
 */
final class IncludeParamTest extends TestCase
{
    public function testNoParameterAsksForNothing(): void
    {
        self::assertFalse(IncludeParam::working($this->request('')));
        self::assertFalse(IncludeParam::working($this->request('include=')));
    }

    public function testWorkingAloneOrInAList(): void
    {
        self::assertTrue(IncludeParam::working($this->request('include=working')));
        self::assertTrue(IncludeParam::working($this->request('include=%20working%20,')));
    }

    public function testAnythingElseIsABadRequest(): void
    {
        $this->expectException(ApiProblem::class);
        $this->expectExceptionMessage('only "working" can be included');

        IncludeParam::working($this->request('include=working,cheese'));
    }

    public function testAListFormIsABadRequest(): void
    {
        $this->expectException(ApiProblem::class);

        IncludeParam::working($this->request('include[]=working'));
    }

    private function request(string $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/v1/x?' . $query)
            ->withQueryParams($this->parse($query));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parse(string $query): array
    {
        parse_str($query, $params);

        return $params;
    }
}
