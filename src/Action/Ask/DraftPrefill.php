<?php

declare(strict_types=1);

namespace Logbook\Action\Ask;

use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Ai\Draft\DraftState;
use Logbook\Service\Ai\Draft\DraftNotFound;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A draft card's *Edit* (spec.md §7.26): the create form opened with
 * `?draft={id}` starts from the draft's form values, in the user's units
 * and language, with those fields marked "from your message". The form
 * carries the id back, and saving it closes the draft as added, so the
 * card no longer offers *Add*.
 */
final readonly class DraftPrefill
{
    /** Form field carrying the draft id; `_from_message` lists the drafted fields for the field macros. */
    public const string FIELD = 'draft';
    public const string MARKED = '_from_message';

    public function __construct(
        private DraftStore $drafts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The form's values from the draft named by `?draft=`, or $defaults when
     * there is none, it is someone else's, closed, or for another form.
     *
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    public function values(ServerRequestInterface $request, DraftKind $kind, ?int $vehicleId, array $defaults): array
    {
        $id = $request->getQueryParams()[self::FIELD] ?? null;
        if (!is_string($id) || !ctype_digit($id)) {
            return $defaults;
        }
        try {
            $draft = $this->drafts->get(RequestContext::requireUser($request), (int) $id);
        } catch (DraftNotFound) {
            return $defaults;
        }
        if (
            $draft->kind !== $kind
            || ($vehicleId !== null && $draft->vehicleId !== $vehicleId)
            || $draft->state($this->clock->now()) !== DraftState::Waiting
        ) {
            return $defaults;
        }

        return [
            ...$defaults,
            ...$draft->formValues,
            self::FIELD => (string) $draft->id,
            self::MARKED => implode(',', array_keys(array_filter($draft->formValues, static fn (string $v): bool => $v !== ''))),
        ];
    }

    /**
     * After the form saved: close the draft it came from, if any.
     */
    public function saved(ServerRequestInterface $request): void
    {
        $id = RequestContext::form($request)[self::FIELD] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            $this->drafts->closeByForm(RequestContext::requireUser($request), (int) $id);
        }
    }
}
