<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\User;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Change-password form: current password, new password twice. A user
 * without a password (single sign-on only, Phase 23.1) sets one without a
 * current password.
 */
final class PasswordChangeForm
{
    /**
     * @param array<array-key, mixed> $input
     * @return string|ValidationErrors the new password when valid
     */
    public static function parse(array $input, string $locale, User $user, AuthService $auth): string|ValidationErrors
    {
        $validator = new Validator($input, $locale);

        $current = $input['current_password'] ?? '';
        if (!$user->hasPassword()) {
            // Nothing to confirm: the session (from single sign-on) is the proof.
        } elseif (!is_string($current) || $current === '') {
            $validator->addError('current_password', 'validation.required');
        } elseif (!$auth->verifyPassword($user, $current)) {
            $validator->addError('current_password', 'auth.current_password_wrong');
        }

        $new = $validator->password('new_password');
        if ($new !== null && ($input['new_password_confirm'] ?? null) !== $new) {
            $validator->addError('new_password_confirm', 'auth.password_mismatch');
        }

        return $validator->errors()->isEmpty() && $new !== null ? $new : $validator->errors();
    }
}
