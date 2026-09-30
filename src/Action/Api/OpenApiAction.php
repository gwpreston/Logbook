<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\OpenApiDocument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/openapi.json — the OpenAPI description (docs/api/openapi.json),
 * its `servers` pointing at this install. No key needed.
 */
final readonly class OpenApiAction
{
    public function __construct(
        private OpenApiDocument $document,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json($this->document->forThisInstall())
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
