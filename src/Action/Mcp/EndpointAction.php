<?php

declare(strict_types=1);

namespace Logbook\Action\Mcp;

use Logbook\Middleware\CurrentUserMiddleware;
use Logbook\Service\Api\ApiKeyAuthenticator;
use Logbook\Service\Api\KeyRefused;
use Logbook\Service\Mcp\McpCall;
use Logbook\Service\Mcp\McpError;
use Logbook\Service\Mcp\McpRequestReader;
use Logbook\Service\Mcp\McpServer;
use Logbook\Support\Config\AppSettings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * POST /mcp — the MCP server's one endpoint (spec.md §7.28), Streamable
 * HTTP answered with single JSON objects. Outside the session and CSRF
 * groups, like the API: the key is the only way in.
 *
 * In order: an `Origin` not in API_CORS_ORIGINS is a 403 (DNS-rebinding
 * protection); anything but POST is a 405; a missing or bad key is a 401
 * and a throttled address a 429, as for the API; then the message is read,
 * checked for its protocol era and answered as the key's user. A
 * notification answers 202 with no body.
 */
final readonly class EndpointAction
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private ApiKeyAuthenticator $authenticator,
        private McpServer $server,
        private AppSettings $settings,
        private ResponseFactoryInterface $responses,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = strtolower(rtrim($request->getHeaderLine('Origin'), '/'));
        if ($origin !== '' && !in_array($origin, $this->settings->apiCorsOrigins, true)) {
            return $this->error(new McpError(
                McpError::FORBIDDEN_ORIGIN,
                'This origin may not use the MCP server. See API_CORS_ORIGINS.',
                403,
            ));
        }
        if ($request->getMethod() !== 'POST') {
            return $this->responses->createResponse(405)
                ->withHeader('Allow', 'POST')
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $holder = $this->authenticator->authenticate($request);
        } catch (KeyRefused $refused) {
            $error = $this->error(new McpError(
                $refused->status === 429 ? McpError::THROTTLED : McpError::UNAUTHORIZED,
                $refused->getMessage(),
                $refused->status,
            ));
            foreach ($refused->headers as $name => $value) {
                $error = $error->withHeader($name, $value);
            }

            return $error;
        }

        $request = $request->withAttribute(CurrentUserMiddleware::ATTRIBUTE, $holder->user);

        return $this->authenticator->as($holder, function () use ($request, $holder): ResponseInterface {
            $call = null;
            try {
                $call = McpRequestReader::read($request);
                $result = $this->server->handle($call, $holder);
            } catch (McpError $e) {
                return $this->error($call === null ? $e : $e->forId($call->id));
            } catch (Throwable $e) {
                $this->logger->error('MCP request failed', ['method' => $call?->method, 'exception' => $e]);

                return $this->error(new McpError(McpError::INTERNAL_ERROR, 'Internal error', 500, null, $call?->id));
            }
            if ($result === null) {
                return $this->responses->createResponse(202)->withHeader('Cache-Control', 'no-store');
            }

            return $this->result($call, $result);
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    private function result(McpCall $call, array $result): ResponseInterface
    {
        return $this->json(200, ['jsonrpc' => '2.0', 'id' => $call->id, 'result' => $result === [] ? new stdClass() : $result]);
    }

    private function error(McpError $error): ResponseInterface
    {
        $body = ['jsonrpc' => '2.0'];
        if ($error->id !== null) {
            $body['id'] = $error->id;
        }
        $body['error'] = ['code' => $error->rpcCode, 'message' => $error->getMessage()];
        if ($error->data !== null) {
            $body['error']['data'] = $error->data;
        }

        return $this->json($error->status, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode($body, self::JSON_FLAGS));

        return $response;
    }
}
