<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\User\User;
use Logbook\Support\Display\DisplayPreferences;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Boots the app with a model on the network assigned to `ask`, a scripted
 * provider and an owner, for Ask Logbook's tests.
 */
abstract class AskTestCase extends AiTestCase
{
    use CostFixtures;

    protected const string NOW = '2026-10-15T12:00:00Z';

    /**
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, User}
     */
    protected function askApp(array $env = [], ?DisplayPreferences $preferences = null): array
    {
        $app = $this->aiApp($env);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'owner', $preferences);
        $connection = $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->assign($app, $connection, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);

        return [$app, $owner];
    }

    /**
     * The messages of the $index-th request sent to the model, as role and text.
     *
     * @return list<array{role: string, content: string}>
     */
    protected function sent(int $index): array
    {
        $messages = $this->provider->requests[$index]['json']['messages'] ?? [];
        $out = [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            $role = is_array($message) && is_string($message['role'] ?? null) ? $message['role'] : '';
            $content = is_array($message) && is_string($message['content'] ?? null) ? $message['content'] : '';
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    /**
     * The tool results in the $index-th request, in order.
     *
     * @return list<string>
     */
    protected function toolReplies(int $index): array
    {
        return array_values(array_map(
            static fn (array $m): string => $m['content'],
            array_filter($this->sent($index), static fn (array $m): bool => $m['role'] === 'tool'),
        ));
    }

    /**
     * @return array<mixed>
     */
    protected static function decoded(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function rows(App $app, string $table): int
    {
        $count = $this->connection($app)->fetchOne('SELECT COUNT(*) FROM ' . $table);

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * The thread id an answer's redirect (or JSON url) points at.
     */
    protected static function threadIn(string $location): string
    {
        preg_match('#/ask/threads/(\d+)#', $location, $m);

        return $m[1] ?? self::fail('No thread in ' . $location);
    }

    protected static function answerIn(string $location): string
    {
        preg_match('#answer-(\d+)#', $location, $m);

        return $m[1] ?? self::fail('No answer in ' . $location);
    }
}
