<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;

/**
 * Slim's error handler, logging through Monolog at a level that matches the
 * failure: client errors (404, 405, …) are routine and logged as info; server
 * errors are logged as errors with the exception attached.
 */
final class ErrorHandler extends SlimErrorHandler
{
    protected function writeToErrorLog(): void
    {
        $context = [
            'status' => $this->statusCode,
            'method' => $this->request->getMethod(),
            'path' => $this->request->getUri()->getPath(),
        ];

        if ($this->exception instanceof HttpException && $this->statusCode < 500) {
            $this->logger->info($this->exception->getMessage(), $context);

            return;
        }

        $this->logger->error(
            $this->exception->getMessage(),
            $context + ['exception' => $this->exception],
        );
    }
}
