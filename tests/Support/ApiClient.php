<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Logbook\Kernel;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\OpenApiDocument;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Drives the REST API (spec.md §7.20) with a key, and validates every
 * response it gets against docs/api/openapi.json: the operation's schema
 * for its status and content type, or the problem schema for errors the
 * router answers before any operation (unknown paths). So every API test
 * is a contract test too.
 */
final class ApiClient
{
    private static ?ResponseValidator $validator = null;
    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    /** The client address the throttle counts: its own, so tests never throttle each other. */
    public readonly string $address;

    /**
     * @param App<ContainerInterface> $app
     */
    public function __construct(
        private readonly App $app,
        private readonly ?string $token = null,
        private readonly string $prefix = '/api/v1',
        ?string $address = null,
    ) {
        $this->address = $address ?? '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(4));
    }

    public function withToken(?string $token): self
    {
        return new self($this->app, $token, $this->prefix, $this->address);
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $path, null, $headers);
    }

    /**
     * @param array<string, mixed>|string $body an array is sent as JSON; a string as it is
     * @param array<string, string> $headers
     */
    public function post(string $path, array|string $body, array $headers = []): ResponseInterface
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return $this->send('POST', $path, $raw, $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, mixed>|string $body an array is sent as JSON; a string as it is
     * @param array<string, string> $headers
     */
    public function patch(string $path, array|string $body, array $headers = []): ResponseInterface
    {
        $raw = is_string($body) ? $body : json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return $this->send('PATCH', $path, $raw, $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function put(string $path, array|string|null $body = null, array $headers = []): ResponseInterface
    {
        $raw = is_array($body) ? json_encode((object) $body, JSON_THROW_ON_ERROR) : $body;

        return $this->send('PUT', $path, $raw, $headers + ($raw === null ? [] : ['Content-Type' => 'application/json']));
    }

    /**
     * A `multipart/form-data` POST with one file (spec.md §7.20
     * *Attachments*): the bytes are written to a temporary file, as PHP
     * would, and handed to the app as an uploaded file named $field.
     *
     * @param array<string, string> $headers
     */
    public function upload(
        string $path,
        string $contents,
        string $name,
        string $type = 'application/pdf',
        string $field = 'file',
        array $headers = [],
    ): ResponseInterface {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logbook-api-upload-');
        file_put_contents($tmp, $contents);
        $file = new UploadedFile($tmp, $name, $type, strlen($contents), UPLOAD_ERR_OK);
        try {
            $headers += ['Content-Type' => 'multipart/form-data; boundary=x'];

            return $this->send('POST', $path, null, $headers, [$field => $file]);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $path, array $headers = []): ResponseInterface
    {
        return $this->send('DELETE', $path, null, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, UploadedFileInterface> $files
     */
    public function send(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
        array $files = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $this->prefix . $path,
            ['REMOTE_ADDR' => $this->address],
        );
        if ($this->token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        $response = $this->app->handle($request);
        self::assertMatchesContract($method, $path, $response);

        return $response;
    }

    public static function json(ResponseInterface $response): JsonDoc
    {
        $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data);

        return new JsonDoc($data);
    }

    public static function assertMatchesContract(string $method, string $path, ResponseInterface $response): void
    {
        if ($method === 'OPTIONS') {
            // CORS preflights are not operations: ApiCorsMiddleware answers them, empty or as a problem.
            Assert::assertContains($response->getStatusCode(), [204, 403], 'preflight ' . $path);
            if ($response->getStatusCode() === 204) {
                Assert::assertSame('', (string) $response->getBody());
            }

            return;
        }
        $template = self::operationPath(strtok($path, '?') ?: $path);
        $body = (string) $response->getBody();
        $response->getBody()->rewind();

        if ($template === null || $response->getStatusCode() === 405) {
            // Answered by the router, before any operation: a problem, always.
            Assert::assertSame(ApiResponder::PROBLEM, $response->getHeaderLine('Content-Type'), $method . ' ' . $path);
            $problem = json_decode($body, true);
            Assert::assertIsArray($problem);
            Assert::assertSame(
                ['type', 'title', 'status', 'code', 'detail'],
                array_keys($problem),
                'router problem for ' . $method . ' ' . $path,
            );

            return;
        }

        try {
            self::validator()->validate(new OperationAddress($template, strtolower($method)), $response);
        } catch (ValidationFailed $failure) {
            $reason = $failure->getMessage();
            for ($previous = $failure->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                $reason .= ' → ' . $previous->getMessage();
            }
            Assert::fail(sprintf(
                "%s %s answered %d, which does not match openapi.json:\n%s\n%s",
                $method,
                $path,
                $response->getStatusCode(),
                $reason,
                $body,
            ));
        }
    }

    private static function validator(): ResponseValidator
    {
        return self::$validator ??= (new ValidatorBuilder())
            ->fromJsonFile(Kernel::rootDir() . OpenApiDocument::PATH)
            ->getResponseValidator();
    }

    /**
     * The description's path template for a request path ("/vehicles/3/fuel" → "/vehicles/{id}/fuel").
     */
    private static function operationPath(string $path): ?string
    {
        self::$document ??= OpenApiDocument::load(Kernel::rootDir());
        $paths = self::$document['paths'] ?? [];
        Assert::assertIsArray($paths);
        foreach (array_keys($paths) as $template) {
            $pattern = '#^' . preg_replace('/\\\\\{[a-z_]+\\\\\}/', '[^/]+', preg_quote((string) $template, '#')) . '$#';
            if (preg_match($pattern, $path) === 1) {
                return (string) $template;
            }
        }

        return null;
    }
}
