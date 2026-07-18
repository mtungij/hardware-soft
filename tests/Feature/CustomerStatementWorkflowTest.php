<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccountingService;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function customerStatementScenario(): array
{
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $location = StockLocation::query()->where('branch_id', $branch->id)->firstOrFail();
    $unit = Unit::query()->firstOrCreate(['short_name' => 'bag'], ['name' => 'Bag', 'status' => 'active']);
    $category = Category::query()->firstOrCreate(
        ['company_id' => $branch->company_id, 'code' => 'TST-CREDIT'],
        ['branch_id' => $branch->id, 'name' => 'Credit Test', 'description' => 'Credit test category', 'status' => 'active']
    );
    $product = Product::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'selling_unit_id' => $unit->id,
        'name' => 'Statement Cement',
        'sku' => 'STMT-CEM',
        'buying_price' => 10000,
        'selling_price' => 15000,
        'conversion_factor' => 1,
        'allow_fractional_sale' => false,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'reorder_level' => 0,
        'status' => 'active',
    ]);
    $customer = Customer::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Statement Customer',
        'phone' => '0712000000',
        'customer_type' => 'credit',
        'credit_limit' => 500000,
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);
    $sale = Sale::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'sale_number' => 'SALE-STMT-001',
        'sale_date' => today(),
        'sale_type' => 'retail',
        'subtotal' => 60000,
        'discount_amount' => 2000,
        'tax_amount' => 0,
        'total_amount' => 58000,
        'paid_amount' => 0,
        'balance_amount' => 58000,
        'change_amount' => 0,
        'payment_status' => 'unpaid',
        'status' => 'completed',
        'created_by' => $admin->id,
        'sold_by' => $admin->id,
    ]);
    SaleItem::query()->create([
        'company_id' => $branch->company_id,
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'selling_unit_id' => $unit->id,
        'base_unit_id' => $unit->id,
        'conversion_factor' => 1,
        'sale_type' => 'retail',
        'quantity' => 4,
        'base_quantity' => 4,
        'unit_cost' => 10000,
        'unit_price' => 15000,
        'discount_per_unit' => 500,
        'discount_amount' => 2000,
        'discount_total' => 2000,
        'gross_total' => 60000,
        'net_unit_price' => 14500,
        'net_total' => 58000,
        'tax_amount' => 0,
        'line_total' => 58000,
    ]);

    return [$admin, $branch, $customer, $sale, $product];
}

test('customer statement shows credit sale products and payment ledger', function () {
    [$admin, $branch, $customer] = customerStatementScenario();

    app(AccountingService::class)->receiveCustomerPayment($customer, [
        'branch_id' => $branch->id,
        'amount' => 20000,
        'payment_method' => 'cash',
        'reference_number' => 'RCPT-1',
        'payment_date' => today()->toDateString(),
        'notes' => 'First payment',
    ], $admin->id);

    $this->actingAs($admin)
        ->get(route('customer-balances.show', $customer))
        ->assertOk()
        ->assertSee('Statement Cement')
        ->assertSee('SALE-STMT-001')
        ->assertSee('Discount / Unit')
        ->assertSee('Line Total')
        ->assertSee('Payment')
        ->assertSee('Running Balance');
});

test('customer payment allocation is linked to the paid sale', function () {
    [$admin, $branch, $customer, $sale] = customerStatementScenario();

    $payment = app(AccountingService::class)->receiveCustomerPayment($customer, [
        'branch_id' => $branch->id,
        'amount' => 30000,
        'payment_method' => 'cash',
        'reference_number' => 'RCPT-2',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);

    $allocation = CustomerPaymentAllocation::query()->where('customer_payment_id', $payment->id)->firstOrFail();

    expect($allocation->sale_id)->toBe($sale->id);
    expect((float) $allocation->allocated_amount)->toBe(30000.0);
});

test('daily customer payment report shows method totals', function () {
    [$admin, $branch, $customer] = customerStatementScenario();

    app(AccountingService::class)->receiveCustomerPayment($customer, [
        'branch_id' => $branch->id,
        'amount' => 15000,
        'payment_method' => 'cash',
        'reference_number' => 'RCPT-3',
        'payment_date' => today()->toDateString(),
        'notes' => null,
    ], $admin->id);

    $this->actingAs($admin)
        ->get(route('reports.customer-payments'))
        ->assertOk()
        ->assertSee('Customer Payment Report')
        ->assertSee('Statement Customer')
        ->assertSee('TZS 15,000');
});
