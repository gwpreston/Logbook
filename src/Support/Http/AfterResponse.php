<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Work that runs once the response has gone (spec.md §7.9 *Forgotten
 * password*: the email is sent after the answer, so whether one was sent
 * does not show in how long the answer took). The front controller runs it
 * after emitting the response; with PHP-FPM, fastcgi_finish_request()
 * closes the connection first. A task that fails is logged and the rest
 * still run.
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

    public function run(bool $finishRequest = false): void
    {
        if ($this->tasks === []) {
            return;
        }
        if ($finishRequest && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
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
