<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Working;

use DateTimeImmutable;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Finance\AgreementFigures;
use Logbook\Service\Finance\FinanceFigures;
use Logbook\Service\Finance\Schedule;
use Logbook\Service\Working\FinanceWorking;
use Logbook\Support\Working;
use Logbook\Tests\Support\WorkingWords;
use Logbook\Tests\Unit\Service\Finance\FinanceFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The working of the settlement estimate, equity and interest so far
 * (spec.md §8 *Show the working*) against AgreementFiguresTest's
 * hand-worked HP at 9.9% APR: on 15 Jul 2026 18 payments remain, the
 * settlement is 5,037.89, the first 30 payments hold 2,078.28 of interest.
 * Every working's result is the figure the page shows.
 */
final class FinanceWorkingTest extends TestCase
{
    public function testTheSettlementEstimateNamesItsInputsAndAgreesWithTheFigure(): void
    {
        [$agreement, $figures, $working] = $this->settled('settlement', '2026-07-15');

        self::assertNotNull($working);
        self::assertNotNull($figures->settlement);
        self::assertSame($figures->settlement->amount->toDecimal(2), $working->resultValue);
        self::assertSame('5037.89', $working->resultValue);
        self::assertSame(18, $figures->settlement->paymentCount);
        self::assertSame('5424.30', $figures->settlement->paymentsTotal?->toDecimal(2));
        self::assertStringContainsString('18 remaining payments', $working->steps[0]);
        self::assertStringContainsString('£5,424.30', $working->steps[0]);
        self::assertStringContainsString('0.79%', $working->steps[0], 'the monthly rate');
        self::assertStringContainsString('£5,037.89', $working->steps[0]);
        self::assertStringContainsString('quote will differ', implode(' ', $working->notes));
        self::assertSame('finance.show', $working->inputs[0]->link?->route);
        self::assertSame($agreement->id, $working->inputs[0]->link->params['agreement']);
    }

    public function testALendersQuoteIsTheInputAndIsDated(): void
    {
        $quote = new SettlementQuote(
            1,
            1,
            FinanceFixtures::date('2026-07-10'),
            '7612.08',
            FinanceFixtures::date('2026-07-31'),
            null,
        );
        [, , $working] = $this->settled('settlement', '2026-07-15', quotes: [$quote]);

        self::assertNotNull($working);
        self::assertSame('7612.08', $working->resultValue);
        self::assertSame('quote', $working->inputs[0]->asOf?->kind->value);
        self::assertTrue($working->inputs[0]->asOf->shows());
        self::assertStringContainsString('The lender quoted £7,612.08', $working->steps[0]);
    }

    public function testEquityIsTheValueLessTheSettlement(): void
    {
        [, $figures, $working] = $this->settled('equity', '2026-07-15', valuation: ['2026-06-01', '7200']);

        self::assertNotNull($working);
        self::assertNotNull($figures->equity);
        self::assertSame($figures->equity->amount->toDecimal(2), $working->resultValue);
        self::assertSame('2162.11', $working->resultValue);
        self::assertStringContainsString(
            'Value £7,200.00 (1 Jun 2026) − settlement £5,037.89 (estimated today) = £2,162.11.',
            $working->steps[0],
        );
        self::assertSame('valuation', $working->inputs[0]->asOf?->kind->value);
        self::assertStringContainsString('doesn’t estimate the value', $working->notes[0]);
    }

    public function testNoEquityWithoutARecentValuation(): void
    {
        [, , $working] = $this->settled('equity', '2026-07-15');

        self::assertNull($working);
    }

    public function testInterestSoFarListsTheLastThreePaymentsAndTheRule(): void
    {
        [, $figures, $working] = $this->settled('interest', '2026-07-15');

        self::assertNotNull($working);
        self::assertNotNull($figures->costOfCredit);
        self::assertSame('2078.28', $working->resultValue);
        self::assertSame($figures->costOfCredit->soFar?->toDecimal(2), $working->resultValue);
        self::assertStringContainsString('30 payments', $working->steps[0]);
        // The sum in the words is the sum of the steps the figure used.
        self::assertCount(4, $working->steps, 'the sum, then the last three payments');
        self::assertStringContainsString('Payment 28,', $working->steps[1]);
        self::assertStringContainsString('Payment 30,', $working->steps[3]);
        self::assertStringContainsString('balance', $working->notes[0]);
        self::assertSame(30, $figures->costOfCredit->paymentsMade);
    }

    public function testInterestStepsAddUpToTheInterestShares(): void
    {
        [, $figures] = $this->settled('interest', '2026-07-15');

        self::assertCount(48, $figures->interestSteps);
        foreach ($figures->interestSteps as $step) {
            self::assertSame($figures->interest[$step->number], $step->interest);
            self::assertSame(
                $step->capital(),
                (string) \Brick\Math\BigDecimal::of($step->balanceBefore)->minus($step->balanceAfter)->toScale(2),
                'capital is what leaves the balance, less the interest added',
            );
        }
    }

    public function testAnEndedAgreementSaysItIsExact(): void
    {
        $agreement = FinanceFixtures::hp(AgreementStatus::Completed, '2028-01-15');
        $figures = $this->figuresFor($agreement, '2028-02-01');
        $working = $this->builder()->interestSoFar($agreement, $figures);

        self::assertNotNull($working);
        self::assertTrue($figures->costOfCredit?->exact);
        self::assertSame($figures->costOfCredit->soFar?->toDecimal(2), $working->resultValue);
        self::assertStringContainsString('exact', $working->notes[0]);
    }

    public function testItReadsInGerman(): void
    {
        $agreement = FinanceFixtures::hp();
        $figures = $this->figuresFor($agreement, '2026-07-15', valuation: ['2026-06-01', '7200']);
        $today = FinanceFixtures::date('2026-07-15');
        $builder = new FinanceWorking(WorkingWords::translator('de'), WorkingWords::formatter(locale: 'de'));

        $settlement = $builder->settlement($agreement, $figures, $today);
        $equity = $builder->equity($agreement, $figures, $today);
        $interest = $builder->interestSoFar($agreement, $figures);

        self::assertNotNull($settlement);
        self::assertStringContainsString('18 verbleibenden Raten', $settlement->steps[0]);
        self::assertStringContainsString('5.037,89', $settlement->steps[0]);
        self::assertNotNull($equity);
        self::assertStringContainsString('Wert 7.200,00', $equity->steps[0]);
        self::assertStringContainsString('heute geschätzt', $equity->steps[0]);
        self::assertNotNull($interest);
        self::assertStringContainsString('30 bezahlten Raten', $interest->steps[0]);
        self::assertStringContainsString('Tilgung', $interest->steps[1]);
    }

    public function testALeaseHasNoSettlementEquityOrInterest(): void
    {
        $agreement = FinanceFixtures::lease();
        $figures = $this->figuresFor($agreement, '2026-01-01');
        $builder = $this->builder();
        $today = FinanceFixtures::date('2026-01-01');

        self::assertNull($builder->settlement($agreement, $figures, $today));
        self::assertNull($builder->equity($agreement, $figures, $today));
        self::assertNull($builder->interestSoFar($agreement, $figures));
    }

    /**
     * @param list<SettlementQuote> $quotes
     * @param array{0: string, 1: string}|null $valuation
     * @return array{0: FinanceAgreement, 1: FinanceFigures, 2: ?Working}
     */
    private function settled(string $which, string $today, array $quotes = [], ?array $valuation = null): array
    {
        $agreement = FinanceFixtures::hp();
        $figures = $this->figuresFor($agreement, $today, $quotes, $valuation);
        $day = FinanceFixtures::date($today);
        $builder = $this->builder();
        $working = match ($which) {
            'settlement' => $builder->settlement($agreement, $figures, $day),
            'equity' => $builder->equity($agreement, $figures, $day),
            default => $builder->interestSoFar($agreement, $figures),
        };

        return [$agreement, $figures, $working];
    }

    /**
     * @param list<SettlementQuote> $quotes
     * @param array{0: string, 1: string}|null $valuation
     */
    private function figuresFor(
        FinanceAgreement $agreement,
        string $today,
        array $quotes = [],
        ?array $valuation = null,
    ): FinanceFigures {
        $day = FinanceFixtures::date($today);
        $value = $valuation === null ? null : new VehicleValuation(
            1,
            1,
            new VehicleValuationData(FinanceFixtures::date($valuation[0]), $valuation[1], null, null),
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );

        return AgreementFigures::of($agreement, Schedule::of($agreement, [], $day), $quotes, $value, $day, 'GBP');
    }

    private function builder(): FinanceWorking
    {
        return new FinanceWorking(WorkingWords::translator(), WorkingWords::formatter());
    }
}
