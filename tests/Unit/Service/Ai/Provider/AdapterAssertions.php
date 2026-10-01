<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Provider;

use Closure;
use Logbook\Service\Ai\Provider\ImageInput;
use Logbook\Service\Ai\Provider\ProviderError;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Tests\Support\AiProviderMock;

/**
 * Shared by the adapter tests: the recorded request must be what was sent.
 *
 * @property AiProviderMock $mock
 */
trait AdapterAssertions
{
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => ['colour' => ['type' => 'string'], 'count' => ['type' => 'integer']],
        'required' => ['colour', 'count'],
        'additionalProperties' => false,
    ];

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR42mO4oKBAEmIY1TCqYfhqAABLfxAQ'
        . 'egypMgAAAABJRU5ErkJggg==';

    private function assertSentFixture(string $name): void
    {
        $expected = AiProviderMock::fixture($name)['request'];
        self::assertNotNull($expected, sprintf('Fixture %s records no request.', $name));
        self::assertEquals($expected, $this->mock->last()['json'], sprintf('The request does not match %s.json.', $name));
    }

    private function failure(Closure $call): ProviderError
    {
        try {
            $call();
        } catch (ProviderError $e) {
            return $e;
        }
        self::fail('Expected a ProviderError.');
    }

    private static function addNumbers(): ToolDefinition
    {
        return new ToolDefinition('add_numbers', 'Adds two whole numbers.', [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']],
            'required' => ['a', 'b'],
        ]);
    }

    private static function image(): ImageInput
    {
        return new ImageInput((string) base64_decode(self::PNG, true), 'image/png');
    }
}
