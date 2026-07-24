<?php

use App\Models\Branch;
use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockAdjustment;
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
    $this->actingAs($admin)->get('/store-stock')->assertOk()->assertSee('Stock by Location');
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
        ->assertSet('items.0.selling_price', (float) $product->selling_price);
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

test('store stock report displays stock by all active locations and grouped view', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::whereKey($admin->branch_id)->firstOrFail();
    $product = Product::firstOrFail();
    $product->update(['name' => 'Multi Location Test Cement', 'reorder_level' => 10]);

    $paintStore = StockLocation::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $branch->id,
        'name' => 'Paint Store Test',
        'code' => 'PAINT-TEST-'.uniqid(),
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'can_receive_stock' => true,
    ]);
    $cementStore = StockLocation::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $branch->id,
        'name' => 'Cement Store Test',
        'code' => 'CEMENT-TEST-'.uniqid(),
        'type' => 'warehouse',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'can_receive_stock' => true,
    ]);

    foreach ([[$paintStore, 25], [$cementStore, 40]] as [$location, $quantity]) {
        StockMovement::query()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock_location_id' => $location->id,
            'movement_type' => 'purchase_in',
            'quantity' => $quantity,
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => 1000,
            'created_by' => $admin->id,
            'movement_date' => today(),
        ]);
    }

    $this->actingAs($admin);

    Volt::test('store-stock.index')
        ->assertSee('Stock by Location')
        ->assertSee('Paint Store Test')
        ->assertSee('Cement Store Test')
        ->assertSee('25')
        ->assertSee('40')
        ->set('groupedView', true)
        ->assertSee('Total');
});

test('store stock report respects location view permissions', function () {
    $branch = Branch::firstOrFail();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');

    $visible = StockLocation::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Visible Stock Room',
        'code' => 'VISIBLE-'.uniqid(),
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
    ]);
    StockLocation::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Hidden Stock Room',
        'code' => 'HIDDEN-'.uniqid(),
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
    ]);

    $cashier->stockLocations()->attach($visible->id, [
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'can_view' => true,
        'can_sell' => false,
        'can_transfer' => false,
        'can_receive' => false,
        'is_default' => false,
    ]);

    $this->actingAs($cashier);

    Volt::test('store-stock.index')
        ->assertSee('Visible Stock Room')
        ->assertDontSee('Hidden Stock Room');
});

test('store stock exports support pdf and excel with filters', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('exports.download', ['export' => 'tables.store-stock', 'format' => 'pdf', 'search' => 'cement']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($admin)
        ->get(route('exports.download', ['export' => 'tables.store-stock', 'format' => 'excel', 'search' => 'cement']))
        ->assertOk();
});

function adjustmentScenario(): array
{
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::whereKey($admin->branch_id)->firstOrFail();
    $product = Product::firstOrFail();
    $mainStore = StockLocation::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $branch->id,
        'name' => 'Adjustment Main Store',
        'code' => 'ADJ-MAIN-'.uniqid(),
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
    ]);
    $dispensing = StockLocation::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $branch->id,
        'name' => 'Adjustment Dispensing',
        'code' => 'ADJ-DISP-'.uniqid(),
        'type' => 'dispensing',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
    ]);

    foreach ([[$mainStore, 100], [$dispensing, 40]] as [$location, $quantity]) {
        StockMovement::query()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock_location_id' => $location->id,
            'movement_type' => 'purchase_in',
            'quantity' => $quantity,
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => 1000,
            'created_by' => $admin->id,
            'movement_date' => today(),
        ]);
    }

    Setting::query()->firstOrFail()->update([
        'inventory_mode' => 'multi_location',
        'stock_adjustment_approval_required' => true,
    ]);

    return [$admin, $branch, $product, $mainStore, $dispensing];
}

test('stock adjustment requires a location in multi location mode', function () {
    [$admin, $branch, $product] = adjustmentScenario();
    $this->actingAs($admin);

    Volt::test('stock-adjustments.create')
        ->set('stock_location_id', '')
        ->set('lines.0.product_id', (string) $product->id)
        ->set('lines.0.physical_quantity', '10')
        ->call('save')
        ->assertHasErrors(['stock_location_id']);
});

test('stock adjustment system quantity is calculated for selected location only', function () {
    [$admin, $branch, $product, $mainStore, $dispensing] = adjustmentScenario();
    $this->actingAs($admin);

    Volt::test('stock-adjustments.create')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('lines.0.product_id', (string) $product->id)
        ->call('refreshLineQuantity', 0)
        ->assertSet('lines.0.system_quantity', '40');
});

test('pending adjustment does not change stock until approval posts movements', function () {
    [$admin, $branch, $product, $mainStore, $dispensing] = adjustmentScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);

    $adjustment = $inventory->createStockAdjustment([
        'branch_id' => $branch->id,
        'stock_location_id' => $dispensing->id,
        'adjustment_date' => today()->toDateString(),
        'reference_number' => 'ADJ-TEST-001',
        'notes' => null,
    ], [
        ['product_id' => $product->id, 'physical_quantity' => 35, 'reason' => 'Physical count difference', 'notes' => null],
    ], $admin->id);

    expect($adjustment->status)->toBe('pending_approval');
    expect($inventory->getProductStock($product->id, $dispensing->id, $branch->id))->toBe(40.0);

    $inventory->approveAdjustment($adjustment, $admin->id);

    expect($inventory->getProductStock($product->id, $dispensing->id, $branch->id))->toBe(35.0);
    expect($inventory->getProductStock($product->id, $mainStore->id, $branch->id))->toBe(100.0);
    expect(StockMovement::where('reference_type', StockAdjustment::class)->where('reference_id', $adjustment->id)->where('movement_type', 'adjustment_out')->where('quantity_out', 5)->exists())->toBeTrue();
});

test('positive adjustment creates in movement and zero difference creates no movement', function () {
    [$admin, $branch, $product, $mainStore, $dispensing] = adjustmentScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);
    $zeroProduct = Product::query()->create([
        'company_id' => $admin->company_id,
        'branch_id' => $branch->id,
        'category_id' => $product->category_id,
        'unit_id' => $product->unit_id,
        'name' => 'Zero Difference Product',
        'sku' => 'ZERO-'.uniqid(),
        'buying_price' => 1000,
        'selling_price' => 1200,
        'reorder_level' => 5,
        'status' => 'active',
    ]);

    $adjustment = $inventory->createStockAdjustment([
        'branch_id' => $branch->id,
        'stock_location_id' => $dispensing->id,
        'adjustment_date' => today()->toDateString(),
        'reference_number' => 'ADJ-TEST-002',
        'notes' => null,
    ], [
        ['product_id' => $product->id, 'physical_quantity' => 46, 'reason' => 'Physical count difference', 'notes' => null],
        ['product_id' => $zeroProduct->id, 'physical_quantity' => 0, 'reason' => 'Physical count difference', 'notes' => null],
    ], $admin->id);

    $inventory->approveAdjustment($adjustment, $admin->id);

    expect(StockMovement::where('reference_type', StockAdjustment::class)->where('reference_id', $adjustment->id)->where('movement_type', 'adjustment_in')->where('quantity_in', 6)->exists())->toBeTrue();
    expect(StockMovement::where('reference_type', StockAdjustment::class)->where('reference_id', $adjustment->id)->count())->toBe(1);
});

test('user cannot adjust unauthorized stock location', function () {
    [$admin, $branch, $product, $mainStore] = adjustmentScenario();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);

    app(InventoryService::class)->createStockAdjustment([
        'branch_id' => $branch->id,
        'stock_location_id' => $mainStore->id,
        'adjustment_date' => today()->toDateString(),
        'reference_number' => 'ADJ-TEST-003',
        'notes' => null,
    ], [
        ['product_id' => $product->id, 'physical_quantity' => 95, 'reason' => 'Physical count difference', 'notes' => null],
    ], $cashier->id);
})->throws(ValidationException::class);
