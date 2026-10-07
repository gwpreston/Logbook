<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

/**
 * Which routes a demo visitor may not use (spec.md §7.36). Every other
 * route is allowed; RouteInventoryTest makes each route choose, so a route
 * added later cannot be forgotten.
 */
final class DemoRoutes
{
    /**
     * Blocked: users, invitations, sign-in providers, header sign-in, API keys and MCP, AI,
     * fuel price providers, the email server, backup and restore, importing, jobs, the update check, and changing
     * the password, email address or avatar. They answer the *Not available in the demo* page.
     */
    public const array BLOCKED = [
        // Users, invitations and the links that sign someone in.
        'settings.users', 'settings.users.change', 'settings.users.confirm', 'settings.users.add',
        'settings.users.delete', 'settings.users.transfer', 'settings.users.revoke', 'settings.users.identity.remove',
        'invite.accept', 'login.link', 'welcome', 'password.forgot', 'email.confirm',
        // Sign-in providers: single sign-on and header sign-in.
        'oidc.start', 'oidc.callback', 'proxy.link', 'settings.sso.link', 'settings.sso.unlink',
        // One's own credentials.
        'settings.password', 'settings.email', 'settings.email.action', 'settings.avatar', 'settings.avatar.action',
        // API keys and MCP.
        'settings.api_keys', 'settings.api_keys.revoke', 'mcp', 'drafts.action',
        // AI connections and every AI feature.
        'settings.ai', 'settings.ai.tasks', 'settings.ai.this_host', 'settings.ai.connections.create',
        'settings.ai.connections.show', 'settings.ai.connections.edit', 'settings.ai.connections.delete',
        'settings.ai.connections.acknowledge', 'settings.ai.connections.models', 'settings.ai.connections.test',
        'settings.ai_use', 'insights.ask', 'insights.questions.progress', 'insights.questions.retention',
        'insights.questions.delete', 'insights.question', 'insights.question.delete', 'insights.questions.feedback',
        'insights.questions.draft', 'insights.refresh',
        'scan', 'scan.result', 'scan.file', 'scan.reminders', 'scan.vehicle',
        // Fuel price providers (the demo's sample prices need no setting).
        'settings.fuel_prices',
        // The email server (Phase 36.1).
        'settings.delivery', 'settings.delivery.remove',
        // Backup, restore, importing, and everything-exports.
        'backup.index', 'backup.download', 'backup.restore', 'backup.restore.confirm', 'backup.file',
        'import.upload', 'import.map', 'import_app.upload', 'import_app.map',
        // Jobs and the update check.
        'settings.jobs', 'settings.jobs.triggers', 'settings.jobs.url_token', 'settings.jobs.backup',
        'settings.jobs.run', 'settings.jobs.run.status', 'settings.jobs.run_now', 'settings.jobs.started',
        'settings.updates',
        // Sending something out: a test notification and a calendar feed.
        'settings.reminders.test', 'settings.reminders.calendar',
        // One's own notification channels (Phase 36.2): saving, testing, switching and removing;
        // Telegram's *Find my chat* (Phase 36.3).
        'settings.notifications.channel', 'settings.notifications.switch', 'settings.notifications.remove',
        'settings.notifications.find_chat', 'settings.notifications.quiet',
    ];

    public static function isBlocked(?string $routeName): bool
    {
        return self::isApi($routeName) || ($routeName !== null && in_array($routeName, self::BLOCKED, true));
    }

    /**
     * The REST API's routes (all of them but `api.openapi`, its description, which needs no
     * key): blocked too, and answered with a JSON problem rather than a page.
     */
    public static function isApi(?string $routeName): bool
    {
        return $routeName !== null && str_starts_with($routeName, 'api.') && $routeName !== 'api.openapi';
    }
}
