<?php

use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase three pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::firstOrFail();

    $this->actingAs($admin)->get('/purchases')->assertOk()->assertSee('Purchases');
    $this->actingAs($admin)->get('/purchases/create')->assertOk()->assertSee('Create Purchase');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}")->assertOk()->assertSee('Purchase Details');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}/receive")->assertOk()->assertSee('Receive Purchase');
    $this->actingAs($admin)->get('/store-stock')->assertOk()->assertSee('Main Store Stock');
    $this->actingAs($admin)->get('/stock-movements')->assertOk()->assertSee('Stock Movements');
    $this->actingAs($admin)->get('/stock-adjustments')->assertOk()->assertSee('Stock Adjustments');
    $this->actingAs($admin)->get('/stock-adjustments/create')->assertOk()->assertSee('Create Stock Adjustment');
    $this->actingAs($admin)->get('/stock-adjustments/approve')->assertOk()->assertSee('Approve Stock Adjustments');
});

test('stock locations and sample purchase movements are seeded', function () {
    expect(StockLocation::where('code', 'MAIN-STORE')->where('type', 'store')->exists())->toBeTrue();
    expect(StockLocation::where('code', 'DISPENSING')->where('type', 'dispensing')->exists())->toBeTrue();
    expect(Purchase::where('reference_number', 'PO-SEED-0001')->exists())->toBeTrue();
    expect(StockMovement::where('movement_type', 'purchase_in')->exists())->toBeTrue();
});

test('purchase create keeps selected product after supplier is selected', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();
    $supplier = Supplier::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $admin->branch_id,
        'name' => 'Test Supplier',
        'phone' => '+255 700 333 333',
        'status' => 'active',
    ]);

    $this->actingAs($admin);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->assertSet('items.0.product_id', (string) $product->id)
        ->assertSet('items.0.selling_price', (string) $product->selling_price);
});

test('super admin can update product selling price from purchase create', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();
    $supplier = Supplier::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $admin->branch_id,
        'name' => 'Admin Price Supplier',
        'phone' => '+255 700 444 444',
        'status' => 'active',
    ]);

    $this->actingAs($admin);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '1')
        ->set('items.0.cost_price', '1000')
        ->set('items.0.selling_price', '99999')
        ->set('reference_number', 'PO-ADMIN-PRICE')
        ->call('savePurchase', 'draft');

    expect((float) $product->refresh()->selling_price)->toBe(99999.0);
});

test('non admin cannot update product selling price from purchase create', function () {
    $product = Product::firstOrFail();
    $originalSellingPrice = (float) $product->selling_price;
    $branch = \App\Models\Branch::firstOrFail();
    $storeKeeper = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $storeKeeper->assignRole('Store Keeper');

    $supplier = Supplier::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Store Keeper Price Supplier',
        'phone' => '+255 700 555 555',
        'status' => 'active',
    ]);

    $this->actingAs($storeKeeper);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '1')
        ->set('items.0.cost_price', '1000')
        ->set('items.0.selling_price', '99999')
        ->set('reference_number', 'PO-STORE-PRICE')
        ->call('savePurchase', 'draft');

    $purchaseItem = Purchase::query()
        ->where('reference_number', 'PO-STORE-PRICE')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    expect((float) $product->refresh()->selling_price)->toBe($originalSellingPrice);
    expect((float) $purchaseItem->selling_price)->toBe($originalSellingPrice);
});

test('receiving purchase creates grn and purchase in movements', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::where('status', 'ordered')->firstOrFail();
    $item = $purchase->items()->firstOrFail();
    $remaining = $item->remainingQuantity();

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => $remaining],
        now()->toDateString(),
        $admin->id,
        'Test receiving'
    );

    expect(GoodsReceivingNote::where('purchase_id', $purchase->id)->count())->toBeGreaterThan(0);
    expect(StockMovement::where('reference_type', GoodsReceivingNote::class)->where('movement_type', 'purchase_in')->count())->toBeGreaterThan(0);
    expect($item->refresh()->received_quantity)->toEqual($item->ordered_quantity);
});

test('receiving cannot exceed remaining quantity', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::where('status', 'ordered')->firstOrFail();
    $item = $purchase->items()->firstOrFail();

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => $item->remainingQuantity() + 1],
        now()->toDateString(),
        $admin->id
    );
})->throws(ValidationException::class);

test('cashier can view store stock only from phase three pages', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)->get('/store-stock')->assertOk();
    $this->actingAs($cashier)->get('/purchases')->assertForbidden();
    $this->actingAs($cashier)->get('/stock-movements')->assertForbidden();
    $this->actingAs($cashier)->get('/stock-adjustments')->assertForbidden();
});
