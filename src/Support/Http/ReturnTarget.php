<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Support\Security\SafeRedirect;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a form should return to after saving (spec.md §5): the `return`
 * parameter an edit link from a History page carries, kept by the form in a
 * hidden field. Checked like the sign-in redirect: a local path under the
 * base path, or nothing.
 */
final class ReturnTarget
{
    public const string FIELD = 'return';

    public static function of(ServerRequestInterface $request, string $basePath): ?string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[self::FIELD] ?? null) : null;
        $value ??= $request->getQueryParams()[self::FIELD] ?? null;

        return SafeRedirect::localPath(is_string($value) ? $value : null, $basePath);
    }
}
