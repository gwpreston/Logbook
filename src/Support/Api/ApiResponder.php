<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON and problem-details responses for the API (spec.md §7.20). Values
 * are already strings where precision matters (Serializer), so encoding
 * never turns a decimal into a float.
 */
final readonly class ApiResponder
{
    public const string JSON = 'application/json';
    public const string PROBLEM = 'application/problem+json';

    public function __construct(
        private ResponseFactoryInterface $responses,
        private AppSettings $settings,
    ) {
    }

    /**
     * A list page: `{"items": [...], "next": url|null}`, `next` being this
     * request's URL with the next page's cursor.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $extra more top-level fields
     */
    public function page(ServerRequestInterface $request, array $items, ?string $cursor, array $extra = []): ResponseInterface
    {
        $next = null;
        if ($cursor !== null) {
            $query = ['cursor' => $cursor] + $request->getQueryParams();
            $query['cursor'] = $cursor;
            $next = AbsoluteUrl::origin($this->settings->url, $this->settings->basePath)
                . $request->getUri()->getPath() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->json($extra + ['items' => $items, 'next' => $next]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->write($this->responses->createResponse($status), $data, self::JSON);
    }

    public function problem(ApiProblem $problem): ResponseInterface
    {
        $response = $this->responses->createResponse($problem->status);
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $this->write($response, self::problemBody($problem, $response->getReasonPhrase()), self::PROBLEM);
    }

    /**
     * @return array<string, mixed>
     */
    public static function problemBody(ApiProblem $problem, string $title): array
    {
        $body = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $problem->status,
            'code' => $problem->problemCode,
            'detail' => $problem->detail(),
        ];
        if ($problem->errors !== []) {
            $body['errors'] = $problem->errors;
        }

        return $body + $problem->extra;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function write(ResponseInterface $response, array $data, string $type): ResponseInterface
    {
        $response->getBody()->write(self::encode($data));

        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
