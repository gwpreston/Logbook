<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Oidc;

use Logbook\Domain\User\User;

/**
 * The end of a callback: what happened, the user to sign in (or who
 * linked), where to go next, and the ID token for the provider's sign-out.
 */
final readonly class OidcResult
{
    public function __construct(
        public OidcOutcome $outcome,
        public ?User $user = null,
        public ?string $next = null,
        public ?string $idToken = null,
    ) {
    }

    public static function failed(): self
    {
        return new self(OidcOutcome::Failed);
    }

    public function signsIn(): bool
    {
        return in_array($this->outcome, [OidcOutcome::SignedIn, OidcOutcome::Created], true) && $this->user !== null;
    }
}
