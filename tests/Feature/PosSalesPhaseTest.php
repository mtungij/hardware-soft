<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

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

test('cashier can access pos but cannot open cancel page', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');
    $sale = Sale::firstOrFail();

    $this->actingAs($cashier)->get('/pos')->assertOk();
    $this->actingAs($cashier)->get('/sales')->assertOk();
    $this->actingAs($cashier)->get("/sales/{$sale->id}/cancel")->assertForbidden();
});
