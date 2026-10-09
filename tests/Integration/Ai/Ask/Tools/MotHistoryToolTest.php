<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Service\Ai\Ask\AskTool;
use Psr\Container\ContainerInterface;
use Slim\App;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryFetcher;
use Logbook\Service\MotHistory\MotHistoryRegistry;
use Logbook\Service\MotHistory\Sample\SampleMotProvider;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\JsonDoc;

/**
 * `mot_history(vehicle)` (spec.md §7.26, §7.38): the stored tests, newest
 * first, with mileage, defects and the recall state, and the attribution;
 * only while MOT history is on; never fetches.
 */
final class MotHistoryToolTest extends ToolsBTestCase
{
    public function testTheGolfsMotHistoryWithItsRecallAndAttribution(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'LB19 KTR',
        ));
        $config = $this->service($app, MotHistoryConfig::class);
        self::assertNotContains('mot_history', $this->available($app, $owner), 'off: not offered');

        $config->saveProvider($this->service($app, MotHistoryRegistry::class)->get(SampleMotProvider::CODE));
        self::assertContains('mot_history', $this->available($app, $owner));
        $empty = new JsonDoc($this->toolResult($app, $owner, 'mot_history', ['vehicle' => $golf->id])->data);
        self::assertFalse($empty->get('fetched'));
        self::assertSame(0, $empty->int('total_count'));

        $fetcher = $this->service($app, MotHistoryFetcher::class);
        $fetcher->confirm($golf);
        $fetcher->fetch($golf);
        $this->assertSchemaAccepts($app, $owner, 'mot_history', ['vehicle' => $golf->id]);
        $result = $this->toolResult($app, $owner, 'mot_history', ['vehicle' => $golf->id]);
        $data = new JsonDoc($result->data);

        self::assertSame(6, $data->int('total_count'));
        self::assertStringStartsWith('An outstanding recall', $data->string('recall'));
        self::assertSame('passed', $data->get('tests', 0, 'result'));
        self::assertNotNull($data->get('tests', 0, 'odometer'));
        self::assertStringContainsString('Open Government Licence', $data->string('attribution'));
        self::assertStringContainsString('Open Government Licence', $result->source);
        self::assertSame('/vehicles/' . $golf->id . '/mot-history', $result->link);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private function available(App $app, User $owner): array
    {
        $tools = $this->service($app, ToolRegistry::class)->available($owner);

        return array_map(static fn (AskTool $tool): string => $tool->name(), $tools);
    }
}
