<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiEditor;
use Logbook\Service\Api\ApiEntries;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/vehicles/{id}/{list}/{entry} — edit an entry (spec.md
 * §7.20 *Phase 39*, #283): the sent fields over the stored entry, through
 * the edit form. 200 with the entry, its new `ETag` and any warnings.
 */
final readonly class EditEntryAction
{
    public function __construct(
        private ApiEditor $editor,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->editor->update(
            self::list($args),
            RequestContext::requireUser($request),
            RequestContext::vehicle($request),
            (int) ($args['entry'] ?? 0),
            JsonInput::decode((string) $request->getBody()),
            self::ifMatch($request),
        );

        return $this->responder->json(['entry' => $result['entry']->body, 'warnings' => $result['warnings']])
            ->withHeader('ETag', $result['entry']->tag);
    }

    /**
     * @param array<string, string> $args
     * @return value-of<ApiEntries::EDITABLE>
     */
    public static function list(array $args): string
    {
        $list = $args['list'] ?? '';
        if (!in_array($list, ApiEntries::EDITABLE, true)) {
            throw new \LogicException(sprintf('The route names no editable list ("%s").', $list));
        }

        return $list;
    }

    public static function ifMatch(ServerRequestInterface $request): ?string
    {
        $value = trim($request->getHeaderLine('If-Match'));

        return $value === '' ? null : $value;
    }
}
