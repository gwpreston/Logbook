<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Working\ClaimWorking;
use Logbook\Service\Working\EconomyWorking;
use Logbook\Service\Working\FinanceWorking;
use Logbook\Service\Working\ForecastWorking;
use Logbook\Service\Working\OwnershipWorking;
use Logbook\Service\Working\ReportWorking;
use Logbook\Service\Working\TrueCostWorking;
use Logbook\Service\Working\WorthItWorking;
use Logbook\Support\Working;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The inventory of spec.md §8 (*Show the working*): every headline figure
 * has a builder that returns a Working, and the agreement view carries the
 * three finance workings. Adding a figure to the inventory without one
 * fails here; list it when it is built.
 */
final class WorkingCoverageTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function builders(): array
    {
        return [
            'depreciation for a period' => [TrueCostWorking::class, 'depreciation'],
            'true cost for a period' => [TrueCostWorking::class, 'period'],
            'what changed' => [TrueCostWorking::class, 'change'],
            'settlement estimate' => [FinanceWorking::class, 'settlement'],
            'equity' => [FinanceWorking::class, 'equity'],
            'interest so far' => [FinanceWorking::class, 'interestSoFar'],
            'cost per distance and of ownership' => [OwnershipWorking::class, 'forCost'],
            'depreciation since bought' => [OwnershipWorking::class, 'depreciation'],
            'economy for a segment' => [EconomyWorking::class, 'forFill'],
            'mileage claim' => [ClaimWorking::class, 'forTotals'],
            'coming up total' => [ForecastWorking::class, 'forTotals'],
            'reports cost per distance' => [ReportWorking::class, 'costPerDistance'],
            'was it worth it' => [WorthItWorking::class, 'forRow'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('builders')]
    public function testEveryInventoryFigureHasABuilderReturningAWorking(string $class, string $method): void
    {
        self::assertTrue(method_exists($class, $method), $class . '::' . $method . ' must exist');
        $type = (new ReflectionMethod($class, $method))->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Working::class, $type->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function agreementWorkings(): array
    {
        return [
            'settlement' => ['settlementWorking'],
            'equity' => ['equityWorking'],
            'interest so far' => ['interestWorking'],
        ];
    }

    #[DataProvider('agreementWorkings')]
    public function testTheAgreementViewCarriesItsWorkings(string $property): void
    {
        self::assertTrue(property_exists(AgreementView::class, $property));
        $type = (new ReflectionProperty(AgreementView::class, $property))->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Working::class, $type->getName());
    }
}
