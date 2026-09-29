<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\SalePack;

use Logbook\Service\SalePack\PaperworkKind;
use Logbook\Service\SalePack\SalePackOptions;
use PHPUnit\Framework\TestCase;

/**
 * The sale pack's options (spec.md §7.19): defaults until the form is sent,
 * strict parsing, work costs only at `costs=1`, and paperwork kinds only
 * from those offered.
 */
final class SalePackOptionsTest extends TestCase
{
    public function testTheDefaultsUntilTheFormIsSent(): void
    {
        $options = SalePackOptions::fromQuery([], PaperworkKind::cases());

        self::assertTrue($options->dueNext);
        self::assertTrue($options->descriptions);
        self::assertFalse($options->timeline);
        self::assertFalse($options->costs);
        self::assertSame([PaperworkKind::Service, PaperworkKind::Inspection, PaperworkKind::Photo], $options->kinds);
        self::assertSame([], $options->exclude);
    }

    public function testOnceSentAnAbsentBoxIsUnticked(): void
    {
        $query = ['options' => '1', 'timeline' => '1', 'kinds' => ['purchase']];
        $options = SalePackOptions::fromQuery($query, PaperworkKind::cases());

        self::assertFalse($options->dueNext);
        self::assertFalse($options->descriptions);
        self::assertTrue($options->timeline);
        self::assertSame([PaperworkKind::Purchase], $options->kinds);
    }

    public function testOnlyCostsOneShowsWorkCosts(): void
    {
        foreach ([null, '0', 'true', 'yes', 'on', ' 1', ['1']] as $value) {
            $query = $value === null ? [] : ['costs' => $value];
            self::assertFalse(SalePackOptions::fromQuery($query, [])->costs, var_export($value, true));
            self::assertFalse(SalePackOptions::fromQuery(['options' => '1'] + $query, [])->costs, var_export($value, true));
        }
        self::assertTrue(SalePackOptions::fromQuery(['costs' => '1'], [])->costs);
        self::assertTrue(SalePackOptions::fromQuery(['options' => '1', 'costs' => '1'], [])->costs);
    }

    public function testUnknownValuesFallBackToTheDefaults(): void
    {
        $query = ['due' => 'no', 'timeline' => 'yes', 'descriptions' => ['1']];
        $options = SalePackOptions::fromQuery($query, PaperworkKind::cases());
        self::assertTrue($options->dueNext, 'not sent: the default');
        self::assertFalse($options->timeline);
        self::assertTrue(SalePackOptions::fromQuery(['timeline' => '1'], [])->timeline, 'a link may turn a box on');

        $sent = SalePackOptions::fromQuery(['options' => '1', 'due' => 'yes', 'descriptions' => ['1']], PaperworkKind::cases());
        self::assertFalse($sent->dueNext, 'only 1 is on');
        self::assertFalse($sent->descriptions);
    }

    public function testKindsComeOnlyFromThoseOffered(): void
    {
        $offered = [PaperworkKind::Photo, PaperworkKind::Purchase];
        $options = SalePackOptions::fromQuery([
            'options' => '1',
            'kinds' => ['registration', 'sale', 'service', 'photo', ['purchase'], 'fuel'],
        ], $offered);

        self::assertSame([PaperworkKind::Photo], $options->kinds, 'service is not offered; the rest never are');
        $defaults = SalePackOptions::fromQuery([], $offered)->kinds;
        self::assertSame([PaperworkKind::Photo], $defaults, 'the defaults of those offered');
    }

    public function testExclusionsKeepPositiveIdsOnly(): void
    {
        $options = SalePackOptions::fromQuery(['exclude' => ['12', '0', '-3', 'x', '12', '7', ['9'], '1e3']], []);

        self::assertSame([12, 7], $options->exclude);
        self::assertSame([], SalePackOptions::fromQuery(['exclude' => '12'], [])->exclude);
    }

    public function testTheQueryReproducesTheOptions(): void
    {
        $options = SalePackOptions::fromQuery(
            ['options' => '1', 'descriptions' => '1', 'costs' => '1', 'kinds' => ['service', 'insurance'], 'exclude' => ['4']],
            PaperworkKind::cases(),
        );

        self::assertSame([
            'options' => '1',
            'descriptions' => '1',
            'costs' => '1',
            'kinds' => ['service', 'insurance'],
            'exclude' => ['4'],
        ], $options->query());
        self::assertEquals($options, SalePackOptions::fromQuery($options->query(), PaperworkKind::cases()));
        self::assertSame([], $options->excluding([])->exclude);
    }
}
