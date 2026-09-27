<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;

enum DatabaseDriver: string
{
    case Pgsql = 'pgsql';
    case Mysql = 'mysql';
    case Sqlite = 'sqlite';

    public static function fromEnv(string $value): self
    {
        $normalised = strtolower(trim($value));

        return match ($normalised) {
            'pgsql', 'postgres', 'postgresql', 'pdo_pgsql' => self::Pgsql,
            'mysql', 'mariadb', 'pdo_mysql' => self::Mysql,
            'sqlite', 'sqlite3', 'pdo_sqlite' => self::Sqlite,
            default => throw new InvalidArgumentException(
                sprintf('Unsupported DB_DRIVER "%s" (expected pgsql, mysql or sqlite).', $value),
            ),
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Pgsql => 5432,
            self::Mysql => 3306,
            self::Sqlite => null,
        };
    }

    /** Doctrine DBAL driver name. */
    public function dbalDriver(): string
    {
        return 'pdo_' . $this->value;
    }

    /** Phinx adapter name. */
    public function phinxAdapter(): string
    {
        return $this->value;
    }
}
