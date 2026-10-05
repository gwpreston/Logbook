<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask;

use Logbook\Service\Access\AccessContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `ask_available()` for the entry points (header, dashboard), and the
 * `ask_answer` filter: an answer as safe HTML, paragraphs and lists kept,
 * every figure the grounding check couldn't match marked (spec.md §7.26).
 */
final class AskTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly AskAvailability $availability,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ask_available', fn (): bool => $this->availability->isAvailable($this->context->user())),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ask_answer', $this->answer(...), ['is_safe' => ['html']]),
            // One line with the same marks, for an AI insight's title (Phase 33.4).
            new TwigFilter('ask_marked', $this->mark(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param list<string> $ungrounded
     */
    public function answer(string $text, array $ungrounded = []): string
    {
        $html = [];
        foreach (preg_split('/\n\s*\n/u', trim($text)) ?: [] as $block) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", $block)),
                static fn (string $l): bool => $l !== '',
            ));
            $isList = $lines !== [] && array_reduce(
                $lines,
                static fn (bool $all, string $l): bool => $all && preg_match('/^([-*•]|\d+[.)])\s+/u', $l) === 1,
                true,
            );
            if ($isList) {
                $items = array_map(
                    fn (string $l): string => '<li>'
                        . $this->mark((string) preg_replace('/^([-*•]|\d+[.)])\s+/u', '', $l), $ungrounded)
                        . '</li>',
                    $lines,
                );
                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }
            $marked = array_map(fn (string $l): string => $this->mark($l, $ungrounded), $lines);
            $html[] = '<p>' . implode('<br>', $marked) . '</p>';
        }

        return implode("\n", $html);
    }

    /**
     * @param list<string> $ungrounded
     */
    public function mark(string $line, array $ungrounded = []): string
    {
        $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        // Markdown bold, which models use often.
        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
        if ($ungrounded === []) {
            return $escaped;
        }
        $title = htmlspecialchars($this->translator->trans('ask.grounding.mark'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tokens = array_map(
            static fn (string $t): string => preg_quote(htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/'),
            $ungrounded,
        );
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return (string) preg_replace(
            '/(?<![\d.,])(' . implode('|', $tokens) . ')(?![\d])/u',
            '<mark class="ask-unverified" title="' . $title . '">$1</mark>',
            $escaped,
        );
    }
}
