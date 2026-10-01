<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Support\Validation\ValidationErrors;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validation errors as a 422 problem (spec.md §7.20): each field gets the
 * form's message key and its English text, so a client can match on the
 * key and show the text.
 */
final readonly class ValidationProblem
{
    private const string LOCALE = 'en';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(ValidationErrors $errors): ApiProblem
    {
        $fields = [];
        foreach ($errors->all() as $field => $error) {
            $fields[$field] = [
                'key' => $error['key'],
                'message' => $this->translator->trans($error['key'], $error['params'], null, self::LOCALE),
            ];
        }

        return new ApiProblem(
            422,
            'validation_failed',
            'Some fields are missing or invalid; see "errors".',
            $fields,
            [],
            $errors,
        );
    }
}
