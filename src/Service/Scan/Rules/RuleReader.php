<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\OcrText;
use Logbook\Service\Ai\Scan\PreparedFile;
use Logbook\Service\Ai\Scan\ReadBy;
use Logbook\Service\Ai\Scan\ScanProblem;
use Logbook\Service\Ai\Scan\Scrubber;
use Logbook\Service\Ai\Scan\VehicleMatcher;
use Logbook\Service\Ocr\OcrReader;
use Logbook\Service\Station\StationService;

/**
 * Reading a file without AI (spec.md §7.27 *Reading without AI*, Phase
 * 53.4): a photo or scanned PDF by OCR, a text PDF by its text layer, then
 * by rules. The result is what a model gives (per kind the schema's fields
 * as `{value, evidence}`, the evidence the line a value came from), so the
 * Mapper, the evidence match and the checks work unchanged. Every kind's
 * reader runs, so *Read it as* maps that kind's own values. Nothing leaves
 * the server: no request, no usage-log row; OCR's own one-per-user limit
 * applies.
 */
final readonly class RuleReader
{
    /** The confidence an amount no sum checks needs before it is filled (#525). */
    private const float CLEAR = 90.0;

    public function __construct(
        private OcrReader $ocr,
        private Classifier $classifier,
        private FuelReceiptRules $fuel,
        private InvoiceRules $invoice,
        private StationService $stations,
        private VehicleMatcher $vehicles,
    ) {
    }

    /**
     * A photo or scanned PDF: OCR, then rules.
     */
    public function readPicture(User $user, string $path, string $mime, bool $registration = false): Extraction|ScanProblem
    {
        $read = $this->ocr->read($user->id, $path, $mime, $user->preferences->locale);
        if (!$read->ok()) {
            return ScanProblem::fromOcr($read->problem);
        }
        $text = OcrText::of($read, $registration);
        // Only OCR's confidence floor (#525): the 30-word minimum keeps short text from a model, and rules
        // fill only labelled values, a lone amount only when read clearly, so a short receipt is still read.
        if ($text->wordCount === 0 || $text->meanConfidence < OcrText::MIN_MEAN_CONFIDENCE) {
            return ScanProblem::TooLittleText;
        }

        return $this->read($user, $text->rows(), $text, $this->ocr->engine()?->name());
    }

    /**
     * A text PDF: its text (already scrubbed of 11-digit runs), then rules.
     */
    public function readText(User $user, PreparedFile $file, bool $registration = false): Extraction|ScanProblem
    {
        if ($file->problem !== null) {
            return $file->problem;
        }
        $text = (string) $file->text;

        return $this->read($user, explode("\n", $text), $text, null, $registration);
    }

    /**
     * @param list<string> $rows the document's lines, scrubbed
     * @param string|OcrText $text what each value's evidence is checked against
     * @param bool $registration a *Scan the V5C* scan (#511): the V5C rule whatever the text says
     */
    public function read(User $user, array $rows, string|OcrText $text, ?string $engine, bool $registration = false): Extraction
    {
        $locale = $user->preferences->locale;
        $v5c = $registration
            || ($text instanceof OcrText ? $text->looksLikeRegistration() : Scrubber::looksLikeRegistration($text));
        if ($v5c) {
            // A V5C's reference never reaches a rule, whatever the gaps between its groups (merge review of 53.4).
            $rows = array_map(Scrubber::withoutReferenceWords(...), $rows);
        }
        $rows = array_values(array_filter(array_map('trim', $rows), static fn (string $r): bool => $r !== ''));
        $kind = $this->classifier->kind($rows, $locale, $this->fuel);

        $plates = array_values(array_filter(array_map(
            static fn (Vehicle $v): ?string => $v->data->registration,
            $this->vehicles->candidates($user),
        )));
        $dated = self::dateOnly($rows, $locale);
        // #525: an amount nothing checks is filled only when OCR read it clearly; a PDF's text always is.
        $clear = static fn (string $amount): bool => !$text instanceof OcrText
            || ($text->find($amount) ?? 0.0) >= self::CLEAR;
        $invoice = $this->invoice->read($rows, $locale, $plates, $clear);
        $registration = InvoiceRules::registration($rows, $plates);
        $knownStation = fn (string $name): bool => $this->stations->enabled()
            && $this->stations->existing(StationName::tidy($name)) !== null;

        $readings = [];
        foreach (ScanKind::cases() as $each) {
            $object = match ($each) {
                ScanKind::FuelReceipt => ['fields' => $this->fuel->read($rows, $locale, $knownStation, $clear)],
                ScanKind::ServiceInvoice => $invoice,
                ScanKind::Registration => ['fields' => $registration === null ? [] : ['registration' => $registration]],
                // An insurance document's first date is when its cover starts.
                ScanKind::Insurance => ['fields' => isset($dated['date']) ? ['start' => $dated['date']] : []],
                default => ['fields' => $dated],
            };
            if ($v5c) {
                // A V5C's reference is scrubbed from every kind's reading, not only the registration one
                // (spec.md §7.27: never shown or stored; merge review of 53.4).
                /** @var array<string, mixed> $object */
                $object = Scrubber::registrationValue($object);
            }
            $readings[$each->value] = Extraction::fromObject(['kind' => $each->value] + $object, $text);
        }

        return $readings[$kind->value]
            ->withAlternatives($readings)
            ->withReadBy(ReadBy::rules($engine));
    }

    /**
     * Other kinds: the first date, with the rest left empty.
     *
     * @param list<string> $rows
     * @return array<string, array{value: string, evidence: string}>
     */
    private static function dateOnly(array $rows, string $locale): array
    {
        foreach ($rows as $row) {
            $date = RuleText::date($row, $locale);
            if ($date !== null) {
                return ['date' => ['value' => $date, 'evidence' => RuleText::evidence($row)]];
            }
        }

        return [];
    }
}
