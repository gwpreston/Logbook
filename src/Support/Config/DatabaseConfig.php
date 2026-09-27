<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

/**
 * Connection settings shared by the DBAL connection factory and Phinx, so the
 * app and its migrations can never disagree about which database they use.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public DatabaseDriver $driver,
        public string $host,
        public ?int $port,
        /** Database name; for SQLite, the absolute path of the database file. */
        public string $name,
        public string $user,
        public string $password,
    ) {
    }

    /**
     * Read `{prefix}DRIVER`, `{prefix}HOST`, `{prefix}PORT`, `{prefix}NAME`,
     * `{prefix}USER` and `{prefix}PASSWORD`. For SQLite, NAME is a file path;
     * relative paths resolve against the project root.
     */
    public static function fromEnv(
        Env $env,
        string $rootDir,
        string $prefix = 'DB_',
        string $defaultSqliteFile = 'var/logbook.sqlite',
    ): self {
        $driver = DatabaseDriver::fromEnv($env->string($prefix . 'DRIVER', DatabaseDriver::Sqlite->value));

        if ($driver === DatabaseDriver::Sqlite) {
            $path = $env->string($prefix . 'NAME', $defaultSqliteFile);

            return new self($driver, '', null, self::absolutePath($path, $rootDir), '', '');
        }

        return new self(
            $driver,
            $env->string($prefix . 'HOST', '127.0.0.1'),
            $env->int($prefix . 'PORT', $driver->defaultPort() ?? 0),
            $env->string($prefix . 'NAME', 'logbook'),
            $env->string($prefix . 'USER', 'logbook'),
            $env->string($prefix . 'PASSWORD'),
        );
    }

    /**
     * Phinx environment definition for this connection.
     *
     * @return array<string, int|string|null>
     */
    public function toPhinxEnvironment(): array
    {
        return match ($this->driver) {
            DatabaseDriver::Sqlite => [
                'adapter' => $this->driver->phinxAdapter(),
                'name' => $this->name,
                'suffix' => '',
            ],
            DatabaseDriver::Mysql => [
                'adapter' => $this->driver->phinxAdapter(),
                'host' => $this->host,
                'port' => $this->port,
                'name' => $this->name,
                'user' => $this->user,
                'pass' => $this->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            DatabaseDriver::Pgsql => [
                'adapter' => $this->driver->phinxAdapter(),
                'host' => $this->host,
                'port' => $this->port,
                'name' => $this->name,
                'user' => $this->user,
                'pass' => $this->password,
                'charset' => 'utf8',
            ],
        };
    }

    /** Human-readable target for logs and CLI output (never includes the password). */
    public function describe(): string
    {
        return $this->driver === DatabaseDriver::Sqlite
            ? sprintf('sqlite:%s', $this->name)
            : sprintf('%s://%s@%s:%d/%s', $this->driver->value, $this->user, $this->host, $this->port ?? 0, $this->name);
    }

    private static function absolutePath(string $path, string $rootDir): string
    {
        if ($path === ':memory:' || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
            return $path;
        }

        return rtrim($rootDir, '/\\') . '/' . $path;
    }
}
