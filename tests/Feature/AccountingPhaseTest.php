<?php

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CashbookService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase six accounting pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $customer = Customer::firstOrFail();
    $supplier = Supplier::firstOrFail();
    $cashbook = CashbookSession::firstOrFail();

    $this->actingAs($admin)->get('/expenses')->assertOk()->assertSee('Expenses');
    $this->actingAs($admin)->get('/expense-categories')->assertOk()->assertSee('Expense Categories');
    $this->actingAs($admin)->get('/customer-balances')->assertOk()->assertSee('Customer Balances');
    $this->actingAs($admin)->get("/customer-balances/{$customer->id}")->assertOk()->assertSee('Customer Statement');
    $this->actingAs($admin)->get('/customer-payments/create')->assertOk()->assertSee('Record Customer Payment');
    $this->actingAs($admin)->get('/supplier-balances')->assertOk()->assertSee('Supplier Balances');
    $this->actingAs($admin)->get("/supplier-balances/{$supplier->id}")->assertOk()->assertSee('Supplier Statement');
    $this->actingAs($admin)->get('/supplier-payments/create')->assertOk()->assertSee('Pay Supplier');
    $this->actingAs($admin)->get('/cashbook')->assertOk()->assertSee('Cashbook');
    $this->actingAs($admin)->get("/cashbook/{$cashbook->id}")->assertOk()->assertSee('Cashbook Session');
});

test('phase six report pages render for accountant', function () {
    $accountant = User::factory()->create(['status' => 'active']);
    $accountant->assignRole('Accountant');

    foreach (['sales', 'purchases', 'expenses', 'customers', 'suppliers', 'stock-valuation', 'profit-loss', 'cashbook'] as $report) {
        $this->actingAs($accountant)->get("/reports/{$report}")->assertOk();
    }
});

test('expense categories and expenses are seeded', function () {
    expect(ExpenseCategory::where('name', 'Rent')->exists())->toBeTrue();
    expect(Expense::where('reference_number', 'EXP-SEED-0001')->exists())->toBeTrue();
});

test('customer payment reduces credit balance and prevents overpayment', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $customer = Customer::where('credit_limit', '>', 0)->firstOrFail();
    $inventory = app(InventoryService::class);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::query()->get()->first(fn ($product) => $inventory->getProductStock($product->id, $dispensing->id, $branch->id) >= 1);

    $inventory->completeSale(
        [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'credit', 'amount' => 10000, 'reference_number' => null]],
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    $accounting = app(AccountingService::class);
    expect($accounting->customerBalance($customer->fresh()))->toEqual(10000.0);

    $accounting->receiveCustomerPayment($customer, [
        'branch_id' => $branch->id,
        'amount' => 4000,
        'payment_method' => 'cash',
        'reference_number' => 'TEST-CPAY',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);

    expect($accounting->customerBalance($customer->fresh()))->toEqual(6000.0);

    $accounting->receiveCustomerPayment($customer->fresh(), [
        'branch_id' => $branch->id,
        'amount' => 7000,
        'payment_method' => 'cash',
        'reference_number' => 'TEST-CPAY-OVER',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);
})->throws(ValidationException::class);

test('supplier payment reduces supplier balance and prevents overpayment', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $supplier = Supplier::firstOrFail();
    $accounting = app(AccountingService::class);
    $before = $accounting->supplierBalance($supplier);

    expect($before)->toBeGreaterThan(0);

    $accounting->paySupplier($supplier, [
        'branch_id' => $branch->id,
        'amount' => 5000,
        'payment_method' => 'cash',
        'reference_number' => 'TEST-SPAY',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);

    expect($accounting->supplierBalance($supplier->fresh()))->toEqual($before - 5000);

    $accounting->paySupplier($supplier->fresh(), [
        'branch_id' => $branch->id,
        'amount' => $before,
        'payment_method' => 'cash',
        'reference_number' => 'TEST-SPAY-OVER',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);
})->throws(ValidationException::class);

test('cashbook enforces one open session and closes with difference', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $service = app(CashbookService::class);
    $date = today()->addDay()->toDateString();

    $session = $service->openSession($branch->id, $date, 50000, $admin->id);
    expect($session->status)->toBe('open');

    $closed = $service->closeSession($session, 51000, $admin->id);
    expect($closed->status)->toBe('closed');
    expect((float) $closed->difference)->toEqual(1000.0);

    $service->openSession($branch->id, $date, 50000, $admin->id);
    $service->openSession($branch->id, $date, 50000, $admin->id);
})->throws(ValidationException::class);

test('cashier can view customer balances and cashbook but not expenses', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)->get('/customer-balances')->assertOk();
    $this->actingAs($cashier)->get('/cashbook')->assertOk();
    $this->actingAs($cashier)->get('/expenses')->assertForbidden();
});
