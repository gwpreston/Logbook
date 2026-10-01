<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Repository\AiSecretRepository;
use Logbook\Service\Ai\AdapterFactory;
use Logbook\Service\Ai\SecretBox;
use Logbook\Support\Net\HostResolver;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Boots the app with a recorded model provider and fake DNS, so the AI
 * tests send nothing anywhere (spec.md §7.25 *Tests*).
 */
abstract class AiTestCase extends AppTestCase
{
    protected const string SECRET = 'a-test-session-secret-of-32-chars!';

    protected AiProviderMock $provider;
    protected FakeHostResolver $dns;

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function aiApp(array $env = []): App
    {
        $app = $this->createApp($env + ['SESSION_SECRET' => self::SECRET]);
        $this->provider = new AiProviderMock();
        $this->dns = new FakeHostResolver();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, $this->provider->client);
        $container->set(HostResolver::class, $this->dns);

        return $app;
    }

    /**
     * A connection straight into the database (not through the form).
     *
     * @param App<ContainerInterface> $app
     */
    protected function addConnection(
        App $app,
        string $name,
        AdapterType $adapter,
        string $url,
        Location $location,
        ?string $apiKey = null,
        ?int $cap = null,
        int $maxMb = 8,
    ): int {
        $id = $this->service($app, AiConnectionRepository::class)->insert(new ConnectionSettings(
            name: $name,
            adapter: $adapter,
            baseUrl: $url,
            location: $location,
            headerNames: [],
            timeoutSeconds: $location->defaultTimeout(),
            verifyTls: true,
            caBundle: null,
            maxRequestMb: $maxMb,
            monthlyTokenCap: $cap,
            enabled: true,
        ), new DateTimeImmutable('2026-10-01T00:00:00Z'));
        if ($apiKey !== null) {
            $this->service($app, AiSecretRepository::class)->put(
                $id,
                AdapterFactory::API_KEY,
                $this->service($app, SecretBox::class)->store($apiKey),
                new DateTimeImmutable('2026-10-01T00:00:00Z'),
            );
        }

        return $id;
    }

    /**
     * OpenAI, on the internet, with a key.
     *
     * @param App<ContainerInterface> $app
     */
    protected function cloud(App $app, string $key = 'sk-test'): int
    {
        $url = 'https://api.openai.com/v1';

        return $this->addConnection($app, 'OpenAI', AdapterType::OpenAiCompatible, $url, Location::Internet, $key);
    }

    /**
     * An OpenAI-compatible server on the network.
     *
     * @param App<ContainerInterface> $app
     */
    protected function network(App $app, string $name, string $url, ?string $key = null): int
    {
        return $this->addConnection($app, $name, AdapterType::OpenAiCompatible, $url, Location::Network, $key);
    }

    /**
     * Add a model with capabilities and give it a task.
     *
     * @param App<ContainerInterface> $app
     * @param list<Capability> $capabilities
     */
    protected function assign(App $app, int $connectionId, string $model, AiTaskName $task, array $capabilities): int
    {
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $models = $this->service($app, AiModelRepository::class);
        $id = $models->add($connectionId, $model, $now);
        $models->setCapabilities($id, $capabilities, $now);
        $this->service($app, AiTaskRepository::class)->assign(new AiTaskAssignment($task, $id, null, null), $now);

        return $id;
    }
}
