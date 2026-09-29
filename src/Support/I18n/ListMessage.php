<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Parts joined as a list in the current language: "front left", "front
 * left and front right", "front left, front right and rear left". The
 * words come from the catalogue (`list.pair`, `list.last`), as ICU list
 * patterns do.
 */
final readonly class ListMessage implements TranslatableInterface
{
    /**
     * @param list<TranslatableInterface|string> $parts
     */
    public function __construct(private array $parts)
    {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $words = array_map(
            static fn (TranslatableInterface|string $part): string => is_string($part)
                ? $part
                : $part->trans($translator, $locale),
            $this->parts,
        );
        $last = array_pop($words);
        if ($last === null) {
            return '';
        }
        if ($words === []) {
            return $last;
        }
        if (count($words) === 1) {
            return $translator->trans('list.pair', ['first' => $words[0], 'second' => $last], null, $locale);
        }

        return $translator->trans('list.last', ['list' => implode(', ', $words), 'last' => $last], null, $locale);
    }
}
