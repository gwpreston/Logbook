<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiIssues;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/vehicles/{id}/issues (spec.md §7.20 *Issues*): through the
 * issue form's parser; a retry finds the one already logged.
 */
final readonly class LogIssueAction
{
    public function __construct(
        private ApiIssues $issues,
        private ApiResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->issues->log(
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            JsonInput::decode((string) $request->getBody()),
        );

        return $this->responder->json($result, $result['duplicate'] ? 200 : 201);
    }
}
