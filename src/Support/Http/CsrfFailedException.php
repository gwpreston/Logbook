<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Slim\Exception\HttpBadRequestException;

/**
 * A state-changing request without a valid CSRF token: forged, or a form
 * left open until its session expired. Rendered as a friendly 400 page.
 */
final class CsrfFailedException extends HttpBadRequestException
{
    /** @var string */
    protected $message = 'Invalid or missing CSRF token.';
}
