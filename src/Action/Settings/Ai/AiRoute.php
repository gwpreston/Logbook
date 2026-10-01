<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ai;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiModel;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Loads the connection (and model) a Settings → AI route names, or 404.
 */
final readonly class AiRoute
{
    public function __construct(
        private AiConnectionRepository $connections,
        private AiModelRepository $models,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function connection(ServerRequestInterface $request, array $args): AiConnection
    {
        return $this->connections->find((int) ($args['connection'] ?? 0)) ?? throw new HttpNotFoundException($request);
    }

    /**
     * The model named by a form field (`model`), which must be the connection's.
     */
    public function model(ServerRequestInterface $request, AiConnection $connection, mixed $id): AiModel
    {
        $model = is_numeric($id) ? $this->models->find((int) $id) : null;
        if ($model === null || $model->connectionId !== $connection->id) {
            throw new HttpNotFoundException($request);
        }

        return $model;
    }
}
