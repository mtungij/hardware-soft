<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
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

test('credit sale requires customer and updates balance', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $customer = Customer::where('credit_limit', '>', 0)->firstOrFail();
    $inventory = app(InventoryService::class);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::query()->get()->first(fn ($product) => $inventory->getProductStock($product->id, $dispensing->id, $branch->id) >= 1);
    $beforeBalance = (float) $customer->balance_amount;

    $sale = $inventory->completeSale(
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

function posCreditScenario(string $enforcement = 'warn'): array
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
        'credit_limit_enforcement' => $enforcement,
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

test('credit limit warning appears on pos', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('warn');
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('customer_id', (string) $customer->id)
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
        ->assertSet('credit_limit_warning.customer_name', $customer->name)
        ->assertSee('This customer has exceeded the approved credit limit.');
});

test('user with override permission can continue credit limit sale', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('warn');

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'credit', 'amount' => 100, 'reference_number' => null]],
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
        null,
        true,
    );

    expect($sale->customer_id)->toBe($customer->id);
    expect((float) $customer->fresh()->balance_amount)->toBe(100.0);
});

test('user without override permission cannot continue credit limit warning', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('warn');
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->set('credit_limit_warning', ['customer_name' => $customer->name])
        ->call('continueCreditLimitSale')
        ->assertHasErrors(['customer_id']);
});

test('company setting block sales enforces credit limit', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('block');

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
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('company setting warn only requires authorized credit override', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('warn');

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
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );
})->throws(ValidationException::class);

test('company setting ignore credit limit skips credit validation', function () {
    [$admin, $branch, $dispensing, $product, $customer] = posCreditScenario('ignore');

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'credit', 'amount' => 100, 'reference_number' => null]],
        $customer->id,
        $dispensing->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->customer_id)->toBe($customer->id);
});

test('cashier can access pos but cannot open cancel page', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');
    $sale = Sale::firstOrFail();

    $this->actingAs($cashier)->get('/pos')->assertOk();
    $this->actingAs($cashier)->get('/sales')->assertOk();
    $this->actingAs($cashier)->get("/sales/{$sale->id}/cancel")->assertForbidden();
});
