<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Ai\Draft\DraftSource;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Ai\Draft\DraftInvalid;
use Logbook\Service\Ai\Draft\DraftNotFound;
use Logbook\Service\Ai\Draft\DraftRefused;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * POST /ask/drafts/{draft}/{action:add|discard|undo} and
 * POST /drafts/{draft}/{action} — a draft card's buttons (spec.md §7.26
 * *Drafting entries*, §7.28 *Drafts to review*). *Add* writes the draft as
 * it stands at the press, with the access the user has then; *Discard*
 * closes it; *Undo* deletes what *Add* wrote, for a few seconds. Another
 * user's draft is not found.
 *
 * An Ask draft needs Ask, and goes back to its card in its thread. An MCP
 * client's draft needs only its user (no AI is involved) and goes back to
 * the page it was listed on: the dashboard, or `/ask`. The `/drafts` route
 * serves MCP drafts only.
 */
final readonly class DraftAction
{
    /** Where an MCP draft's buttons may send the user back to. */
    private const array BACK = ['home' => 'home', 'ask' => 'ask'];

    public function __construct(
        private AskGuard $guard,
        private AskAvailability $ask,
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
        $user = RequestContext::requireUser($request);
        $id = $args['draft'] ?? '';
        if (!ctype_digit($id)) {
            throw new HttpNotFoundException($request);
        }
        $session = RequestContext::session($request);
        $review = RouteContext::fromRequest($request)->getRoute()?->getName() === 'drafts.action';
        try {
            $draft = $this->drafts->get($user, (int) $id);
            if ($draft->source !== DraftSource::Mcp) {
                if ($review) {
                    throw new DraftNotFound();
                }
                $this->guard->user($request);
            }
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

        return $this->back($request, $draft);
    }

    private function back(ServerRequestInterface $request, AiDraft $draft): ResponseInterface
    {
        if ($draft->source === DraftSource::Mcp) {
            $back = RequestContext::form($request)['back'] ?? null;
            $route = self::BACK[is_string($back) ? $back : ''] ?? 'home';
            if ($route === 'ask' && !$this->ask->isAvailable(RequestContext::requireUser($request))) {
                $route = 'home';
            }

            return $this->redirect->to($this->redirect->urlFor($route) . '#drafts-to-review');
        }
        if ($draft->threadId === null) {
            return $this->redirect->toRoute('ask');
        }

        return $this->redirect->to(
            $this->redirect->urlFor('ask.thread', ['thread' => (string) $draft->threadId]) . '#draft-' . $draft->id,
        );
    }
}
