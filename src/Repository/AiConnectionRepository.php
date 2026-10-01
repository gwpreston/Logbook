<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\Location;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;
use UnexpectedValueException;

/**
 * Connections to model providers (`ai_connections`, spec.md §6
 * AiConnection). Secrets are in AiSecretRepository.
 */
final readonly class AiConnectionRepository
{
    private const string TABLE = 'ai_connections';

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?AiConnection
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<AiConnection> by name
     */
    public function listAll(): array
    {
        $rows = $this->select()->orderBy('name')->addOrderBy('id')->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function exists(): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('1')
            ->from(self::TABLE)
            ->setMaxResults(1)
            ->fetchOne() !== false;
    }

    public function insert(ConnectionSettings $settings, DateTimeImmutable $now): int
    {
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->insert(
            self::TABLE,
            $this->columns($settings) + ['created_at' => $at, 'updated_at' => $at],
            $this->types(),
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Save the settings. A changed URL clears the acknowledgement.
     */
    public function update(int $id, ConnectionSettings $settings, DateTimeImmutable $now): void
    {
        $columns = $this->columns($settings)
            + ['updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform())];
        $current = $this->find($id);
        if ($current !== null && $current->baseUrl !== $settings->baseUrl) {
            $columns += ['acknowledged_by' => null, 'acknowledged_at' => null, 'acknowledged_url' => null];
        }

        $this->connection->update(self::TABLE, $columns, ['id' => $id], $this->types() + ['id' => ParameterType::INTEGER]);
    }

    public function acknowledge(int $id, int $userId, string $url, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'acknowledged_by' => $userId,
            'acknowledged_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
            'acknowledged_url' => $url,
        ], ['id' => $id], ['acknowledged_by' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]);
    }

    public function clearAcknowledgement(int $id): void
    {
        $this->connection->update(
            self::TABLE,
            ['acknowledged_by' => null, 'acknowledged_at' => null, 'acknowledged_url' => null],
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Record the class found by a test or a call.
     */
    public function setLocation(int $id, Location $location): void
    {
        $this->connection->update(
            self::TABLE,
            ['location' => $location->value],
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Delete the connection; its models, secrets and task assignments go
     * with it, usage rows keep their counts.
     */
    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(ConnectionSettings $settings): array
    {
        return [
            'name' => $settings->name,
            'adapter' => $settings->adapter->value,
            'base_url' => $settings->baseUrl,
            'location' => $settings->location->value,
            'header_names' => $settings->headerNames === [] ? null : json_encode($settings->headerNames, JSON_THROW_ON_ERROR),
            'timeout_seconds' => $settings->timeoutSeconds,
            'verify_tls' => $settings->verifyTls,
            'ca_bundle' => $settings->caBundle,
            'max_request_mb' => $settings->maxRequestMb,
            'monthly_token_cap' => $settings->monthlyTokenCap,
            'enabled' => $settings->enabled,
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private function types(): array
    {
        return [
            'timeout_seconds' => ParameterType::INTEGER,
            'verify_tls' => ParameterType::BOOLEAN,
            'max_request_mb' => ParameterType::INTEGER,
            'monthly_token_cap' => ParameterType::INTEGER,
            'enabled' => ParameterType::BOOLEAN,
        ];
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'name',
                'adapter',
                'base_url',
                'location',
                'header_names',
                'timeout_seconds',
                'verify_tls',
                'ca_bundle',
                'max_request_mb',
                'monthly_token_cap',
                'enabled',
                'acknowledged_by',
                'acknowledged_at',
                'acknowledged_url',
                'created_at',
                'updated_at',
            )
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AiConnection
    {
        $platform = $this->connection->getDatabasePlatform();
        $adapter = Row::string($row, 'adapter');
        $location = Row::string($row, 'location');
        $headers = Row::nullableString($row, 'header_names');
        $names = $headers === null ? [] : json_decode($headers, true);

        return new AiConnection(
            id: Row::int($row, 'id'),
            name: Row::string($row, 'name'),
            adapter: AdapterType::tryFrom($adapter)
                ?? throw new UnexpectedValueException(sprintf('Unknown AI adapter "%s".', $adapter)),
            baseUrl: Row::string($row, 'base_url'),
            location: Location::tryFrom($location)
                ?? throw new UnexpectedValueException(sprintf('Unknown AI location "%s".', $location)),
            headerNames: is_array($names) ? array_values(array_filter($names, is_string(...))) : [],
            timeoutSeconds: Row::int($row, 'timeout_seconds'),
            verifyTls: Row::bool($row, 'verify_tls'),
            caBundle: Row::nullableString($row, 'ca_bundle'),
            maxRequestMb: Row::int($row, 'max_request_mb'),
            monthlyTokenCap: Row::nullableInt($row, 'monthly_token_cap'),
            enabled: Row::bool($row, 'enabled'),
            acknowledgedBy: Row::nullableInt($row, 'acknowledged_by'),
            acknowledgedAt: ($row['acknowledged_at'] ?? null) === null
                ? null
                : UtcDateTime::fromDatabase($row['acknowledged_at'], $platform),
            acknowledgedUrl: Row::nullableString($row, 'acknowledged_url'),
            createdAt: UtcDateTime::fromDatabase($row['created_at'] ?? null, $platform),
            updatedAt: UtcDateTime::fromDatabase($row['updated_at'] ?? null, $platform),
        );
    }
}
