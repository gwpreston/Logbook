<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Domain\Ai\Scan\ScanKind;

/**
 * What a document is, from its words (spec.md §7.27 *Reading without AI*,
 * Phase 53.4): each kind scores the different keywords of its own found
 * in the text (`scan.keywords.<kind>`), a fuel receipt only when a volume
 * or a price per unit is printed (and one more for a volume). The highest
 * score wins, a tie going to the kind listed first; with none, *other*.
 * V5C markers are keywords like any other, so an invoice's own words can
 * outscore "VSC warning light" (#524).
 */
final readonly class Classifier
{
    /** In the order a tie goes. */
    private const array KINDS = [
        ScanKind::FuelReceipt,
        ScanKind::Inspection,
        ScanKind::ClaimLetter,
        ScanKind::Insurance,
        ScanKind::RepairEstimate,
        ScanKind::ServiceInvoice,
        ScanKind::Registration,
    ];

    public function __construct(private Keywords $keywords)
    {
    }

    /**
     * @param list<string> $rows
     */
    public function kind(array $rows, string $locale, FuelReceiptRules $fuel): ScanKind
    {
        $text = implode("\n", $rows);
        $best = ScanKind::Other;
        $bestScore = 0;
        foreach (self::KINDS as $kind) {
            $score = count($this->keywords->found($kind->value, $locale, $text));
            if ($kind === ScanKind::FuelReceipt) {
                $printed = $fuel->printed($rows, $locale);
                $score = $printed === 0 ? 0 : $score + ($printed > 1 ? 1 : 0);
            }
            if ($score > $bestScore) {
                $best = $kind;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
