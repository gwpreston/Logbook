<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Finance;

use Logbook\Domain\Finance\AgreementType;
use Logbook\Service\Finance\FinanceAgreementForm;
use Logbook\Service\Finance\FinanceCheck;
use Logbook\Service\Finance\FinanceInput;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use PHPUnit\Framework\TestCase;

/**
 * The agreement form and its consistency check (spec.md §7.32 *Form*).
 */
final class FinanceAgreementFormTest extends TestCase
{
    /** A PCP as the paperwork gives it. */
    private const array PCP = [
        'type' => 'pcp',
        'lender' => 'Toyota Financial Services',
        'agreement_number' => 'PCP-0012345678',
        'started_on' => '2024-12-31',
        'first_payment_on' => '2025-01-31',
        'number_of_payments' => '36',
        'regular_payment' => '250',
        'final_payment' => '8000',
        'cash_price' => '20000',
        'customer_deposit' => '2000',
        'dealer_contribution' => '1000',
        'apr' => '0',
        'annual_mileage_allowance' => '8000',
        'mileage_unit' => 'mi',
        'excess_mileage_charge' => '0.09',
        'count_in_costs' => '1',
    ];

    public function testAPcpFromItsPaperwork(): void
    {
        $input = $this->parse(self::PCP);

        self::assertInstanceOf(FinanceInput::class, $input);
        $data = $input->data;
        self::assertSame(AgreementType::Pcp, $data->type);
        self::assertSame('PCP-0012345678', $data->agreementNumber);
        self::assertSame(36, $data->numberOfPayments);
        self::assertSame('250.00', $data->regularPayment);
        self::assertSame('8000.00', $data->finalPayment);
        self::assertSame('0.0900', $data->excessMileageCharge);
        self::assertSame(8000, $data->annualMileageAllowance);
        self::assertTrue($data->countInCosts);
        self::assertSame([], FinanceCheck::of($data), 'no total entered: nothing to compare');
    }

    public function testZeroIsAValidAmountAndAprButNegativesAreNot(): void
    {
        $input = $this->parse(['customer_deposit' => '0', 'apr' => '0', 'regular_payment' => '0'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $input);

        $errors = $this->parse(['regular_payment' => '-1'] + self::PCP);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('regular_payment'));
    }

    public function testRequiredFieldsByType(): void
    {
        $errors = $this->parse(['cash_price' => ''] + self::PCP);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('cash_price'), 'HP and PCP need the cash price');

        $loan = ['type' => 'loan', 'lender' => 'Bank', 'started_on' => '2025-03-01', 'first_payment_on' => '2025-04-01',
            'number_of_payments' => '36', 'regular_payment' => '245.89'];
        $errors = $this->parse($loan);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('amount_of_credit'), 'a loan needs the amount of credit');

        $errors = $this->parse(['number_of_payments' => '121'] + self::PCP);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('number_of_payments'), 'at most 120 monthly payments');
    }

    public function testFieldsOutsideTheTypeAreIgnored(): void
    {
        $lease = ['type' => 'lease', 'lender' => 'Lessor', 'started_on' => '2025-03-10', 'first_payment_on' => '2025-04-10',
            'number_of_payments' => '23', 'regular_payment' => '300', 'initial_rental' => '1500',
            'cash_price' => '30000', 'apr' => '5', 'final_payment' => '9000'];
        $input = $this->parse($lease);

        self::assertInstanceOf(FinanceInput::class, $input);
        self::assertNull($input->data->cashPrice);
        self::assertNull($input->data->finalPayment);
        self::assertSame('0', $input->data->apr);
        self::assertSame('1500.00', $input->data->initialRental);
    }

    public function testDatesInOrder(): void
    {
        $errors = $this->parse(['first_payment_on' => '2024-12-30'] + self::PCP);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('first_payment_on'));

        $errors = $this->parse(['final_payment_on' => '2027-12-31'] + self::PCP);
        self::assertInstanceOf(ValidationErrors::class, $errors);
        self::assertTrue($errors->has('final_payment_on'), 'on the last regular payment');
    }

    public function testConsistencyCheckWarnsAtOneOhOneButNotAtOnePound(): void
    {
        // Deposits 3,000 + 36 × 250 + 8,000 = 20,000.
        $exact = $this->parse(['total_amount_payable' => '20001.00'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $exact);
        self::assertSame([], FinanceCheck::of($exact->data), 'a difference of 1.00 is rounding');

        $off = $this->parse(['total_amount_payable' => '20001.01'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $off, 'never blocks');
        $checks = FinanceCheck::of($off->data);
        self::assertCount(1, $checks);
        self::assertSame('total', $checks[0]->kind);
        self::assertSame('20000.00', $checks[0]->computed);
        self::assertSame('20001.01', $checks[0]->stated);
    }

    public function testConsistencyCheckOfTheAmountOfCredit(): void
    {
        $input = $this->parse(['amount_of_credit' => '16998.99'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $input);
        $checks = FinanceCheck::of($input->data);
        self::assertCount(1, $checks);
        self::assertSame('credit', $checks[0]->kind);
        self::assertSame('17000.00', $checks[0]->computed);

        $input = $this->parse(['amount_of_credit' => '16999.00'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $input);
        self::assertSame([], FinanceCheck::of($input->data));
    }

    public function testPurchasePriceOffersByType(): void
    {
        $input = $this->parse(['set_purchase_price' => '1', 'clear_purchase_price' => '1'] + self::PCP);
        self::assertInstanceOf(FinanceInput::class, $input);
        self::assertTrue($input->setPurchasePrice);
        self::assertFalse($input->clearPurchasePrice, 'only a lease clears it');
    }

    public function testValuesRoundTrip(): void
    {
        $agreement = FinanceFixtures::pcp();
        $values = FinanceAgreementForm::values($agreement, $this->preferences());
        $input = $this->parse($values + ['lender' => 'Lender']);

        self::assertInstanceOf(FinanceInput::class, $input);
        self::assertSame('2025-01-31', $input->data->firstPaymentOn->format('Y-m-d'));
        self::assertSame('8000.00', $input->data->finalPayment);
    }

    /**
     * @param array<string, string> $input
     */
    private function parse(array $input): FinanceInput|ValidationErrors
    {
        return FinanceAgreementForm::parse($input, $this->preferences());
    }

    private function preferences(): DisplayPreferences
    {
        return DisplayPreferences::defaults('en', 'Europe/London', 'GBP');
    }
}
