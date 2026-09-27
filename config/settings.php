<?php

declare(strict_types=1);

use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;

/*
 * Settings are resolved from environment variables (and `.env`). Every
 * variable, its default and its meaning is documented in `.env.example` and
 * spec.md §9 — add new ones there first.
 */
return static fn (Env $env, string $rootDir): AppSettings => AppSettings::fromEnv($env, $rootDir);
