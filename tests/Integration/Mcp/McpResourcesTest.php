<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mcp;

use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\JsonDoc;
use Logbook\Tests\Support\McpClient;

/**
 * Resources and prompts (spec.md §7.28), and the key user's language for
 * everything written for the client's model.
 */
final class McpResourcesTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTheVehiclesMeAndSummaryResources(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $old = $this->vehicle($app, 'Ford', 'Fiesta');
        $this->service($app, VehicleService::class)->archive($owner, $old);
        $mcp = new McpClient($app, $this->apiKey($app, $owner, ApiScope::Read, 'Desktop'));

        $listed = McpClient::result($mcp->modern('resources/list'));
        self::assertSame(['logbook://vehicles', 'logbook://me'], $listed->column('uri', 'resources'));
        $templates = McpClient::result($mcp->modern('resources/templates/list'));
        self::assertSame('logbook://vehicles/{id}/summary', $templates->get('resourceTemplates', 0, 'uriTemplate'));

        $vehicles = $this->read($mcp, 'logbook://vehicles');
        self::assertSame([
            ['id' => $golf->id, 'name' => 'Volkswagen Golf', 'registration' => 'GO19 ABC', 'type' => 'car',
                'make' => 'Volkswagen', 'model' => 'Golf', 'year' => null, 'fuel_type' => 'petrol', 'status' => 'active'],
            ['id' => $old->id, 'name' => 'Ford Fiesta', 'registration' => 'FI19 ABC', 'type' => 'car', 'make' => 'Ford',
                'model' => 'Fiesta', 'year' => null, 'fuel_type' => 'petrol', 'status' => VehicleStatus::Archived->value],
        ], $vehicles->toArray());

        $me = $this->read($mcp, 'logbook://me');
        self::assertSame('en_GB', $me->get('user', 'locale'));
        self::assertSame('Europe/London', $me->get('user', 'timezone'));
        self::assertSame('GBP', $me->get('user', 'currency'));
        self::assertSame('Desktop', $me->get('key', 'name'));
        self::assertSame('04-06', $me->get('tax_year_start'), 'GB: the tax year starts on 6 April');

        $summary = $this->read($mcp, 'logbook://vehicles/' . $golf->id . '/summary');
        self::assertSame($golf->id, $summary->get('vehicle', 'id'));

        foreach (['logbook://vehicles/999999/summary', 'logbook://nothing', 'file:///etc/passwd'] as $uri) {
            $modern = McpClient::body($mcp->modern('resources/read', ['uri' => $uri]));
            self::assertSame(-32602, $modern->get('error', 'code'), $uri);
            $legacy = McpClient::body($mcp->legacy('resources/read', ['uri' => $uri]));
            self::assertSame(-32002, $legacy->get('error', 'code'), $uri);
        }
    }

    public function testPromptsNameTheToolsAndTheVehicle(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://cars.example']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner, ApiScope::Read));

        $prompts = McpClient::result($mcp->modern('prompts/list'))->doc('prompts');
        self::assertSame(['monthly_summary', 'before_service', 'sale_checklist'], $prompts->column('name'));
        self::assertSame([], $prompts->get(0, 'arguments'));
        self::assertSame('vehicle', $prompts->get(1, 'arguments', 0, 'name'));

        $monthly = McpClient::result($mcp->modern('prompts/get', ['name' => 'monthly_summary']));
        self::assertSame('user', $monthly->get('messages', 0, 'role'));
        self::assertStringContainsString('last_month', $monthly->string('messages', 0, 'content', 'text'));

        $sale = McpClient::result($mcp->modern('prompts/get', [
            'name' => 'sale_checklist',
            'arguments' => ['vehicle' => (string) $golf->id],
        ]));
        $text = $sale->string('messages', 0, 'content', 'text');
        self::assertStringContainsString('Volkswagen Golf', $text);
        self::assertStringContainsString('https://cars.example/vehicles/' . $golf->id . '/sale-pack', $text);

        $other = $this->createMember($app);
        $theirs = $this->service($app, VehicleService::class)
            ->create($other, new VehicleData(VehicleType::Car, 'Skoda', 'Octavia', FuelType::Diesel));
        foreach ([['vehicle' => (string) $theirs->id], ['vehicle' => 'golf'], []] as $arguments) {
            $refused = $mcp->modern('prompts/get', ['name' => 'before_service', 'arguments' => $arguments ?: new \stdClass()]);
            self::assertSame(-32602, McpClient::body($refused)->get('error', 'code'), (string) json_encode($arguments));
            self::assertStringNotContainsString('Octavia', (string) $refused->getBody());
        }
        self::assertSame(-32602, McpClient::body($mcp->modern('prompts/get', ['name' => 'nope']))->get('error', 'code'));
    }

    public function testEverythingForTheModelIsInTheKeyUsersLanguage(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'owner', new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::LitresPer100Km,
            'EUR',
        ));
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner));

        $listed = McpClient::result($mcp->modern('tools/list'))->doc('tools')->toArray();
        $tools = new JsonDoc(array_column($listed, 'description', 'name'));
        self::assertStringStartsWith('Findet die Fahrzeuge', $tools->string('find_vehicles'));
        self::assertStringStartsWith('Trägt einen Tankvorgang', $tools->string('log_fill_up'));
        $discovered = McpClient::result($mcp->modern('server/discover'));
        self::assertStringContainsString('Logbook verwaltet', $discovered->string('instructions'));
        self::assertSame('Monatsübersicht', McpClient::result($mcp->modern('prompts/list'))->get('prompts', 0, 'title'));

        $saved = McpClient::structured($mcp->modern('tools/call', ['name' => 'draft_expense', 'arguments' => [
            'vehicle' => $golf->id,
            'category' => 'Parken',
            'amount' => '4,50',
        ]]));
        self::assertStringStartsWith('Entwurf gespeichert.', $saved->string('say'));
    }

    public function testEveryToolHasADescriptionInEveryLanguage(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $this->vehicle($app);
        $tools = McpClient::result((new McpClient($app, $this->apiKey($app, $owner)))->modern('tools/list'))->doc('tools');

        self::assertCount(27, $tools);
        foreach ($tools->keys() as $index) {
            $name = $tools->string($index, 'name');
            self::assertNotSame('mcp.tool.' . $name, $tools->get($index, 'description'), $name);
        }
    }

    private function read(McpClient $mcp, string $uri): JsonDoc
    {
        $contents = McpClient::result($mcp->modern('resources/read', ['uri' => $uri]))->doc('contents');
        self::assertSame($uri, $contents->get(0, 'uri'));
        self::assertSame('application/json', $contents->get(0, 'mimeType'));
        $data = json_decode($contents->string(0, 'text'), true);
        self::assertIsArray($data);

        return new JsonDoc($data);
    }
}
