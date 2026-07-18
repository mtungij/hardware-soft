<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase five pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $sale = Sale::firstOrFail();

    $this->actingAs($admin)->get('/pos')->assertOk()->assertSee('POS Sales');
    $this->actingAs($admin)->get('/sales')->assertOk()->assertSee('Sales');
    $this->actingAs($admin)->get("/sales/{$sale->id}")->assertOk()->assertSee('Sale Details');
    $this->actingAs($admin)->get("/sales/{$sale->id}/receipt")->assertOk()->assertSee('Receipt');
    $this->actingAs($admin)->get("/sales/{$sale->id}/payments")->assertOk()->assertSee('Receive Sale Payment');
    $this->actingAs($admin)->get("/sales/{$sale->id}/cancel")->assertOk()->assertSee('Cancel Sale');
});

test('seeded sale creates sale out movement', function () {
    $sale = Sale::where('sale_number', 'SALE-SEED-0001')->firstOrFail();

    expect($sale->status)->toBe('completed');
    expect(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'sale_out')->count())->toBeGreaterThan(0);
});

test('completing cash sale reduces dispensing stock', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $inventory = app(InventoryService::class);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::query()->get()->first(fn ($product) => $inventory->getProductStock($product->id, $dispensing->id, $branch->id) >= 1);

    expect($product)->not->toBeNull();

    $before = $inventory->getProductStock($product->id, $dispensing->id, $branch->id);
    $total = (float) $product->selling_price;

    $sale = $inventory->completeSale(
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->selling_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => $total, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->payment_status)->toBe('paid');
    expect($inventory->getProductStock($product->id, $dispensing->id, $branch->id))->toEqual($before - 1);
});

test('cashier wholesale sale stores sale type sold by unit price and line total', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');
    $inventory = app(InventoryService::class);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::firstOrFail();
    $product->update([
        'buying_price' => 15000,
        'selling_price' => 19000,
        'wholesale_price' => 17500,
    ]);

    StockMovement::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $dispensing->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 25,
        'unit_cost' => 15000,
        'unit_price' => 17500,
        'notes' => 'Wholesale test stock',
        'created_by' => $cashier->id,
        'movement_date' => today(),
    ]);

    $this->actingAs($cashier);

    $sale = $inventory->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'wholesale',
            'quantity' => 20,
            'unit_price' => $product->wholesale_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 350000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $cashier->id,
    );

    $item = $sale->items()->firstOrFail();

    expect($sale->sale_type)->toBe('wholesale');
    expect($sale->sold_by)->toBe($cashier->id);
    expect($sale->created_at)->not->toBeNull();
    expect($item->sale_type)->toBe('wholesale');
    expect((float) $item->unit_price)->toBe(17500.0);
    expect((float) $item->line_total)->toBe(350000.0);
});

test('cashier can see wholesale sale type option on pos', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)
        ->get('/pos')
        ->assertOk()
        ->assertSee('Aina ya Bei')
        ->assertSee('Wholesale');
});

test('sale cannot exceed available stock', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $inventory = app(InventoryService::class);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::firstOrFail();
    $available = $inventory->getProductStock($product->id, $dispensing->id, $branch->id);

    $inventory->completeSale(
        [[
            'product_id' => $product->id,
            'quantity' => $available + 1,
            'unit_price' => $product->selling_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => $product->selling_price, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('cancelling sale returns stock', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $inventory = app(InventoryService::class);
    $sale = Sale::where('status', 'completed')->with('items')->firstOrFail();
    $item = $sale->items->first();
    $before = $inventory->getProductStock($item->product_id, $item->stock_location_id, $branch->id);

    $inventory->cancelSale($sale->id, $admin->id);

    expect($sale->fresh()->status)->toBe('cancelled');
    expect($inventory->getProductStock($item->product_id, $item->stock_location_id, $branch->id))->toEqual($before + (float) $item->quantity);
    expect(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'return_in')->exists())->toBeTrue();
});

test('registered customer credit sale updates balance without checking credit limit', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario();
    $customer->update(['credit_limit' => 1, 'balance_amount' => 1000000]);
    $beforeBalance = (float) $customer->balance_amount;

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->selling_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'credit', 'amount' => $product->selling_price, 'reference_number' => null]],
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->payment_status)->toBe('unpaid');
    expect((float) $customer->fresh()->balance_amount)->toEqual($beforeBalance + (float) $sale->balance_amount);
});

function posCreditScenario(): array
{
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $dispensing = StockLocation::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'POS Credit Test Location',
        'code' => 'POS-CREDIT-'.uniqid(),
        'type' => 'dispensing',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
    ]);
    $product = Product::firstOrFail();
    $product->update([
        'buying_price' => 10,
        'selling_price' => 100,
        'wholesale_price' => 90,
    ]);
    $customer = Customer::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Limit Test Customer',
        'phone' => '+255700000099',
        'email' => fake()->unique()->safeEmail(),
        'customer_type' => 'credit',
        'credit_limit' => 50,
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);

    Setting::query()->firstOrFail()->update([
        'allow_credit_sale_without_customer' => true,
        'inventory_mode' => 'multi_location',
        'enable_warehouse' => true,
        'allow_direct_stock_in' => false,
        'allow_sales_from_store' => true,
        'default_stock_location_id' => $dispensing->id,
    ]);

    StockMovement::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $dispensing->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 5,
        'quantity_in' => 5,
        'quantity_out' => 0,
        'unit_cost' => 10,
        'unit_price' => 100,
        'created_by' => $admin->id,
        'movement_date' => today(),
    ]);

    return [$admin, $branch, $dispensing, $product, $customer];
}

test('credit sale without customer shows unassigned confirmation', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('customer_id', '')
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '1',
            'unit_price' => '100',
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]])
        ->set('payments', [['payment_method' => 'credit', 'amount' => '100', 'reference_number' => '']])
        ->call('completeSale')
        ->assertDispatched('open-modal')
        ->assertSee('Customer Not Selected')
        ->assertSee('Continue Without Customer');
});

test('continue without customer completes sale with system customer', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('customer_id', '')
        ->set('temporary_customer_name', 'Site Foreman')
        ->set('temporary_customer_phone', '+255700111222')
        ->set('project_name', 'Warehouse Extension')
        ->set('vehicle_number', 'T123 ABC')
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '1',
            'unit_price' => '100',
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]])
        ->set('payments', [['payment_method' => 'credit', 'amount' => '100', 'reference_number' => '']])
        ->call('continueWithoutCustomer');

    $sale = Sale::query()->latest('id')->firstOrFail();
    $systemCustomer = Customer::withoutGlobalScopes()->where('company_id', $branch->company_id)->where('is_unassigned_credit_customer', true)->firstOrFail();

    expect($sale->customer_id)->toBe($systemCustomer->id);
    expect($sale->credit_customer_unassigned)->toBeTrue();
    expect($sale->credit_assignment_status)->toBe('unassigned');
    expect($sale->temporary_customer_name)->toBe('Site Foreman');
    expect($sale->temporary_customer_phone)->toBe('+255700111222');
    expect((float) $systemCustomer->fresh()->balance_amount)->toBe(100.0);
    expect(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'sale_out')->exists())->toBeTrue();
});

test('company setting can require selecting a customer for credit sale', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();

    Setting::query()->firstOrFail()->update(['allow_credit_sale_without_customer' => false]);

    app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'credit', 'amount' => 100, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('partial credit sale calculates outstanding amount', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario();

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [
            ['payment_method' => 'cash', 'amount' => 30, 'reference_number' => null],
            ['payment_method' => 'credit', 'amount' => 70, 'reference_number' => null],
        ],
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->payment_status)->toBe('partial');
    expect((float) $sale->paid_amount)->toBe(30.0);
    expect((float) $sale->balance_amount)->toBe(70.0);
});

test('pos product discount is applied per unit', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();

    $product->update([
        'buying_price' => 10000,
        'selling_price' => 15000,
    ]);

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 4,
            'unit_price' => 15000,
            'discount_amount' => 500,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 58000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    $item = $sale->items()->firstOrFail();

    expect((float) $sale->subtotal)->toBe(60000.0);
    expect((float) $sale->discount_amount)->toBe(2000.0);
    expect((float) $sale->total_amount)->toBe(58000.0);
    expect((float) $item->discount_per_unit)->toBe(500.0);
    expect((float) $item->discount_total)->toBe(2000.0);
    expect((float) $item->gross_total)->toBe(60000.0);
    expect((float) $item->net_unit_price)->toBe(14500.0);
    expect((float) $item->net_total)->toBe(58000.0);
    expect((float) $item->line_total)->toBe(58000.0);
});

test('pos paid amount auto updates when discount changes', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();
    $product->update(['buying_price' => 10000, 'selling_price' => 15000]);

    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '4',
            'unit_price' => '15000',
            'discount_amount' => '500',
            'tax_amount' => '0',
        ]])
        ->set('payments', [['payment_method' => 'cash', 'amount' => '60000', 'reference_number' => '']])
        ->set('auto_payment_amount', '60000')
        ->set('payment_amount_manually_edited', false)
        ->call('syncDefaultPaymentAmount')
        ->assertSet('payments.0.amount', '58000');
});

test('pos preserves manually entered amount received and recalculates change', function () {
    [$admin, $branch, $dispensing, $product] = posCreditScenario();
    $product->update(['buying_price' => 10000, 'selling_price' => 15000]);

    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '4',
            'unit_price' => '15000',
            'discount_amount' => '500',
            'tax_amount' => '0',
        ]])
        ->set('auto_payment_amount', '60000')
        ->set('payment_amount_manually_edited', true)
        ->set('payments', [['payment_method' => 'cash', 'amount' => '60000', 'reference_number' => '']])
        ->call('syncDefaultPaymentAmount')
        ->assertSet('payments.0.amount', '60000')
        ->assertSee('TZS 2,000');
});

function fractionalPipeScenario(): array
{
    [$admin, $branch, $dispensing] = posCreditScenario();
    $piece = Unit::query()->firstOrCreate(['short_name' => 'pc'], ['name' => 'Piece', 'status' => 'active']);
    $metre = Unit::query()->firstOrCreate(['short_name' => 'm'], ['name' => 'Metre', 'status' => 'active']);
    $product = Product::firstOrFail();
    $product->category->update(['allow_fractional_sales' => false]);
    $product->update([
        'unit_id' => $piece->id,
        'selling_unit_id' => $metre->id,
        'conversion_factor' => 3,
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
        'buying_price' => 20000,
        'selling_price' => 10000,
        'wholesale_price' => 9000,
    ]);

    StockMovement::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $dispensing->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 8,
        'quantity_in' => 8,
        'quantity_out' => 0,
        'unit_cost' => 20000,
        'unit_price' => 10000,
        'created_by' => $admin->id,
        'movement_date' => today(),
    ]);

    return [$admin, $branch, $dispensing, $product, $piece, $metre];
}

function categoryFractionalPipeScenario(bool $categoryAllowsFractional = true, bool $productAllowsFractional = false): array
{
    [$admin, $branch, $dispensing] = posCreditScenario();
    $piece = Unit::query()->firstOrCreate(['short_name' => 'pc'], ['name' => 'Piece', 'status' => 'active']);
    $metre = Unit::query()->firstOrCreate(['short_name' => 'm'], ['name' => 'Metre', 'status' => 'active']);
    $product = Product::with('category')->firstOrFail();
    $product->category->update([
        'allow_fractional_sales' => $categoryAllowsFractional,
    ]);
    $product->update([
        'unit_id' => $piece->id,
        'selling_unit_id' => $metre->id,
        'conversion_factor' => 3,
        'allow_fractional_sale' => $productAllowsFractional,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
        'buying_price' => 20000,
        'selling_price' => 10000,
        'wholesale_price' => 9000,
    ]);

    StockMovement::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $dispensing->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 8,
        'quantity_in' => 8,
        'quantity_out' => 0,
        'unit_cost' => 20000,
        'unit_price' => 10000,
        'created_by' => $admin->id,
        'movement_date' => today(),
    ]);

    return [$admin, $branch, $dispensing, $product, $piece, $metre];
}

test('fractional pipe sale deducts converted base stock and calculates price', function () {
    [$admin, $branch, $dispensing, $product, $piece, $metre] = fractionalPipeScenario();
    $startingStock = app(InventoryService::class)->getProductStock($product->id, $dispensing->id, $branch->id);

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1.5,
            'unit_price' => 10000,
            'discount_amount' => 500,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 14250, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    $item = $sale->items()->firstOrFail();
    $movement = StockMovement::query()
        ->where('reference_type', Sale::class)
        ->where('reference_id', $sale->id)
        ->where('movement_type', 'sale_out')
        ->firstOrFail();

    expect((float) $sale->subtotal)->toBe(15000.0);
    expect((float) $sale->discount_amount)->toBe(750.0);
    expect((float) $sale->total_amount)->toBe(14250.0);
    expect((float) $item->quantity)->toBe(1.5);
    expect((float) $item->base_quantity)->toBe(0.5);
    expect((float) $item->conversion_factor)->toBe(3.0);
    expect($item->selling_unit_id)->toBe($metre->id);
    expect($item->base_unit_id)->toBe($piece->id);
    expect((float) $item->discount_total)->toBe(750.0);
    expect((float) $item->net_total)->toBe(14250.0);
    expect((float) $movement->quantity)->toBe(0.5);
    expect((float) app(InventoryService::class)->getProductStock($product->id, $dispensing->id, $branch->id))->toBe($startingStock - 0.5);
});

test('fractional sale validates whole products minimum and quantity step', function () {
    [$admin, $branch, $dispensing, $product] = fractionalPipeScenario();
    $inventory = app(InventoryService::class);

    $product->update(['allow_fractional_sale' => false]);

    $inventory->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 1.5, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 15000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('fractional sale validates minimum quantity and quantity step', function () {
    [$admin, $branch, $dispensing, $product] = fractionalPipeScenario();
    $inventory = app(InventoryService::class);

    $inventory->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 0.25, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 2500, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('pos displays available fractional stock in selling unit', function () {
    [$admin, $branch, $dispensing, $product] = fractionalPipeScenario();
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->call('addProduct', $product->id)
        ->assertSet('cart.0.quantity', '0.5000')
        ->assertSee('Selling Quantity')
        ->assertSee('Base Quantity Deducted');

    expect(app(InventoryService::class)->getProductStock($product->id, $dispensing->id, $branch->id) * (float) $product->conversion_factor)->toBe(39.0);
});

test('pipe category products display fractional controls in pos', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: true, productAllowsFractional: false);
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->call('addProduct', $product->id)
        ->assertSet('cart.0.allow_fractional_sale', true)
        ->assertSee('Fractional')
        ->assertSee('Available Base Stock')
        ->assertSee('Unit Conversion')
        ->assertSee('Selling Quantity')
        ->assertSee('Base Quantity Deducted');
});

test('non pipe category products use simple cart layout without fractional details', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: false, productAllowsFractional: false);
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->call('addProduct', $product->id)
        ->assertSet('cart.0.allow_fractional_sale', false)
        ->assertSee('TZS 10,000')
        ->assertDontSee('Available Base Stock')
        ->assertDontSee('Unit Conversion')
        ->assertDontSee('Selling Quantity')
        ->assertDontSee('Base Quantity Deducted');
});

test('ordinary product quantity rejects decimal values', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: false, productAllowsFractional: false);

    app(InventoryService::class)->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 1.5, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 15000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('pipe product quantity accepts fractional values from category default', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: true, productAllowsFractional: false);

    $halfMetreSale = app(InventoryService::class)->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 0.5, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 5000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    $onePointFiveMetreSale = app(InventoryService::class)->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 1.5, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 15000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect((float) $halfMetreSale->items()->firstOrFail()->base_quantity)->toBe(0.1667);
    expect((float) $onePointFiveMetreSale->items()->firstOrFail()->base_quantity)->toBe(0.5);
});

test('payment section remains normal for fractional products', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: true, productAllowsFractional: false);
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->call('addProduct', $product->id)
        ->assertSee('TZS 5,000')
        ->assertSee('Change');
});

test('product level fractional setting works when category default is false', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: false, productAllowsFractional: true);
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->call('addProduct', $product->id)
        ->assertSet('cart.0.allow_fractional_sale', true)
        ->assertSee('Base Quantity Deducted');
});

test('existing whole unit sales continue working', function () {
    [$admin, $branch, $dispensing, $product] = categoryFractionalPipeScenario(categoryAllowsFractional: false, productAllowsFractional: false);
    $product->update(['buying_price' => 5000]);

    $sale = app(InventoryService::class)->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 2, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 20000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect((float) $sale->items()->firstOrFail()->quantity)->toBe(2.0);
});

test('fractional return restores converted base quantity', function () {
    [$admin, $branch, $dispensing, $product] = fractionalPipeScenario();
    $inventory = app(InventoryService::class);
    $startingStock = $inventory->getProductStock($product->id, $dispensing->id, $branch->id);

    $sale = $inventory->completeSale(
        [['product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 1.5, 'unit_price' => 10000, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 15000, 'reference_number' => null]],
        null,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    $inventory->cancelSale($sale->id, $admin->id);

    $return = StockMovement::query()
        ->where('reference_type', Sale::class)
        ->where('reference_id', $sale->id)
        ->where('movement_type', 'return_in')
        ->firstOrFail();

    expect((float) $return->quantity)->toBe(0.5);
    expect((float) $inventory->getProductStock($product->id, $dispensing->id, $branch->id))->toBe($startingStock);
});

test('cashier can access pos but cannot open cancel page', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');
    $sale = Sale::firstOrFail();

    $this->actingAs($cashier)->get('/pos')->assertOk();
    $this->actingAs($cashier)->get('/sales')->assertOk();
    $this->actingAs($cashier)->get("/sales/{$sale->id}/cancel")->assertForbidden();
});
