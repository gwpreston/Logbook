<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DI\Container;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\User\User;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Support\Json\SchemaCheck;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ConfigurableVehicleAccess;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Shared steps for the Ask tool tests (spec.md §7.26 *Tests*): call a tool
 * through the registry as a user, and an app whose access can be narrowed.
 */
trait ToolsATesting
{
    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $arguments
     */
    private function call(App $app, User $user, string $tool, array $arguments): ToolRun
    {
        return $this->service($app, ToolRegistry::class)->run($user, new ToolCall('call_1', $tool, $arguments));
    }

    /**
     * The tool's result data, failing on an error.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $arguments
     */
    private function data(App $app, User $user, string $tool, array $arguments): JsonDoc
    {
        $run = $this->call($app, $user, $tool, $arguments);
        self::assertNull($run->error, (string) $run->error);
        self::assertNotNull($run->result);

        return new JsonDoc($run->result->data);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private function offered(App $app, User $user): array
    {
        return array_map(
            static fn (AskTool $tool): string => $tool->name(),
            $this->service($app, ToolRegistry::class)->available($user),
        );
    }

    /**
     * The tool's schema is an object schema the sample arguments fit.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $sample
     */
    private function assertSchemaFits(App $app, string $tool, array $sample): void
    {
        foreach ($this->service($app, ToolRegistry::class)->available($this->owner($app)) as $each) {
            if ($each->name() !== $tool) {
                continue;
            }
            $schema = $each->definition()->parameters;
            self::assertSame('object', $schema['type']);
            self::assertSame([], SchemaCheck::errors($sample, $schema));
            self::assertNotSame([], SchemaCheck::errors(['nonsense' => true], $schema), 'unknown arguments are refused');

            return;
        }
        self::fail(sprintf('%s is not offered.', $tool));
    }

    /**
     * An app whose vehicle abilities a test can narrow, with the Ask model set up.
     *
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, User, ConfigurableVehicleAccess}
     */
    private function policyApp(array $env = []): array
    {
        $app = $this->aiApp($env);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $access = new ConfigurableVehicleAccess($this->service($app, VehicleRepository::class));
        $container->set(VehicleAccess::class, $access);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $connection = $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->assign($app, $connection, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);

        return [$app, $owner, $access];
    }

    /**
     * What the formatter shows for a user (as the tool does).
     *
     * @param App<ContainerInterface> $app
     * @param callable(DisplayFormatter): string $format
     */
    private function shown(App $app, User $user, callable $format): string
    {
        $formatter = $this->service($app, DisplayFormatter::class);

        return $this->service($app, UserDisplayScope::class)->run($user, static fn (): string => $format($formatter));
    }

    private static function prefs(string $locale, string $preset, string $currency = 'GBP'): DisplayPreferences
    {
        $units = UnitPreset::from($preset);

        return new DisplayPreferences(
            $locale,
            'Europe/London',
            $units->distance(),
            $units->volume(),
            $units->consumption(),
            $currency,
        );
    }
}
