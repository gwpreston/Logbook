<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Service\Ai\Draft\DraftInvalid;
use Logbook\Service\Ai\Draft\DraftNotFound;
use Logbook\Service\Ai\Draft\DraftRefused;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /ask/drafts/{draft}/{action:add|discard|undo} — a draft card's
 * buttons (spec.md §7.26 *Drafting entries*). *Add* writes the draft as it
 * stands at the press, with the access the user has then; *Discard* closes
 * it; *Undo* deletes what *Add* wrote, for a few seconds. Another user's
 * draft is not found. Every outcome goes back to the card in its thread.
 */
final readonly class DraftAction
{
    public function __construct(
        private AskGuard $guard,
        private DraftStore $drafts,
        private Redirector $redirect,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->guard->user($request);
        $id = $args['draft'] ?? '';
        if (!ctype_digit($id)) {
            throw new HttpNotFoundException($request);
        }
        $session = RequestContext::session($request);
        try {
            $draft = $this->drafts->get($user, (int) $id);
            $kind = $this->translator->trans($draft->kind->labelKey());
            switch ($args['action'] ?? '') {
                case 'add':
                    $applied = $this->drafts->apply($user, $draft->id);
                    $session->flash(
                        $applied->written->duplicate ? 'info' : 'success',
                        $applied->written->duplicate ? 'ask.draft.duplicate' : 'ask.draft.flash.added',
                        ['kind' => $kind],
                    );
                    break;
                case 'discard':
                    $this->drafts->discard($user, $draft->id);
                    $session->flash('success', 'ask.draft.flash.discarded');
                    break;
                case 'undo':
                    $this->drafts->undo($user, $draft->id);
                    $session->flash('success', 'ask.draft.flash.undone', ['kind' => $kind]);
                    break;
                default:
                    throw new HttpNotFoundException($request);
            }
        } catch (DraftNotFound) {
            throw new HttpNotFoundException($request);
        } catch (DraftRefused $refused) {
            $session->flash('error', $refused->key);
        } catch (DraftInvalid $invalid) {
            foreach ($invalid->errors->all() as $error) {
                $params = [];
                foreach ($error['params'] as $name => $value) {
                    $params[$name] = $value instanceof TranslatableInterface ? $value->trans($this->translator) : $value;
                }
                $session->flash('error', $error['key'], $params);
            }
        }

        return $this->back($draft);
    }

    private function back(AiDraft $draft): ResponseInterface
    {
        if ($draft->threadId === null) {
            return $this->redirect->toRoute('ask');
        }

        return $this->redirect->to(
            $this->redirect->urlFor('ask.thread', ['thread' => (string) $draft->threadId]) . '#draft-' . $draft->id,
        );
    }
}
