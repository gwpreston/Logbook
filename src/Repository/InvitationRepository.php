<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\User\Invitation;
use Logbook\Domain\User\InvitationKind;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * One-time invitation and password-reset links (`invitations`, spec.md §6
 * Invitation). Looked up by the token's keyed hash.
 */
final readonly class InvitationRepository
{
    private const string TABLE = 'invitations';

    public function __construct(private Connection $connection)
    {
    }

    public function insert(
        string $tokenHash,
        InvitationKind $kind,
        int $createdBy,
        ?int $userId,
        string $username,
        string $displayName,
        bool $isAdmin,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?string $email = null,
    ): int {
        $platform = $this->connection->getDatabasePlatform();
        $this->connection->insert(self::TABLE, [
            'token_hash' => $tokenHash,
            'kind' => $kind->value,
            'created_by' => $createdBy,
            'user_id' => $userId,
            'username' => $username,
            'display_name' => $displayName,
            'is_admin' => $isAdmin,
            'expires_at' => UtcDateTime::toDatabase($expiresAt, $platform),
            'created_at' => UtcDateTime::toDatabase($now, $platform),
            'email' => $email,
        ], ['created_by' => ParameterType::INTEGER, 'is_admin' => ParameterType::BOOLEAN]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * The link with this token hash, open or not, with the hash as stored
     * (for a constant-time comparison).
     *
     * @return array{invitation: Invitation, hash: string}|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $row = $this->select()
            ->addSelect('token_hash')
            ->where('token_hash = :hash')
            ->setParameter('hash', $tokenHash)
            ->fetchAssociative();

        return $row === false ? null : ['invitation' => $this->hydrate($row), 'hash' => Row::string($row, 'token_hash')];
    }

    public function find(int $id): ?Invitation
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The links Settings → Users lists: invites, resets and sign-in links,
     * not email confirmations (they are the user's own).
     *
     * @return list<Invitation> newest first
     */
    public function listOpen(DateTimeImmutable $now): array
    {
        $rows = $this->select()
            ->where('used_at IS NULL', 'revoked_at IS NULL', 'expires_at > :now', 'kind <> :email')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('email', InvitationKind::Email->value)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Whether an open invite holds this username (already normalised).
     */
    public function isUsernameReserved(string $username, DateTimeImmutable $now): bool
    {
        return $this->select()
            ->where('username = :username', 'kind = :invite', 'used_at IS NULL', 'revoked_at IS NULL', 'expires_at > :now')
            ->setParameter('username', $username)
            ->setParameter('invite', InvitationKind::Invite->value)
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setMaxResults(1)
            ->fetchAssociative() !== false;
    }

    /**
     * Mark the link used, if it still is open: only one request can win.
     */
    public function markUsed(int $id, DateTimeImmutable $now): bool
    {
        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('used_at', ':now')
            ->where('id = :id', 'used_at IS NULL', 'revoked_at IS NULL')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    /**
     * Retention (the `cleanup` job, spec.md §7.30, #109): links that closed
     * (used, revoked or expired) before $cutoff. Open links are never
     * deleted.
     *
     * @return int links deleted
     */
    public function deleteClosedBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('(used_at IS NOT NULL AND used_at < :cutoff)'
                . ' OR (used_at IS NULL AND revoked_at IS NOT NULL AND revoked_at < :cutoff)'
                . ' OR (used_at IS NULL AND revoked_at IS NULL AND expires_at < :cutoff)')
            ->setParameter('cutoff', UtcDateTime::toDatabase($cutoff, $this->connection->getDatabasePlatform()))
            ->executeStatement();
    }

    public function revoke(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('revoked_at', ':now')
            ->where('id = :id', 'used_at IS NULL', 'revoked_at IS NULL')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * The user's open link of this kind, newest first (an email change still
     * waiting for confirmation).
     */
    public function findOpenFor(int $userId, InvitationKind $kind, DateTimeImmutable $now): ?Invitation
    {
        $row = $this->select()
            ->where('user_id = :user', 'kind = :kind', 'used_at IS NULL', 'revoked_at IS NULL', 'expires_at > :now')
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('kind', $kind->value)
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Revoke every open link of this kind for a user.
     */
    public function revokeOpenFor(int $userId, InvitationKind $kind, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('revoked_at', ':now')
            ->where('user_id = :user', 'kind = :kind', 'used_at IS NULL', 'revoked_at IS NULL')
            ->setParameter('now', UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()))
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->setParameter('kind', $kind->value)
            ->executeStatement();
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'kind', 'created_by', 'user_id', 'username', 'display_name', 'is_admin')
            ->addSelect('expires_at', 'used_at', 'revoked_at', 'created_at', 'email')
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Invitation
    {
        $platform = $this->connection->getDatabasePlatform();
        $instant = static fn (string $column): ?DateTimeImmutable => ($row[$column] ?? null) === null
            ? null
            : UtcDateTime::fromDatabase($row[$column], $platform);

        return new Invitation(
            id: Row::int($row, 'id'),
            kind: InvitationKind::from(Row::string($row, 'kind')),
            createdBy: Row::int($row, 'created_by'),
            userId: Row::nullableInt($row, 'user_id'),
            username: Row::string($row, 'username'),
            displayName: Row::string($row, 'display_name'),
            isAdmin: Row::bool($row, 'is_admin'),
            expiresAt: UtcDateTime::fromDatabase($row['expires_at'] ?? null, $platform),
            usedAt: $instant('used_at'),
            revokedAt: $instant('revoked_at'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            email: Row::nullableString($row, 'email'),
        );
    }
}
