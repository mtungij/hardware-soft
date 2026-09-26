<?php

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CashbookService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->withSession(['staff_locale' => 'en']);
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $companyId = $branch->company_id;
    $supplier = Supplier::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'name' => 'Accounting Test Supplier', 'phone' => '255700101010', 'status' => 'active']);
    Customer::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'name' => 'Accounting Test Customer', 'phone' => '255700202020', 'customer_type' => 'credit', 'credit_limit' => 100000, 'status' => 'active']);
    Purchase::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'purchase_date' => today(), 'reference_number' => 'ACC-PO-001', 'status' => 'ordered', 'payment_status' => 'unpaid', 'total_amount' => 10000, 'paid_amount' => 0, 'balance_amount' => 10000, 'created_by' => $admin->id]);
    Expense::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'expense_category_id' => ExpenseCategory::where('name', 'Rent')->firstOrFail()->id, 'amount' => 100, 'payment_method' => 'cash', 'reference_number' => 'EXP-SEED-0001', 'expense_date' => today(), 'paid_by' => $admin->id]);
    CashbookSession::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'session_date' => today()->subDay(), 'opening_cash' => 0, 'expected_cash' => 0, 'status' => 'closed', 'opened_by' => $admin->id, 'closed_by' => $admin->id, 'closed_at' => now()]);
    $dispensing = app(InventoryService::class)->getDispensingLocation($branch->id);
    $dispensing->update(['can_sell' => true, 'can_issue_stock' => true, 'is_active' => true, 'status' => 'active']);
    StockMovement::create(['company_id' => $companyId, 'branch_id' => $branch->id, 'product_id' => Product::firstOrFail()->id, 'stock_location_id' => $dispensing->id, 'movement_type' => 'direct_stock_in', 'quantity' => 10, 'quantity_in' => 10, 'quantity_out' => 0, 'unit_cost' => 100, 'created_by' => $admin->id, 'movement_date' => today()]);
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

test('accounting fixtures include an expense category and recorded expense', function () {
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
