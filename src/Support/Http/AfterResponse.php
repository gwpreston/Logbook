<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Work that runs once the response has gone (spec.md §7.9 *Forgotten
 * password*: the email is sent after the answer, so whether one was sent
 * does not show in how long the answer took). The front controller runs it
 * after emitting the response. With PHP-FPM, fastcgi_finish_request()
 * closes the connection first. Elsewhere (Apache's mod_php, the Docker
 * image) the response marked by completeBeforeWork() carries its exact
 * length and `Connection: close`, is kept uncompressed, and is flushed
 * before the work starts, so the client has the whole answer while the
 * script goes on. A task that fails is logged and the rest still run.
 */
final class AfterResponse
{
    /** @var list<callable(): mixed> */
    private array $tasks = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param callable(): mixed $task
     */
    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function pending(): int
    {
        return count($this->tasks);
    }

    /**
     * Mark a response that must reach the client in full before queued work
     * runs: its exact length, `Connection: close`, and no compression
     * (mod_deflate would hold the end back until the script exits).
     */
    public static function completeBeforeWork(ResponseInterface $response): ResponseInterface
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        $size = $response->getBody()->getSize();

        $response = $response->withHeader('Connection', 'close');

        return $size === null ? $response : $response->withHeader('Content-Length', (string) $size);
    }

    public function run(bool $finishRequest = false): void
    {
        if ($this->tasks === []) {
            return;
        }
        if ($finishRequest) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
        }
        while ($this->tasks !== []) {
            $task = array_shift($this->tasks);
            try {
                $task();
            } catch (Throwable $e) {
                $this->logger->error('Work after the response failed: {error}', ['error' => $e->getMessage()]);
            }
        }
    }
}
