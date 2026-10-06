<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

/**
 * What a demo visitor may not do (spec.md §7.36). Anything that sends data
 * out, accepts a file or accepts a secret from a visitor asks
 * `DemoMode::blocks()` before it does.
 */
enum DemoRestriction: string
{
    /** Users, invitations, sign-in providers, backup and restore, jobs, the update check. */
    case Administration = 'administration';
    /** The password, the email address, the avatar, single sign-on links, API keys. */
    case Credentials = 'credentials';
    /** Mail, notifications, webhooks, the update check, AI and provider requests. */
    case Outbound = 'outbound';
    /** A file from the visitor: nothing a visitor adds survives except text. */
    case Uploads = 'uploads';
    /** AI connections and every AI feature. */
    case Ai = 'ai';
    /** The REST API and MCP. */
    case Api = 'api';
}
