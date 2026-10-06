<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Slim\Exception\HttpForbiddenException;

/**
 * A demo visitor asked for something the demo does not allow (spec.md
 * §7.36). Rendered as the friendly *Not available in the demo* page (403),
 * never a bare error.
 */
final class DemoBlockedException extends HttpForbiddenException
{
    /** @var string */
    protected $message = 'This is not available in the demo.';
}
