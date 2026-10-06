<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRestriction;
use Logbook\Service\Demo\DemoRoutes;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Http\DemoBlockedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;

/**
 * What a demo visitor may not do (spec.md §7.36), after routing: the blocked
 * routes answer the *Not available in the demo* page (a JSON problem under
 * the API), `/setup` is a 404, and a request carrying a file is refused. In
 * any other state of the guard it does nothing.
 */
final readonly class DemoGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DemoMode $mode,
        private ApiResponder $responder,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->mode->isActive()) {
            return $handler->handle($request);
        }

        $name = RouteContext::fromRequest($request)->getRoute()?->getName();
        if ($name === 'setup') {
            throw new HttpNotFoundException($request);
        }
        if (DemoRoutes::isApi($name) || $name === 'mcp') {
            return $this->responder->problem(new ApiProblem(403, 'demo', 'This is not available in the demo.'));
        }
        if (DemoRoutes::isBlocked($name) || ($this->mode->blocks(DemoRestriction::Uploads) && self::carriesFile($request))) {
            throw new DemoBlockedException($request);
        }

        return $handler->handle($request);
    }

    private static function carriesFile(ServerRequestInterface $request): bool
    {
        return self::anyFile($request->getUploadedFiles());
    }

    /**
     * @param array<mixed> $files
     */
    private static function anyFile(array $files): bool
    {
        foreach ($files as $file) {
            if (is_array($file) ? self::anyFile($file) : ($file instanceof UploadedFileInterface && self::isFile($file))) {
                return true;
            }
        }

        return false;
    }

    /** An empty file field (no file chosen) is not a file. */
    private static function isFile(UploadedFileInterface $file): bool
    {
        return $file->getError() !== UPLOAD_ERR_NO_FILE;
    }
}
