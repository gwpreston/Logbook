<?php

declare(strict_types=1);

namespace Logbook\Support\Security;

use Logbook\Repository\UserRepository;
use Logbook\Support\Config\Env;
use RuntimeException;
use Throwable;

/**
 * A `SESSION_SECRET` for a fresh Docker volume (Phase 36.1, spec.md §9,
 * decided #222): when neither `SESSION_SECRET` nor the file at
 * `SESSION_SECRET_FILE` gives one, and the database has no users yet, 64
 * random hex characters are written to that file, readable only by its
 * owner. An install that already has users is never given one: it would
 * sign everyone out and break their feed links, API keys and invitations.
 */
final readonly class SessionSecretFile
{
    public const string GENERATED = 'generated';
    /** `SESSION_SECRET` is set: the file is not needed. */
    public const string SET = 'set';
    /** No `SESSION_SECRET_FILE` (the bare-PHP default). */
    public const string NO_FILE = 'no_file';
    /** The file is already there; it is never replaced. */
    public const string EXISTS = 'exists';
    /** The database has users and no secret: left alone, and the admin is told. */
    public const string EXISTING_INSTALL = 'existing_install';

    public function __construct(private Env $env, private UserRepository $users)
    {
    }

    /**
     * @return self::* what happened
     */
    public function ensure(): string
    {
        if ($this->env->string('SESSION_SECRET') !== '') {
            return self::SET;
        }
        $file = $this->env->string('SESSION_SECRET_FILE');
        if ($file === '') {
            return self::NO_FILE;
        }
        if (file_exists($file)) {
            return self::EXISTS;
        }
        if ($this->hasUsers()) {
            return self::EXISTING_INSTALL;
        }

        $previous = umask(0077);
        try {
            $written = file_put_contents($file, bin2hex(random_bytes(32)) . "\n", LOCK_EX);
        } finally {
            umask($previous);
        }
        if ($written === false) {
            throw new RuntimeException(sprintf('Could not write %s.', $file));
        }
        chmod($file, 0600);

        return self::GENERATED;
    }

    private function hasUsers(): bool
    {
        try {
            return $this->users->exists();
        } catch (Throwable) {
            // No users table yet (MIGRATE_ON_START=false on a new volume): nothing to break.
            return false;
        }
    }
}
