<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Domain\Access\ShareSummaryLine;
use Logbook\Service\Access\ShareSummary;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /vehicles/{id}/sharing/{member}/{action:save|remove} — the owner
 * changes or removes one share (spec.md §7.21; the route declares `Own`).
 * A user without a share on this vehicle is a 404. A save that widens
 * access says what it added.
 */
final readonly class ChangeShareAction
{
    public function __construct(
        private SharingService $sharing,
        private Redirector $redirect,
        private ShareSummary $summary,
        private UserDirectory $directory,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $userId = (int) ($args['member'] ?? 0);
        $session = RequestContext::session($request);

        if (($args['action'] ?? '') === 'remove') {
            if ($this->sharing->shareOfUserId($vehicle, $userId) === null) {
                throw new HttpNotFoundException($request);
            }
            $this->sharing->remove($vehicle, $userId);
            $session->flash('success', 'sharing.removed');
        } else {
            $share = ShareForm::parse(RequestContext::form($request));
            $old = $this->sharing->shareOfUserId($vehicle, $userId);
            if ($old === null || !$this->sharing->update($vehicle, $userId, $share->level, $share->canSeeCosts, $share->notify)) {
                throw new HttpNotFoundException($request);
            }
            $session->flash('success', 'sharing.saved');

            // Name what the save widened (spec.md §7.21, #395): no
            // confirmation page, since a share is easily undone.
            $gained = $this->summary->of($share->level, $share->canSeeCosts, $share->notify)
                ->gainedSince($this->summary->of($old->level, $old->canSeeCosts, $old->notify));
            if ($gained !== []) {
                $what = array_map(
                    fn (ShareSummaryLine $line): string => $this->translator->trans(
                        'share_summary.lines.' . $line->key,
                        $line->params + ['person' => 'they'],
                    ),
                    $gained,
                );
                $session->flash('info', 'sharing.widened', [
                    'name' => $this->directory->find($userId)->displayName ?? '',
                    'what' => implode('; ', $what),
                ]);
            }
        }

        return $this->redirect->toRoute('vehicles.sharing', ['id' => (string) $vehicle->id]);
    }
}
