<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CashbookService;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();
        $admin = User::query()->where('email', 'admin@buildmart.test')->first();

        if (! $branch || ! $admin) {
            return;
        }

        $category = ExpenseCategory::query()->where('name', 'Transport')->first();
        if ($category && ! Expense::query()->where('reference_number', 'EXP-SEED-0001')->exists()) {
            Expense::create([
                'branch_id' => $branch->id,
                'expense_category_id' => $category->id,
                'amount' => 25000,
                'payment_method' => 'cash',
                'reference_number' => 'EXP-SEED-0001',
                'expense_date' => today()->toDateString(),
                'paid_by' => $admin->id,
                'notes' => 'Seed transport expense.',
            ]);
        }

        $accounting = app(AccountingService::class);
        $customer = Customer::query()->where('balance_amount', '>', 0)->first();
        if ($customer && ! $customer->payments()->where('reference_number', 'CPAY-SEED-0001')->exists()) {
            $accounting->receiveCustomerPayment($customer, [
                'branch_id' => $branch->id,
                'amount' => min(5000, $accounting->customerBalance($customer)),
                'payment_method' => 'cash',
                'reference_number' => 'CPAY-SEED-0001',
                'payment_date' => today()->toDateString(),
                'notes' => 'Seed customer payment.',
            ], $admin->id);
        }

        $supplier = Supplier::query()->first();
        if ($supplier && ! $supplier->payments()->where('reference_number', 'SPAY-SEED-0001')->exists() && $accounting->supplierBalance($supplier) > 0) {
            $accounting->paySupplier($supplier, [
                'branch_id' => $branch->id,
                'amount' => min(10000, $accounting->supplierBalance($supplier)),
                'payment_method' => 'cash',
                'reference_number' => 'SPAY-SEED-0001',
                'payment_date' => today()->toDateString(),
                'notes' => 'Seed supplier payment.',
            ], $admin->id);
        }

        if (! CashbookSession::query()->where('branch_id', $branch->id)->whereDate('session_date', today())->exists()) {
            app(CashbookService::class)->openSession($branch->id, today()->toDateString(), 100000, $admin->id, 'Seed daily cashbook session.');
        }
    }
}
