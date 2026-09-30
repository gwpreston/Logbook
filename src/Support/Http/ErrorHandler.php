<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Middleware\ApiErrorMiddleware;
use Logbook\Support\Api\ApiPath;
use Logbook\Support\Api\ApiResponder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Interfaces\CallableResolverInterface;

/**
 * Slim's error handler, logging through Monolog at a level that matches the
 * failure: client errors (404, 405, …) are routine and logged as info; server
 * errors are logged as errors with the exception attached.
 *
 * Errors raised before an API route's own middleware runs (an unknown API
 * path, a wrong method, the API switched off) are answered as problem
 * details, like everything else under /api/ (spec.md §7.20).
 */
final class ErrorHandler extends SlimErrorHandler
{
    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        ?LoggerInterface $logger = null,
        private readonly string $basePath = '',
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    protected function respond(): ResponseInterface
    {
        if (!ApiPath::matches($this->request->getUri()->getPath(), $this->basePath)) {
            return parent::respond();
        }

        $problem = ApiErrorMiddleware::toProblem($this->exception);
        $response = $this->responseFactory->createResponse($problem->status);
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write(ApiResponder::encode(ApiResponder::problemBody($problem, $response->getReasonPhrase())));

        return $response
            ->withHeader('Content-Type', ApiResponder::PROBLEM)
            ->withHeader('Cache-Control', 'no-store');
    }

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
