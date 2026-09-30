<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Api\ApiKey;
use Logbook\Domain\Api\ApiScope;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * API keys (`api_keys`, spec.md §6 ApiKey). Looked up by the token's keyed
 * hash; listed, touched and revoked per user.
 */
final readonly class ApiKeyRepository
{
    private const string TABLE = 'api_keys';

    public function __construct(private Connection $connection)
    {
    }

    public function insert(int $userId, string $name, ApiScope $scope, string $tokenHash, DateTimeImmutable $now): int
    {
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $tokenHash,
            'scope' => $scope->value,
            'created_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['user_id' => ParameterType::INTEGER]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * The key with this token hash, revoked or not, with the hash as stored
     * (for a constant-time comparison).
     *
     * @return array{key: ApiKey, hash: string}|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $row = $this->select()
            ->addSelect('token_hash')
            ->where('token_hash = :hash')
            ->setParameter('hash', $tokenHash)
            ->fetchAssociative();

        return $row === false ? null : ['key' => $this->hydrate($row), 'hash' => Row::string($row, 'token_hash')];
    }

    public function find(int $id): ?ApiKey
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<ApiKey> newest first
     */
    public function listForUser(int $userId): array
    {
        $rows = $this->select()
            ->where('user_id = :user')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @return list<ApiKey> newest first
     */
    public function listAll(): array
    {
        $rows = $this->select()->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function touch(int $id, DateTimeImmutable $now): void
    {
        $this->connection->update(
            self::TABLE,
            ['last_used_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())],
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Revoke a key that is not revoked yet; false when there was none.
     */
    public function revoke(int $id, DateTimeImmutable $now): bool
    {
        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('revoked_at', ':now')
            ->where('id = :id')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() > 0;
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'user_id', 'name', 'scope', 'created_at', 'last_used_at', 'revoked_at')
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ApiKey
    {
        $platform = $this->connection->getDatabasePlatform();
        $scope = Row::string($row, 'scope');

        return new ApiKey(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            name: Row::string($row, 'name'),
            scope: ApiScope::tryFrom($scope) ?? throw new UnexpectedValueException(sprintf('Unknown API scope "%s".', $scope)),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            lastUsedAt: ($row['last_used_at'] ?? null) === null
                ? null
                : UtcDateTime::fromDatabase($row['last_used_at'], $platform),
            revokedAt: ($row['revoked_at'] ?? null) === null ? null : UtcDateTime::fromDatabase($row['revoked_at'], $platform),
        );
    }
}
