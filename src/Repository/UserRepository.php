<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\User\User;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Logbook\Support\Display\Accent;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Display\Theme;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * Accounts (`users` table).
 */
final readonly class UserRepository
{
    private const string TABLE = 'users';
    private const array COLUMNS = [
        'id', 'username', 'password_hash', 'display_name', 'locale', 'timezone', 'distance_unit',
        'volume_unit', 'consumption_unit', 'depth_unit', 'currency', 'theme', 'accent', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function exists(): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('id')
            ->from(self::TABLE)
            ->setMaxResults(1)
            ->fetchOne() !== false;
    }

    public function find(int $id): ?User
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every account, oldest first (the scheduled task works through them).
     *
     * @return list<User>
     */
    public function listAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @param string $username already normalised (lower-case)
     */
    public function findByUsername(string $username): ?User
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where('username = :username')
            ->setParameter('username', $username)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(
        string $username,
        string $passwordHash,
        string $displayName,
        DisplayPreferences $preferences,
        DateTimeImmutable $now,
    ): User {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->insert(self::TABLE, [
            'username' => $username,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ] + self::preferenceColumns($preferences));

        $user = $this->find((int) $this->connection->lastInsertId());
        assert($user instanceof User);

        return $user;
    }

    public function updateProfile(int $id, string $displayName, DisplayPreferences $preferences, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'display_name' => $displayName,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ] + self::preferenceColumns($preferences), ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    public function updateTheme(int $id, Theme $theme, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'theme' => $theme->value,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    public function updatePasswordHash(int $id, string $passwordHash, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'password_hash' => $passwordHash,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * @return array<string, string>
     */
    private static function preferenceColumns(DisplayPreferences $preferences): array
    {
        return [
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'distance_unit' => $preferences->distanceUnit->value,
            'volume_unit' => $preferences->volumeUnit->value,
            'consumption_unit' => $preferences->consumptionUnit->value,
            'depth_unit' => $preferences->depthUnit->value,
            'currency' => $preferences->currency,
            'theme' => $preferences->theme->value,
            'accent' => $preferences->accent->value,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $platform = $this->connection->getDatabasePlatform();

        return new User(
            id: Row::int($row, 'id'),
            username: Row::string($row, 'username'),
            passwordHash: Row::string($row, 'password_hash'),
            displayName: Row::string($row, 'display_name'),
            preferences: new DisplayPreferences(
                locale: Row::string($row, 'locale'),
                timezone: Row::string($row, 'timezone'),
                distanceUnit: DistanceUnit::from(Row::string($row, 'distance_unit')),
                volumeUnit: VolumeUnit::from(Row::string($row, 'volume_unit')),
                consumptionUnit: ConsumptionUnit::from(Row::string($row, 'consumption_unit')),
                currency: Row::string($row, 'currency'),
                theme: Theme::tryFrom(Row::string($row, 'theme')) ?? Theme::System,
                accent: Accent::tryFrom(Row::nullableString($row, 'accent') ?? '') ?? Accent::DEFAULT,
                depthUnit: DepthUnit::tryFrom(Row::nullableString($row, 'depth_unit') ?? '') ?? DepthUnit::Millimetre,
            ),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'], $platform),
        );
    }
}
