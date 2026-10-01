<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\User\UserIdentity;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Linked sign-in identities (`user_identities`, spec.md §6 UserIdentity).
 */
final readonly class UserIdentityRepository
{
    private const string TABLE = 'user_identities';

    public function __construct(private Connection $connection)
    {
    }

    public function findBySubject(string $provider, string $issuer, string $subject): ?UserIdentity
    {
        $row = $this->select()
            ->where('provider = :provider', 'issuer = :issuer', 'subject = :subject')
            ->setParameter('provider', $provider)
            ->setParameter('issuer', $issuer)
            ->setParameter('subject', $subject)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function find(int $id): ?UserIdentity
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<UserIdentity> oldest first
     */
    public function forUser(int $userId): array
    {
        $rows = $this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Every identity, by user id (Settings → Users).
     *
     * @return array<int, list<UserIdentity>>
     */
    public function byUser(): array
    {
        $byUser = [];
        foreach ($this->select()->orderBy('id')->fetchAllAssociative() as $row) {
            $identity = $this->hydrate($row);
            $byUser[$identity->userId][] = $identity;
        }

        return $byUser;
    }

    public function hasProvider(int $userId, string $provider): bool
    {
        return $this->select()
            ->where('user_id = :user', 'provider = :provider')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('provider', $provider)
            ->setMaxResults(1)
            ->fetchAssociative() !== false;
    }

    public function insert(int $userId, string $provider, string $issuer, string $subject, DateTimeImmutable $now): UserIdentity
    {
        $timestamp = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'provider' => $provider,
            'issuer' => $issuer,
            'subject' => $subject,
            'last_login_at' => $timestamp,
            'created_at' => $timestamp,
        ], ['user_id' => ParameterType::INTEGER]);

        $identity = $this->find((int) $this->connection->lastInsertId());
        assert($identity instanceof UserIdentity);

        return $identity;
    }

    public function touch(int $id, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'last_login_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'user_id', 'provider', 'issuer', 'subject', 'last_login_at', 'created_at')
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UserIdentity
    {
        $platform = $this->connection->getDatabasePlatform();

        return new UserIdentity(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            provider: Row::string($row, 'provider'),
            issuer: Row::string($row, 'issuer'),
            subject: Row::string($row, 'subject'),
            lastLoginAt: ($row['last_login_at'] ?? null) === null
                ? null
                : UtcDateTime::fromDatabase($row['last_login_at'], $platform),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
        );
    }
}
