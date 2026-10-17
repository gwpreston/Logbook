<?php

declare(strict_types=1);

/*
 * Maintainer-only (spec.md §10 *README and docs*, Phase 52): stores the kept
 * *Ask Logbook* conversation the screenshots show, on a throwaway instance
 * seeded with the demo data. It switches AI on with a connection that is
 * never called, runs Logbook's own read-only tools for the demo account, and
 * saves a question and an answer built from what they returned, so no model
 * is asked anything. bin/screenshots.mjs copies it into the container.
 *
 *   php bin/tools/screenshot-ask.php [app root]
 *
 * It refuses to run unless the database is the demo's (the demo marker is
 * there), and DEMO_MODE is off), so it can't touch a real install or a running demo. Prints the thread's path.
 */

use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Feature\Feature;
use Logbook\Kernel;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Repository\AiThreadRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoState;
use Logbook\Service\Feature\FeatureToggles;
use Psr\Container\ContainerInterface;

$root = $argv[1] ?? dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function service(ContainerInterface $container, string $class): object
{
    $service = $container->get($class);
    assert($service instanceof $class);

    return $service;
}

$container = Kernel::createApp(Kernel::settings())->getContainer();
if (service($container, DemoMode::class)->state() !== DemoState::Inert) {
    fwrite(STDERR, "Not a demo database (the demo marker with DEMO_MODE off); nothing was changed.\n");
    exit(1);
}
$user = service($container, UserRepository::class)->findByUsername('demo');
if ($user === null) {
    fwrite(STDERR, "No demo account.\n");
    exit(1);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$connection = service($container, AiConnectionRepository::class)->insert(new ConnectionSettings(
    name: 'Ollama',
    adapter: AdapterType::OpenAiCompatible,
    baseUrl: 'http://127.0.0.1:11434/v1',
    location: Location::Server,
    headerNames: [],
    timeoutSeconds: Location::Server->defaultTimeout(),
    verifyTls: true,
    caBundle: null,
    maxRequestMb: 8,
    monthlyTokenCap: null,
    enabled: true,
), $now);
$models = service($container, AiModelRepository::class);
$model = $models->add($connection, 'llama3.1:8b', $now);
$models->setCapabilities($model, [Capability::Tools], $now);
service($container, AiTaskRepository::class)->assign(new AiTaskAssignment(AiTaskName::Ask, $model, null, null), $now);
$features = service($container, FeatureToggles::class);
$on = array_keys(array_filter($features->own()));
$features->save([...array_map(static fn (string $f): Feature => Feature::from($f), $on), Feature::AiAsk]);
service($container, AiPreferences::class)->set($user->id, true);

$question = 'How much did I spend on fuel in the last 12 months, and which car costs most per mile?';
$tools = service($container, ToolRegistry::class);
$costs = $tools->run($user, new ToolCall('call_1', 'costs', ['period' => 'last_12_months', 'category' => 'fuel']));
$perMile = $tools->run($user, new ToolCall('call_2', 'cost_per_distance', ['period' => 'last_12_months']));
if ($costs->result === null || $perMile->result === null) {
    fwrite(STDERR, 'A tool failed: ' . ($costs->error ?? $perMile->error ?? '') . "\n");
    exit(1);
}

$answer = answer($costs->result->figures, $perMile->result->figures);
$threads = service($container, AiThreadRepository::class);
$thread = $threads->create($user->id, $question, $now->modify('-2 minutes'));
$threads->addMessage($thread, AskRole::User, $question, $now->modify('-2 minutes'));
$threads->addMessage(
    $thread,
    AskRole::Assistant,
    $answer,
    $now->modify('-1 minute'),
    [$costs, $perMile],
    [],
    'Ollama',
    Location::Server,
    'llama3.1:8b',
);
fwrite(STDOUT, '/insights/questions/' . $thread->id . "\n");

/**
 * The answer, worded from the tools' own figures so the grounding check has
 * nothing to flag: every currency's fuel total, and the cheapest and dearest
 * vehicle per distance ("Name: £0.142/mi").
 *
 * @param list<string> $costs
 * @param list<string> $perMile
 */
function answer(array $costs, array $perMile): string
{
    $lines = [$costs === []
        ? 'I found no fuel spend in the last 12 months.'
        : sprintf(
            'You spent **%s** on fuel across the garage in the last 12 months.',
            implode('** and **', $costs),
        )];
    // Only rates in the first total's currency, so pounds aren't ranked against euros.
    $symbol = mb_substr($costs[0] ?? '£', 0, 1);
    $perMile = array_values(array_filter(
        $perMile,
        static fn (string $figure): bool => str_contains($figure, ': ' . $symbol),
    ));
    usort($perMile, static fn (string $a, string $b): int => rate($a) <=> rate($b));
    if (count($perMile) > 1) {
        $lines[] = sprintf(
            'The dearest to run per mile is the %s, and the cheapest the %s.',
            str_replace(': ', ' at ', (string) end($perMile)),
            str_replace(': ', ' at ', $perMile[0]),
        );
    }

    return implode("\n\n", $lines);
}

/** The number after a per-distance figure's name. */
function rate(string $figure): float
{
    $value = substr($figure, (int) strrpos($figure, ': ') + 2);

    return (float) preg_replace('/[^0-9.]/', '', $value);
}
