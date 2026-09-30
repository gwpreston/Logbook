<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Api\ApiScope;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The *Create key* form (spec.md §7.20): a name and a scope.
 */
final class ApiKeyForm
{
    /**
     * @param array<array-key, mixed> $input
     * @return array{name: string, scope: ApiScope}|ValidationErrors
     */
    public static function parse(array $input, string $locale): array|ValidationErrors
    {
        $validator = new Validator($input, $locale);
        $name = $validator->string('name', true, ApiKeyService::NAME_MAX_LENGTH);
        $scope = $validator->enum('scope', ApiScope::class, true);

        if (!$validator->errors()->isEmpty() || $name === null || !$scope instanceof ApiScope) {
            return $validator->errors();
        }

        return ['name' => $name, 'scope' => $scope];
    }
}
