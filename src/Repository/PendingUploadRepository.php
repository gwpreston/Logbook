<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Logbook\Domain\Ai\Scan\ScanStatus;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * Scanned files waiting for their entry (`pending_uploads`, spec.md §6
 * PendingUpload). A row is read only by its own user: another user's
 * token is not found. Saving the entry claims it with a conditional
 * update, so a file is attached once at most.
 */
final readonly class PendingUploadRepository
{
    private const string TABLE = 'pending_uploads';

    public function __construct(private Connection $connection)
    {
    }

    public function insert(
        int $userId,
        string $token,
        string $filename,
        string $mime,
        int $size,
        string $storedPath,
        ?int $vehicleId,
        ?ScanTarget $target,
        DateTimeImmutable $now,
        ?int $incidentId = null,
    ): ScanUpload {
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'token' => $token,
            'filename' => $filename,
            'mime' => $mime,
            'size' => $size,
            'stored_path' => $storedPath,
            'vehicle_id' => $vehicleId,
            'target' => $target?->value,
            'incident_id' => $incidentId,
            'status' => ScanStatus::Reading->value,
            'created_at' => $this->time($now),
            'expires_at' => $this->time($now->modify('+' . ScanUpload::TTL_SECONDS . ' seconds')),
        ], [
            'user_id' => ParameterType::INTEGER,
            'size' => ParameterType::INTEGER,
            'vehicle_id' => ParameterType::INTEGER,
            'incident_id' => $incidentId === null ? ParameterType::NULL : ParameterType::INTEGER,
        ]);

        return $this->find($userId, $token) ?? throw new \LogicException('The pending upload just inserted is missing.');
    }

    public function find(int $userId, string $token): ?ScanUpload
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('token = :token', 'user_id = :user')
            ->setParameter('token', $token)
            ->setParameter('user', $userId, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * What was read (or why not), and the page count of a PDF.
     *
     * @param array<string, mixed> $result
     */
    public function setResult(int $id, ScanStatus $status, array $result, ?int $pageCount): void
    {
        $this->connection->update(self::TABLE, [
            'status' => $status->value,
            'result' => self::json($result),
            'page_count' => $pageCount,
        ], ['id' => $id], ['page_count' => ParameterType::INTEGER]);
    }

    /**
     * Claim a pending row for the entry about to be saved: once only, so a
     * double submit attaches the file once. False when it was claimed
     * already, has lost its file, or has expired.
     */
    public function claim(int $id, DateTimeImmutable $now): bool
    {
        return $this->connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', ':saved')
            ->where('id = :id', 'status <> :saved', 'stored_path IS NOT NULL', 'expires_at > :now')
            ->setParameter('saved', ScanStatus::Saved->value)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->setParameter('now', $this->time($now))
            ->executeStatement() === 1;
    }

    /**
     * The save failed: the file waits for an entry again.
     */
    public function release(int $id, ScanStatus $status): void
    {
        $this->connection->update(self::TABLE, ['status' => $status->value], ['id' => $id]);
    }

    /**
     * The entry is saved: the row keeps only what its card still offers.
     *
     * @param array<string, mixed>|null $recommendations
     */
    public function finish(int $id, ?array $recommendations): void
    {
        $this->connection->update(self::TABLE, [
            'stored_path' => null,
            'recommendations' => $recommendations === null ? null : self::json($recommendations),
        ], ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $recommendations
     */
    public function setRecommendations(int $id, ?array $recommendations): void
    {
        $this->connection->update(self::TABLE, [
            'recommendations' => $recommendations === null ? null : self::json($recommendations),
        ], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * Expired rows, for the scheduled clean-up to delete with their files.
     *
     * @return list<ScanUpload>
     */
    public function expired(DateTimeImmutable $now): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('expires_at <= :now')
            ->setParameter('now', $this->time($now))
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ScanUpload
    {
        $platform = $this->connection->getDatabasePlatform();

        return new ScanUpload(
            id: Row::int($row, 'id'),
            userId: Row::int($row, 'user_id'),
            token: Row::string($row, 'token'),
            filename: Row::string($row, 'filename'),
            mime: Row::string($row, 'mime'),
            size: Row::int($row, 'size'),
            storedPath: Row::nullableString($row, 'stored_path'),
            vehicleId: Row::nullableInt($row, 'vehicle_id'),
            target: ScanTarget::tryFrom((string) Row::nullableString($row, 'target')),
            status: ScanStatus::tryFrom(Row::string($row, 'status')) ?? ScanStatus::Failed,
            pageCount: Row::nullableInt($row, 'page_count'),
            result: self::decode(Row::nullableString($row, 'result')),
            recommendations: self::decode(Row::nullableString($row, 'recommendations')),
            createdAt: UtcDateTime::fromDatabase($row['created_at'], $platform),
            expiresAt: UtcDateTime::fromDatabase($row['expires_at'], $platform),
            incidentId: Row::nullableInt($row, 'incident_id'),
        );
    }

    private function time(DateTimeImmutable $value): string
    {
        return UtcDateTime::toDatabase($value, $this->connection->getDatabasePlatform());
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $json): ?array
    {
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
