<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Slim\Exception\HttpForbiddenException;

/**
 * The user can see the vehicle (or is signed in) but may not do this with
 * it (spec.md §5 *Access policy*). Rendered as a friendly 403 page that says
 * so, rather than "you may not view this page".
 */
final class AccessDeniedException extends HttpForbiddenException
{
    /** @var string */
    protected $message = 'The access policy does not allow this.';
}
