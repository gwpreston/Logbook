<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit compliance document form ↔ ComplianceDocumentData. The same
 * parse() serves create and edit, so an edit can never be stricter than a
 * create. Dates are calendar dates; the cost is optional (blank = 0) and 0
 * is valid.
 */
final class ComplianceDocumentForm
{
    private const int TITLE_MAX = 150;
    private const int TEXT_MAX = 100;
    private const int NOTES_MAX = 1000;
    private const int MONEY_WHOLE_DIGITS = 11;
    private const int MONEY_SCALE = 3;

    /**
     * @return array<string, string>
     */
    public static function values(ComplianceDocument $document): array
    {
        $data = $document->data;

        return [
            'type' => $data->type->value,
            'title' => $data->title ?? '',
            'provider' => $data->provider ?? '',
            'reference' => $data->reference ?? '',
            'start_on' => $data->startOn?->format('Y-m-d') ?? '',
            'expiry_on' => $data->expiryOn?->format('Y-m-d') ?? '',
            'cost' => Decimal::trim($data->cost),
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(?ComplianceType $type = null): array
    {
        return ['type' => ($type ?? ComplianceType::Insurance)->value];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): ComplianceDocumentData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);

        $type = $validator->enum('type', ComplianceType::class, true);
        $title = $validator->string('title', false, self::TITLE_MAX);
        $provider = $validator->string('provider', false, self::TEXT_MAX);
        $reference = $validator->string('reference', false, self::TEXT_MAX);
        $startOn = $validator->date('start_on');
        $expiryOn = $validator->date('expiry_on');
        $cost = $validator->decimal('cost', false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $notes = $validator->string('notes', false, self::NOTES_MAX);

        if ($type === ComplianceType::Other && $title === null && !$validator->errors()->has('title')) {
            $validator->addError('title', 'compliance.title_required');
        }
        if ($startOn !== null && $expiryOn !== null && $expiryOn < $startOn) {
            $validator->addError('expiry_on', 'compliance.expiry_before_start');
        }

        if (!$validator->errors()->isEmpty() || $type === null) {
            return $validator->errors();
        }

        return new ComplianceDocumentData(
            type: $type,
            title: $title,
            provider: $provider,
            reference: $reference,
            startOn: $startOn,
            expiryOn: $expiryOn,
            cost: $cost ?? Decimal::round('0', self::MONEY_SCALE),
            notes: $notes,
        );
    }
}
