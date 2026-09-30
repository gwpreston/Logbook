<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\AccessDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Everything that goes wrong under /api/v1 becomes problem details (spec.md
 * §7.20), never the HTML error page: ApiProblem as thrown, the vehicle
 * access and module gate's 404 and 403, and any other failure as a 500
 * that says nothing about the inside (logged with the exception).
 */
final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ApiResponder $responder,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiProblem $problem) {
            return $this->responder->problem($problem);
        } catch (Throwable $exception) {
            $problem = self::toProblem($exception);
            if ($problem->status >= 500) {
                $this->logger->error($exception->getMessage(), [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'exception' => $exception,
                ]);
            }

            return $this->responder->problem($problem);
        }
    }

    public static function toProblem(Throwable $exception): ApiProblem
    {
        return match (true) {
            $exception instanceof ApiProblem => $exception,
            $exception instanceof HttpNotFoundException => ApiProblem::notFound(),
            $exception instanceof HttpMethodNotAllowedException => new ApiProblem(
                405,
                'method_not_allowed',
                'This address does not answer that method.',
                headers: ['Allow' => implode(', ', $exception->getAllowedMethods())],
            ),
            $exception instanceof AccessDeniedException, $exception instanceof HttpForbiddenException => new ApiProblem(
                403,
                'forbidden',
                'The key\'s user may see this vehicle but is not allowed to do this with it.',
            ),
            $exception instanceof HttpException && $exception->getCode() < 500 => new ApiProblem(
                $exception->getCode(),
                'http_' . $exception->getCode(),
                $exception->getDescription(),
            ),
            default => new ApiProblem(500, 'internal_error', 'Something went wrong on the server. It has been logged.'),
        };
    }
}
