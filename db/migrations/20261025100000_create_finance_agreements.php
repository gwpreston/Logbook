<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Finance and lease agreements (spec.md §6 FinanceAgreement,
 * FinancePaymentEvent, SettlementQuote; §7.32, Phase 29.1).
 *
 * - `finance_agreements`: an HP, PCP, loan or lease agreement as typed
 *   from the paperwork. Money is DECIMAL(14,3) in the vehicle's currency,
 *   as every other money column; dates are calendar dates. The schedule
 *   is derived from these figures on every read and never stored. "At
 *   most one active agreement per vehicle" is checked by the service: a
 *   partial unique index isn't portable.
 * - `finance_payment_events`: the exceptions to "every payment is paid on
 *   its due date" (missed, paid late) and the payments outside the
 *   schedule (extra, settlement).
 * - `settlement_quotes`: the lender's own settlement figures.
 *
 * All three go with their vehicle (CASCADE). Rolling back drops them.
 */
final class CreateFinanceAgreements extends AbstractMigration
{
    public function up(): void
    {
        $money = ['precision' => 14, 'scale' => 3, 'null' => true];
        $this->table('finance_agreements')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('type', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('lender', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('agreement_number', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 12, 'null' => false, 'default' => 'active'])
            ->addColumn('started_on', 'date', ['null' => false])
            ->addColumn('first_payment_on', 'date', ['null' => false])
            ->addColumn('number_of_payments', 'integer', ['null' => false])
            ->addColumn('regular_payment', 'decimal', ['null' => false] + $money)
            ->addColumn('first_payment', 'decimal', $money)
            ->addColumn('final_payment', 'decimal', $money)
            ->addColumn('final_payment_on', 'date', ['null' => true])
            ->addColumn('cash_price', 'decimal', $money)
            ->addColumn('customer_deposit', 'decimal', ['null' => false, 'default' => 0] + $money)
            ->addColumn('dealer_contribution', 'decimal', ['null' => false, 'default' => 0] + $money)
            ->addColumn('initial_rental', 'decimal', $money)
            ->addColumn('amount_of_credit', 'decimal', $money)
            ->addColumn('total_amount_payable', 'decimal', $money)
            ->addColumn('apr', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => false, 'default' => 0])
            ->addColumn('documentation_fee', 'decimal', $money)
            ->addColumn('option_to_purchase_fee', 'decimal', $money)
            ->addColumn('annual_mileage_allowance', 'integer', ['null' => true])
            ->addColumn('mileage_unit', 'string', ['limit' => 2, 'null' => false, 'default' => 'mi'])
            ->addColumn('excess_mileage_charge', 'decimal', ['precision' => 10, 'scale' => 4, 'null' => true])
            ->addColumn('start_odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('count_in_costs', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('ended_on', 'date', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'status'], ['name' => 'finance_agreements_vehicle_status_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'finance_agreements_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'finance_agreements_created_by_fk',
            ])
            ->create();

        $this->table('finance_payment_events')
            ->addColumn('agreement_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('due_on', 'date', ['null' => true])
            ->addColumn('kind', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => true])
            ->addColumn('paid_on', 'date', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['agreement_id'], ['name' => 'finance_payment_events_agreement_idx'])
            ->addForeignKey('agreement_id', 'finance_agreements', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'finance_payment_events_agreement_fk',
            ])
            ->create();

        $this->table('settlement_quotes')
            ->addColumn('agreement_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('quoted_on', 'date', ['null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('valid_until', 'date', ['null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['agreement_id'], ['name' => 'settlement_quotes_agreement_idx'])
            ->addForeignKey('agreement_id', 'finance_agreements', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'settlement_quotes_agreement_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('settlement_quotes')->drop()->save();
        $this->table('finance_payment_events')->drop()->save();
        $this->table('finance_agreements')->drop()->save();
    }
}
