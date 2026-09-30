<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Kernel;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;

/**
 * `php bin/api-key.php` (spec.md §7.20): the same keys as Settings → API
 * keys, for headless installs; `create` prints only the token on stdout.
 */
final class ApiKeyCommandTest extends AppTestCase
{
    use ApiFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testCreateListAndRevoke(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app, 'pat');

        [$code, $token, $messages] = self::command('create', '--user', 'Pat', '--name', 'OBD dongle', '--scope=read_write');
        self::assertSame(0, $code, $messages);
        self::assertMatchesRegularExpression('/^lbk_[A-Za-z0-9_-]{43}\n$/', $token, 'the token alone, for scripts');
        self::assertStringContainsString('shown only now', $messages);
        self::assertSame(200, $this->api($app, trim($token))->get('/me')->getStatusCode());

        [$code, $list] = self::command('list', '--user', 'pat');
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^(\d+)\tpat\tOBD dongle\tread_write\tcreated .+\tlast used .+\n$/', $list);
        self::assertStringNotContainsString(trim($token), $list);
        $id = (int) $list;

        [$code, $output] = self::command('revoke', (string) $id);
        self::assertSame(0, $code);
        self::assertSame("Revoked API key {$id} \"OBD dongle\".\n", $output);
        self::assertSame(401, $this->api($app, trim($token))->get('/me')->getStatusCode());
        self::assertStringContainsString("\trevoked ", self::command('list')[1]);
        self::assertSame(1, self::command('revoke', (string) $id)[0], 'once');
    }

    public function testMistakesAreRefused(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app, 'pat');

        self::assertSame(2, self::command()[0]);
        self::assertSame(2, self::command('create', '--user', 'pat', '--name', 'HA', '--scope', 'admin')[0]);
        self::assertSame(2, self::command('create', '--user', 'pat', '--scope', 'read')[0]);
        [$code, , $messages] = self::command('create', '--user', 'nobody', '--name', 'HA', '--scope', 'read');
        self::assertSame(1, $code);
        self::assertStringContainsString('No user named "nobody"', $messages);
        self::assertSame(1, self::command('revoke', '999999')[0]);
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM api_keys'));
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function command(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, Kernel::rootDir() . '/bin/api-key.php', ...array_values($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
