<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Slim\Exception\HttpSpecializedException;

/**
 * The request body exceeded PHP's post_max_size, so PHP discarded it
 * (including the CSRF token). Reported as what it is, not as a CSRF failure.
 */
final class PayloadTooLargeException extends HttpSpecializedException
{
    /** @var int */
    protected $code = 413;
    /** @var string */
    protected $message = 'Payload too large.';
    protected string $title = '413 Payload Too Large';
    protected string $description = 'The submitted data was larger than the server accepts.';
}
