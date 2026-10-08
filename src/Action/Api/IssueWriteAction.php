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
 * POST /api/v1/vehicles/{id}/issues/{entry}/{updates|fix|reopen} (spec.md
 * §7.20 *Issues*): *Add update*, *Mark fixed* and *It's back* / *Reopen*,
 * each answering the issue as its read returns it, with its new `ETag`. A
 * fix or reopen that changes nothing says `unchanged: true`. `If-Match` is
 * honoured when sent.
 */
final readonly class IssueWriteAction
{
    public function __construct(
        private ApiIssues $issues,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $vehicle = RequestContext::vehicle($request);
        $state = $this->issues->state($vehicle, (int) ($args['entry'] ?? 0));
        $this->issues->precondition(EditEntryAction::ifMatch($request), $state);

        $write = $args['write'] ?? '';
        $warnings = [];
        $changed = true;
        switch ($write) {
            case 'updates':
                $warnings = $this->issues->addUpdate($user, $vehicle, $state, JsonInput::decode((string) $request->getBody()));
                break;
            case 'fix':
                $changed = $this->issues->fix($user, $vehicle, $state, JsonInput::decode((string) $request->getBody()));
                break;
            case 'reopen':
                $changed = $this->issues->reopen($user, $vehicle, $state);
                break;
            default:
                throw new \LogicException('The route names no issue write.');
        }

        $saved = $this->issues->state($vehicle, $state->issue->id);
        $body = ['entry' => $this->issues->serialize($saved), 'warnings' => $warnings];
        if ($write !== 'updates') {
            $body['unchanged'] = !$changed;
        }

        return $this->responder->json($body, $write === 'updates' ? 201 : 200)
            ->withHeader('ETag', $this->issues->tag($saved));
    }
}
