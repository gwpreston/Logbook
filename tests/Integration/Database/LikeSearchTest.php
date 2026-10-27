<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Domain\FuelPrices\FeedStation;
use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Tests\Support\AiTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The AI model search and the station name search match text literally and
 * ignore case, non-ASCII letters too, but not accents, the same on every engine (K51, K144,
 * spec.md §7.25, §7.34): `_` and `%` are plain characters, `!` is the escape,
 * and SQLite's LOWER() lowers `Ö` as the server engines do.
 */
final class LikeSearchTest extends AiTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->aiApp();
    }

    public function testTheModelSearchTakesUnderscorePercentAndBangLiterally(): void
    {
        $models = $this->service($this->app, AiModelRepository::class);
        $connection = $this->cloud($this->app);
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');
        foreach (['gpt_4o', 'gpt-4o', 'gpt4o', 'mix 50% off', 'mix 500', 'a!b', 'ab', 'Ölmodell'] as $name) {
            $models->add($connection, $name, $now);
        }
        $search = fn (string $text): array => self::sorted(array_map(
            static fn ($model): string => $model->name,
            $models->listForConnection($connection, $text),
        ));

        self::assertSame(['gpt_4o'], $search('gpt_4'));
        self::assertSame(['gpt_4o'], $search('GPT_4'));
        self::assertSame(['mix 50% off'], $search('50%'));
        self::assertSame(['a!b'], $search('a!b'));
        self::assertSame(['Ölmodell'], $search('öl'));
        self::assertSame(['Ölmodell'], $search('ÖL'));
        $models->add($connection, 'Olmodell', $now);
        self::assertSame(['Ölmodell'], $search('öl'), 'accents count on every engine (K144, #552)');
        self::assertSame(['gpt-4o', 'gpt4o', 'gpt_4o'], $search('gpt'));
    }

    public function testTheStationSearchTakesUnderscorePercentAndBangLiterallyAndLowersEveryLetter(): void
    {
        $stations = $this->service($this->app, ProviderStationRepository::class);
        $stations->upsertStations('test', [
            new FeedStation('1', 'Sale 50% off', 'Tesco'),
            new FeedStation('2', 'Sale 500', 'Shell'),
            new FeedStation('3', 'Ölhandel Müller', 'ÖLMAX'),
            new FeedStation('4', 'Olhandel', null),
            new FeedStation('5', 'Pump_1', null),
            new FeedStation('6', 'Pump-1', null),
            new FeedStation('7', 'Bang!Fuel', null),
            new FeedStation('8', 'BangFuel', null),
        ], new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $search = fn (string $text): array => self::sorted(array_map(
            static fn (ProviderStation $station): string => $station->data->name,
            $stations->searchByName('test', $text),
        ));

        self::assertSame(['Sale 50% off'], $search('50%'));
        self::assertSame(['Ölhandel Müller'], $search('öl'), 'accents count on every engine (K144, #552)');
        self::assertSame(['Olhandel'], $search('olh'));
        self::assertSame(['Ölhandel Müller'], $search('MÜLLER'));
        self::assertSame(['Ölhandel Müller'], $search('ölmax'), 'the brand too');
        self::assertSame(['Pump_1'], $search('p_1'));
        self::assertSame(['Bang!Fuel'], $search('g!f'));
    }

    public function testLowerLowersEveryLetterAndKeepsNullOnEveryEngine(): void
    {
        $connection = $this->service($this->app, Connection::class);
        $row = $connection->fetchNumeric("SELECT LOWER('ÖLHANDEL Müller'), LOWER(NULL)");

        self::assertSame(['ölhandel müller', null], $row === false ? null : array_map(
            static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
            $row,
        ));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
