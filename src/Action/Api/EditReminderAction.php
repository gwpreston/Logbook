<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Service\Api\ApiReminders;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH and DELETE /api/v1/reminders/{reminder} — a manual reminder's
 * *Edit* and *Delete* (spec.md §7.20 *Writes, edits and deletes*): 200
 * with the reminder and its `ETag`, or 204.
 */
final readonly class EditReminderAction
{
    public function __construct(
        private ApiReminders $reminders,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $id = (int) ($args['reminder'] ?? 0);
        if ($request->getMethod() === 'DELETE') {
            $this->reminders->delete($user, $id, EditEntryAction::ifMatch($request));

            return $response->withStatus(204);
        }
        $result = $this->reminders->update(
            $user,
            $id,
            JsonInput::decode((string) $request->getBody()),
            EditEntryAction::ifMatch($request),
        );

        return $this->responder->json(['entry' => $result['reminder'], 'warnings' => []])
            ->withHeader('ETag', $result['tag']);
    }
}
