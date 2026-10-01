<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use Logbook\Support\Validation\ValidationErrors;
use RuntimeException;

/**
 * A draft the form refuses, with the form's errors (spec.md §7.26).
 */
final class DraftInvalid extends RuntimeException
{
    public function __construct(public readonly ValidationErrors $errors)
    {
        parent::__construct('The draft is not valid.');
    }

    /**
     * Whether every error is a missing required field (`needs`), not a
     * wrong value (`invalid`).
     */
    public function onlyMissing(): bool
    {
        foreach ($this->errors->all() as $error) {
            if ($error['key'] !== 'validation.required') {
                return false;
            }
        }

        return true;
    }
}
