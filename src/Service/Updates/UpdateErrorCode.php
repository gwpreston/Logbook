<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

/**
 * Why an update check has no answer (spec.md §7.31). Each is shown in the
 * reader's language from `updates.error.<value>`.
 */
enum UpdateErrorCode: string
{
    /** `404`: the repository has no published release. */
    case NoReleases = 'no_releases';
    /** `403` or `429`; param `until`. */
    case RateLimited = 'rate_limited';
    /** Any other status; param `status`. */
    case HttpStatus = 'http_status';
    case Timeout = 'timeout';
    /** A network or TLS error; param `reason`. */
    case Network = 'network';
    /** More than 1 MB. */
    case TooLarge = 'too_large';
    /** A redirect away from api.github.com, or more than two; param `target`. */
    case Redirect = 'redirect';
    /** Not the JSON of a release. */
    case InvalidResponse = 'invalid_response';
    /** `tag_name` isn't a version; param `tag`. */
    case InvalidTag = 'invalid_tag';
    /** `html_url` isn't the repository's release page. */
    case InvalidUrl = 'invalid_url';
    /** `html_url` names another repository; param `repo`. */
    case Moved = 'moved';
}
