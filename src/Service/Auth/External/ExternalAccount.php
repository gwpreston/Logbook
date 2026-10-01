<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\External;

/**
 * An account vouched for by something outside Logbook (spec.md §7.9): an
 * OIDC provider's validated ID token, or a trusted sign-in proxy. Issuer
 * and subject identify it; the rest is what it says about the person.
 */
final readonly class ExternalAccount
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public string $issuer,
        public string $subject,
        /** The claimed username, as sent (normalised when used). */
        public ?string $username = null,
        public ?string $displayName = null,
        public ?string $email = null,
        /** A locale such as "de" or "en-GB", when the provider says. */
        public ?string $locale = null,
        public array $groups = [],
    ) {
    }

    /**
     * The groups claim as names: a list, or one string.
     *
     * @return list<string>
     */
    public static function groupList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
