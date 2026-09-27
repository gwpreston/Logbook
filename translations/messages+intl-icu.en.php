<?php

declare(strict_types=1);

/*
 * English message catalogue (default + fallback locale). ICU MessageFormat.
 *
 * To add a language, copy this file to `messages+intl-icu.<locale>.php`
 * (e.g. `messages+intl-icu.de.php`) and translate the values; the locale is
 * picked up automatically. Keys are nested here and flattened with dots.
 */
return [
    'app' => [
        'name' => 'Logbook',
        'tagline' => 'Your garage, on your own server.',
    ],
    'nav' => [
        'label' => 'Main navigation',
        'home' => 'Home',
        'skip_to_content' => 'Skip to main content',
        'open_menu' => 'Menu',
    ],
    'home' => [
        'title' => 'Welcome to Logbook',
        'intro' => 'Keep every vehicle’s mileage, fuel, maintenance and renewals in one place — stored on your own server.',
        'vehicle_count' => '{count, plural, =0 {No vehicles yet} one {# vehicle} other {# vehicles}}',
        'coming_soon' => 'Vehicle tracking arrives in the next release. This installation is ready for it.',
        'status_heading' => 'Installation status',
        'status_health' => 'Health check',
        'status_health_hint' => 'Machine-readable status for monitoring (JSON).',
        'status_deep_link' => 'Deep-link check',
        'status_deep_link_hint' => 'Open it, then refresh the page: it should load again, including behind a reverse proxy.',
    ],
    'diagnostics' => [
        'deep_link' => [
            'title' => 'Deep link works',
            'body' => 'This page lives at a nested address. If a hard refresh brings you back here, your reverse proxy and base path are set up correctly.',
            'back' => 'Back to the start page',
        ],
    ],
    'footer' => [
        'self_hosted' => 'Self-hosted. Your data stays on your server.',
    ],
    'error' => [
        'back_home' => 'Go to the start page',
        'details' => 'Error details (shown because debug mode is on)',
        'status_code' => 'Error {status}',
        '400' => ['title' => 'Bad request', 'body' => 'The request could not be understood. Please check it and try again.'],
        '403' => ['title' => 'Access denied', 'body' => 'You do not have permission to view this page.'],
        '404' => ['title' => 'Page not found', 'body' => 'The page you asked for does not exist or has moved.'],
        '405' => ['title' => 'Method not allowed', 'body' => 'This page cannot be used that way.'],
        '500' => ['title' => 'Something went wrong', 'body' => 'An unexpected error occurred. It has been logged; please try again.'],
        '503' => ['title' => 'Temporarily unavailable', 'body' => 'The service is temporarily unavailable. Please try again shortly.'],
        '4xx' => ['title' => 'Request problem', 'body' => 'The request could not be completed.'],
        '5xx' => ['title' => 'Server error', 'body' => 'An unexpected error occurred. It has been logged; please try again.'],
    ],
];
