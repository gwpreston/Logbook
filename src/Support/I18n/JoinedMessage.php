<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A list of translatable parts joined with a separator ("front left, rear
 * right"), for a list whose length ICU cannot express.
 */
final readonly class JoinedMessage implements TranslatableInterface
{
    /**
     * @param list<TranslatableInterface|string> $parts
     */
    public function __construct(private array $parts, private string $separator = ', ')
    {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return implode($this->separator, array_map(
            static fn (TranslatableInterface|string $part): string => is_string($part)
                ? $part
                : $part->trans($translator, $locale),
            $this->parts,
        ));
    }
}
