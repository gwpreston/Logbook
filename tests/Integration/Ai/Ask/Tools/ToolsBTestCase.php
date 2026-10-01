<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DI\Container;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\User\User;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Support\Json\SchemaCheck;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\ConfigurableVehicleAccess;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Calls Ask tools the way the loop does: through the registry, so the
 * rolled-back transaction and the user's display scope apply.
 */
abstract class ToolsBTestCase extends AskTestCase
{
    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $arguments
     */
    protected function call(App $app, User $user, string $tool, array $arguments = []): ToolRun
    {
        return $this->service($app, ToolRegistry::class)->run($user, new ToolCall('call_1', $tool, $arguments));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $arguments
     */
    protected function toolResult(App $app, User $user, string $tool, array $arguments = []): ToolResult
    {
        $run = $this->call($app, $user, $tool, $arguments);
        self::assertNull($run->error, (string) $run->error);
        self::assertNotNull($run->result);

        return $run->result;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $arguments
     */
    protected function data(App $app, User $user, string $tool, array $arguments = []): JsonDoc
    {
        return new JsonDoc($this->toolResult($app, $user, $tool, $arguments)->data);
    }

    /**
     * The tool's schema accepts a typical call and is offered.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $sample
     */
    protected function assertSchemaAccepts(App $app, User $user, string $tool, array $sample): void
    {
        $definitions = $this->service($app, ToolRegistry::class)->definitions($user);
        $found = null;
        foreach ($definitions as $definition) {
            if ($definition->name === $tool) {
                $found = $definition;
            }
        }
        self::assertNotNull($found, $tool . ' is offered');
        self::assertSame('object', $found->parameters['type']);
        self::assertSame([], SchemaCheck::errors($sample, $found->parameters));
        $unknown = SchemaCheck::errors(['no_such_argument' => 1], $found->parameters);
        self::assertNotSame([], $unknown, 'unknown arguments are refused');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function isOffered(App $app, User $user, string $tool): bool
    {
        foreach ($this->service($app, ToolRegistry::class)->available($user) as $offered) {
            if ($offered->name() === $tool) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function policy(App $app): ConfigurableVehicleAccess
    {
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $access = new ConfigurableVehicleAccess($this->service($app, VehicleRepository::class));
        $container->set(VehicleAccess::class, $access);

        return $access;
    }

    protected static function json(JsonDoc $data): string
    {
        return (string) json_encode($data->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
