<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Logbook\Domain\Setting\Setting;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use Psr\Clock\ClockInterface;

/**
 * Key/value settings store (`settings` table).
 */
final readonly class SettingRepository
{
    private const string TABLE = 'settings';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $name, SettingScope $scope = SettingScope::Global, int $ownerId = 0): ?Setting
    {
        $row = $this->connection->createQueryBuilder()
            ->select('name', 'scope', 'owner_id', 'value', 'created_at', 'updated_at')
            ->from(self::TABLE)
            ->where('scope = :scope', 'owner_id = :owner', 'name = :name')
            ->setParameter('scope', $scope->value)
            ->setParameter('owner', $ownerId, ParameterType::INTEGER)
            ->setParameter('name', $name)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Insert or replace a setting's value.
     */
    public function save(string $name, mixed $value, SettingScope $scope = SettingScope::Global, int $ownerId = 0): Setting
    {
        $platform = $this->connection->getDatabasePlatform();
        $now = UtcDateTime::toDatabase($this->clock->now(), $platform);
        $json = Type::getType(Types::JSON)->convertToDatabaseValue($value, $platform);

        $this->connection->transactional(function (Connection $connection) use ($name, $scope, $ownerId, $json, $now): void {
            // Check existence explicitly: MySQL's UPDATE row count reports
            // *changed* rows, so "0 updated" does not mean "row missing".
            $exists = $connection->createQueryBuilder()
                ->select('1')
                ->from(self::TABLE)
                ->where('scope = :scope', 'owner_id = :owner', 'name = :name')
                ->setParameter('scope', $scope->value)
                ->setParameter('owner', $ownerId, ParameterType::INTEGER)
                ->setParameter('name', $name)
                ->fetchOne() !== false;

            if ($exists) {
                $connection->createQueryBuilder()
                    ->update(self::TABLE)
                    ->set('value', ':value')
                    ->set('updated_at', ':now')
                    ->where('scope = :scope', 'owner_id = :owner', 'name = :name')
                    ->setParameter('value', $json)
                    ->setParameter('now', $now)
                    ->setParameter('scope', $scope->value)
                    ->setParameter('owner', $ownerId, ParameterType::INTEGER)
                    ->setParameter('name', $name)
                    ->executeStatement();

                return;
            }

            $connection->insert(self::TABLE, [
                'scope' => $scope->value,
                'owner_id' => $ownerId,
                'name' => $name,
                'value' => $json,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['owner_id' => ParameterType::INTEGER]);
        });

        $saved = $this->find($name, $scope, $ownerId);
        assert($saved instanceof Setting);

        return $saved;
    }

    /**
     * Every setting of one user (their account is being deleted).
     */
    public function deleteAllOf(int $userId): void
    {
        $this->connection->delete(self::TABLE, ['scope' => SettingScope::User->value, 'owner_id' => $userId], [
            'owner_id' => ParameterType::INTEGER,
        ]);
    }

    public function delete(string $name, SettingScope $scope = SettingScope::Global, int $ownerId = 0): void
    {
        $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('scope = :scope', 'owner_id = :owner', 'name = :name')
            ->setParameter('scope', $scope->value)
            ->setParameter('owner', $ownerId, ParameterType::INTEGER)
            ->setParameter('name', $name)
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Setting
    {
        $platform = $this->connection->getDatabasePlatform();

        return new Setting(
            name: Row::string($row, 'name'),
            scope: SettingScope::from(Row::string($row, 'scope')),
            ownerId: Row::int($row, 'owner_id'),
            value: Type::getType(Types::JSON)->convertToPHPValue($row['value'], $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'], $platform),
        );
    }
}
