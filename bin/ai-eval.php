<?php

declare(strict_types=1);

/*
 * Evaluate Ask Logbook against a real model (spec.md §7.26, Phase 26.2):
 * 40 questions asked of the configured `ask` model as the demo owner, each
 * with the tools it should call and, where the answer is a figure, the
 * figure it should contain. The expected figure is worked out at run time
 * by calling the same tool directly, so the script follows the demo data
 * whenever it was seeded.
 *
 *   ./bin/dev-setup.sh --with-sample-data        # the demo data, once
 *   php bin/ai-eval.php [--user=demo] [--only=1,5,12] [--verbose]
 *
 * It reports, per question, whether an expected tool was called, whether
 * the expected figure is in the answer, how many figures the grounding
 * check flagged, and the time; then the totals. It sends real requests to
 * the model (and counts against its connection's caps), so it is not run
 * in CI: paste its summary into the pull request for each model tried.
 * Threads it creates are deleted at the end.
 *
 * Exit code: 0 ran, 1 could not run, 2 usage.
 */

use Logbook\Domain\Ai\Ask\ToolRun;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Kernel;
use Logbook\Repository\AiThreadRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\Ask\AskAvailability;
use Logbook\Service\Ai\Ask\Conversation;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\UserDisplayScope;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['user:', 'only:', 'verbose', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/ai-eval.php [--user=demo] [--only=1,5,12] [--verbose]\n");
    exit(0);
}
$username = is_string($options['user'] ?? null) ? $options['user'] : 'demo';
$only = is_string($options['only'] ?? null)
    ? array_map('intval', explode(',', $options['only']))
    : [];
$verbose = isset($options['verbose']);

$container = Kernel::createContainer(Kernel::settings());
$get = static function (string $class) use ($container): object {
    $service = $container->get($class);
    assert($service instanceof $class);

    return $service;
};
$users = $get(UserRepository::class);
assert($users instanceof UserRepository);
$user = $users->findByUsername(Username::normalise($username));
if (!$user instanceof User) {
    fwrite(STDERR, sprintf("There is no user \"%s\".\n", $username));
    fwrite(STDERR, "Seed the demo data with ./bin/dev-setup.sh --with-sample-data.\n");
    exit(1);
}
$availability = $get(AskAvailability::class);
assert($availability instanceof AskAvailability);
if (!$availability->isAvailable($user)) {
    fwrite(STDERR, "Ask Logbook is not available to that user: set a model for Ask in Settings → AI.\n");
    exit(1);
}
$connection = $availability->connection();

$vehicles = $get(VehicleService::class);
assert($vehicles instanceof VehicleService);
$fleet = $vehicles->listFleet($user, true);
$id = static function (string $words) use ($fleet): ?int {
    foreach ($fleet as $vehicle) {
        if (stripos($vehicle->name() . ' ' . $vehicle->data->make . ' ' . $vehicle->data->model, $words) !== false) {
            return $vehicle->id;
        }
    }

    return null;
};
$golf = $id('Golf');
$corolla = $id('Corolla');
$triple = $id('Triple');
$ev6 = $id('EV6');
$fiesta = $id('Fiesta');
$outlander = $id('Outlander');
$lastYear = (int) date('Y') - 1;
$year = ['from' => $lastYear . '-01-01', 'to' => $lastYear . '-12-31'];

/*
 * [question, tools any of which counts as right (empty: none expected),
 *  the call whose first figure the answer should contain, or null]
 */
$questions = [
    ["How much did I spend on fuel in $lastYear?", ['costs'], ['costs', $year + ['category' => 'fuel']]],
    ['When did I last change the oil on the Golf?', ['maintenance', 'last_done'], null],
    ['Which car costs me the most per mile?', ['cost_per_distance'], null],
    ['How much did I spend on fuel last month?', ['costs'], ['costs', ['period' => 'last_month', 'category' => 'fuel']]],
    ['What has the Golf cost me this year?', ['costs'], ['costs', ['vehicles' => [$golf], 'period' => 'this_year']]],
    ['What economy am I getting from the Corolla?', ['fuel_stats', 'vehicle_summary'], null],
    ['How many miles have I driven in the last 12 months?', ['mileage', 'costs'], null],
    ['What is the Fiesta\'s mileage now?', ['vehicle_summary', 'mileage'], null],
    ['When is the Golf\'s MOT due?', ['documents', 'coming_up'], null],
    ['When does the insurance on the EV6 run out?', ['documents', 'coming_up'], null],
    ['What is coming up in the next three months?', ['coming_up'], null],
    ['Is anything overdue?', ['needs_attention', 'coming_up'], null],
    ['How are the tyres on the Golf?', ['tyres'], null],
    ['How much tread is left on the Street Triple\'s rear tyre?', ['tyres'], null],
    ['What is the Outlander costing me to own?', ['ownership'], null],
    ['How much has the Corolla lost in value?', ['ownership'], null],
    [
        'How much did I spend on maintenance in the last 12 months?',
        ['costs'],
        ['costs', ['period' => 'last_12_months', 'category' => 'maintenance']],
    ],
    ['Break down this year\'s spending by month.', ['costs'], null],
    ["Which vehicle did I spend most on in $lastYear?", ['costs', 'cost_per_distance'], null],
    ['What have I paid per litre for fuel this year, on average?', ['fuel_stats'], null],
    ['How much electricity has the EV6 used this year?', ['fuel_stats'], null],
    ['What does the EV6 cost per mile to run?', ['cost_per_distance', 'vehicle_summary', 'ownership'], null],
    ['When was the Golf last serviced?', ['last_done', 'maintenance'], null],
    ['List the repairs on the Fiesta.', ['maintenance'], null],
    ['How much did the last service on the Corolla cost?', ['maintenance', 'last_done'], null],
    ['Does anything need my attention on the Outlander?', ['needs_attention'], null],
    ['How many business miles have I done this tax year?', ['trips_summary'], null],
    ['How much can I claim for mileage this tax year?', ['trips_summary'], null],
    ['What is the Golf\'s registration?', [], null],
    ['Which of my vehicles are electric?', [], null],
    [
        'How much have I spent on fuel across all my vehicles so far this year?',
        ['costs'],
        ['costs', ['period' => 'this_year', 'category' => 'fuel']],
    ],
    ['What have I spent on parking and tolls?', ['costs'], null],
    ['How far does the Corolla go between fill-ups?', ['fuel_stats', 'mileage'], null],
    ['Is premium fuel worth it in the Golf?', ['fuel_stats'], null],
    ['What is due next on the Street Triple?', ['coming_up', 'vehicle_summary'], null],
    ['How old is the Fiesta?', ['vehicle_summary'], null],
    [
        "How much did I spend on insurance and MOTs in $lastYear?",
        ['costs', 'documents'],
        ['costs', $year + ['category' => 'compliance']],
    ],
    ['What will the weather be tomorrow?', [], null],
    ['What is the best engine oil for a Golf?', [], null],
    ['How much did I spend on the BMW?', ['find_vehicles', 'costs'], null],
];
unset($triple, $ev6, $fiesta, $outlander, $corolla);

$conversation = $get(Conversation::class);
assert($conversation instanceof Conversation);
$registry = $get(ToolRegistry::class);
assert($registry instanceof ToolRegistry);
$threads = $get(AiThreadRepository::class);
assert($threads instanceof AiThreadRepository);
$display = $get(UserDisplayScope::class);
assert($display instanceof UserDisplayScope);

printf(
    "Ask Logbook evaluation · %s · %s on %s (%s) · %s\n\n",
    $user->username,
    $connection->name ?? '?',
    $connection?->host() ?? '?',
    $connection->location->value ?? '?',
    date('Y-m-d H:i'),
);

$totals = [
    'asked' => 0,
    'tools_ok' => 0,
    'figure_checks' => 0,
    'figures_ok' => 0,
    'flagged' => 0,
    'failed' => 0,
    'seconds' => 0.0,
];
$created = [];
foreach ($questions as $index => [$question, $tools, $figureCall]) {
    $number = $index + 1;
    if ($only !== [] && !in_array($number, $only, true)) {
        continue;
    }
    $expected = null;
    if ($figureCall !== null) {
        $call = new ToolCall('eval', $figureCall[0], $figureCall[1]);
        $run = $display->run($user, static fn (): ToolRun => $registry->run($user, $call));
        $expected = $run->result?->figures[0] ?? null;
    }

    $started = microtime(true);
    $called = [];
    $answer = '';
    $flagged = [];
    $error = null;
    try {
        $outcome = $conversation->ask($user, null, $question);
        $created[] = $outcome->thread->id;
        $called = array_map(static fn (ToolRun $r): string => $r->name, $outcome->answer->toolRuns);
        $answer = $outcome->answer->content;
        $flagged = $outcome->answer->ungrounded;
        $error = $outcome->answer->error?->value;
    } catch (AiFailure $failure) {
        $error = $failure->error->value;
    }
    $seconds = microtime(true) - $started;

    $toolsOk = $tools === [] ? $called === [] || $error !== null : array_intersect($tools, $called) !== [];
    $figureOk = $expected === null ? null : str_contains(normalise($answer), normalise($expected));
    $totals['asked']++;
    $totals['tools_ok'] += $toolsOk && $error === null ? 1 : 0;
    $totals['figure_checks'] += $figureOk === null ? 0 : 1;
    $totals['figures_ok'] += $figureOk === true ? 1 : 0;
    $totals['flagged'] += $flagged === [] ? 0 : 1;
    $totals['failed'] += $error === null ? 0 : 1;
    $totals['seconds'] += $seconds;

    printf(
        "%2d. %s %s %s %5.1fs  %s\n",
        $number,
        $error !== null ? 'ERR' : ($toolsOk ? 'ok ' : 'BAD'),
        $figureOk === null ? '   ' : ($figureOk ? 'fig' : 'FIG'),
        $flagged === [] ? '  ' : sprintf('!%d', count($flagged)),
        $seconds,
        $question,
    );
    if ($verbose || !$toolsOk || $figureOk === false || $flagged !== [] || $error !== null) {
        printf(
            "      tools: %s (expected %s)\n",
            $called === [] ? 'none' : implode(', ', $called),
            $tools === [] ? 'none' : implode(' or ', $tools),
        );
        if ($expected !== null) {
            printf("      figure: %s\n", $expected);
        }
        if ($flagged !== []) {
            printf("      flagged: %s\n", implode(', ', $flagged));
        }
        if ($error !== null) {
            printf("      error: %s\n", $error);
        }
        printf("      answer: %s\n", str_replace("\n", ' ', mb_strimwidth($answer, 0, 300, '…')));
    }
}

foreach ($created as $threadId) {
    $threads->delete($user->id, $threadId);
}

$asked = max(1, $totals['asked']);
printf(
    "\nTool accuracy %d/%d (%d%%) · figures %d/%d · answers with a flagged figure %d · failures %d"
    . " · %.1f s total, %.1f s a question\n",
    $totals['tools_ok'],
    $totals['asked'],
    (int) round(100 * $totals['tools_ok'] / $asked),
    $totals['figures_ok'],
    $totals['figure_checks'],
    $totals['flagged'],
    $totals['failed'],
    $totals['seconds'],
    $totals['seconds'] / $asked,
);
exit(0);

/**
 * Figures compared without the kinds of space ICU puts in them.
 */
function normalise(string $text): string
{
    return str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
}
