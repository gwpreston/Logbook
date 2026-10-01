<?php

declare(strict_types=1);

namespace Logbook\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Logbook\Domain\Ai\AiModel;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Domain\Ai\ModelInfo;
use Logbook\Support\Database\Row;
use Logbook\Support\Database\UtcDateTime;

/**
 * The models of each connection (`ai_models`, spec.md §6 AiModel).
 */
final readonly class AiModelRepository
{
    private const string TABLE = 'ai_models';

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?AiModel
    {
        $row = $this->select()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByName(int $connectionId, string $name): ?AiModel
    {
        $row = $this->select()
            ->where('connection_id = :connection', 'name = :name')
            ->setParameter('connection', $connectionId, ParameterType::INTEGER)
            ->setParameter('name', $name)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * A connection's models, added ones first, then by name; optionally
     * only those whose name or label contains $search (case-insensitive).
     *
     * @return list<AiModel>
     */
    public function listForConnection(int $connectionId, string $search = '', ?int $limit = null): array
    {
        $query = $this->select()
            ->where('connection_id = :connection')
            ->setParameter('connection', $connectionId, ParameterType::INTEGER)
            ->orderBy('added', 'DESC')
            ->addOrderBy('name');
        if ($search !== '') {
            $query->andWhere($query->expr()->or(
                'LOWER(name) LIKE :search',
                'LOWER(label) LIKE :search',
            ))->setParameter('search', '%' . addcslashes(mb_strtolower($search), '%_\\') . '%');
        }
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }

        return array_values(array_map($this->hydrate(...), $query->fetchAllAssociative()));
    }

    public function countForConnection(int $connectionId): int
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE)
            ->where('connection_id = :connection')
            ->setParameter('connection', $connectionId, ParameterType::INTEGER)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Every added model, for the task pickers.
     *
     * @return list<AiModel>
     */
    public function listAdded(): array
    {
        $rows = $this->select()->where('added = :added')
            ->setParameter('added', true, ParameterType::BOOLEAN)
            ->orderBy('connection_id')
            ->addOrderBy('name')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Store a fresh model list. New models take the provider's
     * capabilities; listed models not yet added follow the provider's
     * report; added models keep the admin's ticks. Models gone from the
     * list are removed unless added (they may be typed by name).
     *
     * @param list<ModelInfo> $models
     */
    public function syncListed(int $connectionId, array $models, DateTimeImmutable $now): void
    {
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());

        $this->connection->transactional(function (Connection $connection) use ($connectionId, $models, $at): void {
            $existing = [];
            foreach ($this->listForConnection($connectionId) as $model) {
                $existing[$model->name] = $model;
            }
            $seen = [];
            foreach ($models as $info) {
                if (isset($seen[$info->name])) {
                    continue;
                }
                $seen[$info->name] = true;
                $capabilities = [
                    'tools' => in_array(Capability::Tools, $info->capabilities, true),
                    'images' => in_array(Capability::Images, $info->capabilities, true),
                    'json' => in_array(Capability::Json, $info->capabilities, true),
                ];
                $types = [
                    'tools' => ParameterType::BOOLEAN,
                    'images' => ParameterType::BOOLEAN,
                    'json' => ParameterType::BOOLEAN,
                ];
                $model = $existing[$info->name] ?? null;
                if ($model === null) {
                    $connection->insert(self::TABLE, [
                        'connection_id' => $connectionId,
                        'name' => $info->name,
                        'label' => $info->label,
                        'listed' => true,
                        'added' => false,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ] + $capabilities, ['connection_id' => ParameterType::INTEGER, 'listed' => ParameterType::BOOLEAN,
                        'added' => ParameterType::BOOLEAN] + $types);
                    continue;
                }
                $columns = ['label' => $info->label, 'listed' => true, 'updated_at' => $at]
                    + ($model->added ? [] : $capabilities);
                $connection->update(self::TABLE, $columns, ['id' => $model->id], ['listed' => ParameterType::BOOLEAN,
                    'id' => ParameterType::INTEGER] + $types);
            }
            foreach ($existing as $name => $model) {
                if (isset($seen[$name])) {
                    continue;
                }
                if ($model->added) {
                    $connection->update(self::TABLE, ['listed' => false], ['id' => $model->id], [
                        'listed' => ParameterType::BOOLEAN,
                        'id' => ParameterType::INTEGER,
                    ]);
                    continue;
                }
                $connection->delete(self::TABLE, ['id' => $model->id], ['id' => ParameterType::INTEGER]);
            }
        });
    }

    /**
     * Add a model to the connection (it appears in task pickers), typing
     * it in if it isn't listed. Returns its id.
     */
    public function add(int $connectionId, string $name, DateTimeImmutable $now): int
    {
        $model = $this->findByName($connectionId, $name);
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        if ($model !== null) {
            $this->setAdded($model->id, true, $now);

            return $model->id;
        }
        $this->connection->insert(self::TABLE, [
            'connection_id' => $connectionId,
            'name' => $name,
            'listed' => false,
            'added' => true,
            'tools' => false,
            'images' => false,
            'json' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'connection_id' => ParameterType::INTEGER,
            'listed' => ParameterType::BOOLEAN,
            'added' => ParameterType::BOOLEAN,
            'tools' => ParameterType::BOOLEAN,
            'images' => ParameterType::BOOLEAN,
            'json' => ParameterType::BOOLEAN,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Take a model off the connection's task list. A model that is no
     * longer listed is deleted; tasks using it are unassigned either way
     * (by AiTaskRepository::unassignModel or the foreign key).
     */
    public function remove(AiModel $model, DateTimeImmutable $now): void
    {
        if (!$model->listed) {
            $this->connection->delete(self::TABLE, ['id' => $model->id], ['id' => ParameterType::INTEGER]);

            return;
        }
        $this->setAdded($model->id, false, $now);
    }

    public function setAdded(int $id, bool $added, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'added' => $added,
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], ['added' => ParameterType::BOOLEAN, 'id' => ParameterType::INTEGER]);
    }

    /**
     * @param list<Capability> $capabilities the ticked ones
     */
    public function setCapabilities(int $id, array $capabilities, DateTimeImmutable $now): void
    {
        $this->connection->update(self::TABLE, [
            'tools' => in_array(Capability::Tools, $capabilities, true),
            'images' => in_array(Capability::Images, $capabilities, true),
            'json' => in_array(Capability::Json, $capabilities, true),
            'updated_at' => UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform()),
        ], ['id' => $id], [
            'tools' => ParameterType::BOOLEAN,
            'images' => ParameterType::BOOLEAN,
            'json' => ParameterType::BOOLEAN,
            'id' => ParameterType::INTEGER,
        ]);
    }

    /**
     * Store a test's results, with the capabilities it confirmed or
     * cleared and the structured-output mode that worked.
     *
     * @param list<array{step: string, ok: bool, ms: int, error: ?string}> $results
     * @param list<Capability> $capabilities
     */
    public function recordTest(
        int $id,
        array $results,
        array $capabilities,
        ?JsonMode $jsonMode,
        DateTimeImmutable $now,
    ): void {
        $at = UtcDateTime::toDatabase($now, $this->connection->getDatabasePlatform());
        $this->connection->update(self::TABLE, [
            'tools' => in_array(Capability::Tools, $capabilities, true),
            'images' => in_array(Capability::Images, $capabilities, true),
            'json' => in_array(Capability::Json, $capabilities, true),
            'json_mode' => $jsonMode?->value,
            'tested_at' => $at,
            'test_results' => json_encode($results, JSON_THROW_ON_ERROR),
            'updated_at' => $at,
        ], ['id' => $id], [
            'tools' => ParameterType::BOOLEAN,
            'images' => ParameterType::BOOLEAN,
            'json' => ParameterType::BOOLEAN,
            'id' => ParameterType::INTEGER,
        ]);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'connection_id',
                'name',
                'label',
                'listed',
                'added',
                'tools',
                'images',
                'json',
                'json_mode',
                'tested_at',
                'test_results',
            )
            ->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AiModel
    {
        $mode = Row::nullableString($row, 'json_mode');
        $results = Row::nullableString($row, 'test_results');
        $decoded = $results === null ? [] : json_decode($results, true);

        return new AiModel(
            id: Row::int($row, 'id'),
            connectionId: Row::int($row, 'connection_id'),
            name: Row::string($row, 'name'),
            label: Row::nullableString($row, 'label'),
            listed: Row::bool($row, 'listed'),
            added: Row::bool($row, 'added'),
            tools: Row::bool($row, 'tools'),
            images: Row::bool($row, 'images'),
            json: Row::bool($row, 'json'),
            jsonMode: $mode === null ? null : JsonMode::tryFrom($mode),
            testedAt: ($row['tested_at'] ?? null) === null
                ? null
                : UtcDateTime::fromDatabase($row['tested_at'], $this->connection->getDatabasePlatform()),
            testResults: self::results($decoded),
        );
    }

    /**
     * @return list<array{step: string, ok: bool, ms: int, error: ?string}>
     */
    private static function results(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }
        $results = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || !is_string($item['step'] ?? null)) {
                continue;
            }
            $error = $item['error'] ?? null;
            $results[] = [
                'step' => $item['step'],
                'ok' => ($item['ok'] ?? false) === true,
                'ms' => is_int($item['ms'] ?? null) ? $item['ms'] : 0,
                'error' => is_string($error) ? $error : null,
            ];
        }

        return $results;
    }
}
