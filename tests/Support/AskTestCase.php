<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\User\User;
use Logbook\Support\Display\DisplayPreferences;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Boots the app with a model on the network assigned to `ask`, a scripted
 * provider and an owner, for Ask Logbook's tests.
 */
abstract class AskTestCase extends AiTestCase
{
    use CostFixtures;

    protected const string NOW = '2026-10-15T12:00:00Z';

    /**
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, User}
     */
    protected function askApp(array $env = [], ?DisplayPreferences $preferences = null): array
    {
        $app = $this->aiApp($env);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'owner', $preferences);
        $connection = $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->assign($app, $connection, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);

        return [$app, $owner];
    }
}
