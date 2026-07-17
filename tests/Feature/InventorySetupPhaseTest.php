<?php

use App\Models\Category;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AutoPartsCategorySeeder;
use Database\Seeders\AutoPartsProductSeeder;
use Database\Seeders\AutoPartsUnitSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\HardwareCategorySeeder;
use Database\Seeders\HardwareProductSeeder;
use Database\Seeders\HardwareUnitSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function enableDirectInventoryModeForBranch(int $branchId): StockLocation
{
    $dispensing = StockLocation::query()
        ->where('branch_id', $branchId)
        ->where('type', 'dispensing')
        ->firstOrFail();

    $dispensing->forceFill(['status' => 'active', 'is_active' => true, 'can_sell' => true])->save();

    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => false,
        'allow_direct_stock_in' => true,
        'allow_sales_from_store' => false,
        'inventory_mode' => 'single_location',
        'default_stock_location_id' => $dispensing->id,
    ]);

    StockLocation::query()
        ->where('branch_id', $branchId)
        ->where('type', 'store')
        ->update(['status' => 'inactive']);

    return $dispensing;
}

function enableWarehouseInventoryModeForBranch(int $branchId): array
{
    $store = StockLocation::query()
        ->where('branch_id', $branchId)
        ->where('type', 'store')
        ->firstOrFail();
    $dispensing = StockLocation::query()
        ->where('branch_id', $branchId)
        ->where('type', 'dispensing')
        ->firstOrFail();

    $store->forceFill(['status' => 'active', 'is_active' => true, 'can_sell' => true])->save();
    $dispensing->forceFill(['status' => 'active', 'is_active' => true, 'can_sell' => true])->save();

    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true,
        'allow_direct_stock_in' => false,
        'allow_sales_from_store' => true,
        'inventory_mode' => 'multi_location',
        'default_stock_location_id' => $store->id,
    ]);

    return [$store, $dispensing];
}

test('inventory setup pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();

    $this->actingAs($admin)->get('/categories')->assertOk();
    $this->actingAs($admin)->get('/units')->assertOk();
    $this->actingAs($admin)->get('/products')->assertOk();
    $this->actingAs($admin)->get('/products/create')->assertOk();
    $this->actingAs($admin)->get("/products/{$product->id}/edit")->assertOk();
    $this->actingAs($admin)->get('/suppliers')->assertOk();
    $this->actingAs($admin)->get('/suppliers/create')->assertOk();
    $this->actingAs($admin)->get('/customers')->assertOk();
    $this->actingAs($admin)->get('/customers/create')->assertOk();
});

test('view roles can see inventory lists but not create pages', function () {
    $storeKeeper = User::factory()->create(['status' => 'active']);
    $storeKeeper->assignRole('Store Keeper');

    $this->actingAs($storeKeeper)->get('/categories')->assertOk();
    $this->actingAs($storeKeeper)->get('/units')->assertOk();
    $this->actingAs($storeKeeper)->get('/products')->assertOk();
    $this->actingAs($storeKeeper)->get('/suppliers')->assertOk();
    $this->actingAs($storeKeeper)->get('/customers')->assertOk();

    $this->actingAs($storeKeeper)->get('/products/create')->assertForbidden();
    $this->actingAs($storeKeeper)->get('/suppliers/create')->assertForbidden();
    $this->actingAs($storeKeeper)->get('/customers/create')->assertForbidden();
});

test('direct inventory mode hides warehouse only workflows and keeps direct sales flow available', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    enableDirectInventoryModeForBranch((int) $admin->branch_id);

    $this->actingAs($admin)->get('/direct-stock-in')->assertOk();
    $this->actingAs($admin)->get('/dispensing-stock')->assertOk();
    $this->actingAs($admin)->get('/pos')->assertOk();

    $this->actingAs($admin)->get('/suppliers')->assertForbidden();
    $this->actingAs($admin)->get('/purchases')->assertForbidden();
    $this->actingAs($admin)->get('/purchases/create')->assertForbidden();
    $this->actingAs($admin)->get('/store-stock')->assertForbidden();
    $this->actingAs($admin)->get('/stock-transfers')->assertForbidden();
    $this->actingAs($admin)->get('/reports/purchases')->assertForbidden();
});

test('pos requires location selection in multi location mode', function () {
    $branch = Branch::firstOrFail();
    enableWarehouseInventoryModeForBranch((int) $branch->id);
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'sales_location_access' => 'both',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->assertSet('stock_location_id', '')
        ->assertSee('Selling From')
        ->assertSee('Select Selling Location');
});

test('single selling location companies auto select the location', function () {
    $branch = Branch::firstOrFail();
    [$store] = enableWarehouseInventoryModeForBranch((int) $branch->id);
    StockLocation::query()
        ->where('branch_id', $branch->id)
        ->where('type', 'dispensing')
        ->update(['status' => 'inactive', 'is_active' => false]);

    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'sales_location_access' => 'both',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->assertSet('stock_location_id', (string) $store->id)
        ->assertSee('Unauza kutoka:')
        ->assertDontSee('Select Selling Location');
});

test('products cannot be added before selecting selling location', function () {
    $branch = Branch::firstOrFail();
    enableWarehouseInventoryModeForBranch((int) $branch->id);
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'sales_location_access' => 'both',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->call('addProduct', Product::firstOrFail()->id)
        ->assertHasErrors(['stock_location_id'])
        ->assertSet('cart', []);
});

test('changing selling location clears cart after confirmation', function () {
    $branch = Branch::firstOrFail();
    [$store, $dispensing] = enableWarehouseInventoryModeForBranch((int) $branch->id);
    $product = Product::firstOrFail();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'sales_location_access' => 'both',
    ]);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '1',
            'unit_price' => (string) $product->selling_price,
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]])
        ->call('requestStockLocationChange', (string) $store->id)
        ->assertSet('pending_stock_location_id', (string) $store->id)
        ->assertDispatched('open-modal')
        ->call('confirmStockLocationChange')
        ->assertSet('stock_location_id', (string) $store->id)
        ->assertSet('cart', []);
});

test('pos blocks sale from unauthorized stock location', function () {
    $branch = Branch::firstOrFail();
    [$store, $dispensing] = enableWarehouseInventoryModeForBranch((int) $branch->id);
    $product = Product::firstOrFail();
    $cashier = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
        'sales_location_access' => 'store',
    ]);
    $cashier->assignRole('Cashier');

    StockMovement::query()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $store->id,
        'movement_type' => 'adjustment_in',
        'quantity' => 5,
        'unit_cost' => $product->buying_price,
        'unit_price' => $product->selling_price,
        'created_by' => $cashier->id,
        'movement_date' => now()->toDateString(),
    ]);

    $this->actingAs($cashier);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $dispensing->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_type' => 'retail',
            'quantity' => '1',
            'unit_price' => (string) $product->selling_price,
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]])
        ->set('payments', [['payment_method' => 'cash', 'amount' => (string) $product->selling_price, 'reference_number' => '']])
        ->call('completeSale')
        ->assertHasErrors(['stock_location_id'])
        ->assertSee('Huna ruhusa ya kuuza kutoka sehemu hii ya stock.');
});

test('default inventory setup seed data exists', function () {
    expect(Category::where('code', 'CEM')->exists())->toBeTrue();
    expect(Unit::where('short_name', 'pcs')->exists())->toBeTrue();
    expect(Product::where('sku', 'BM-CEM-050')->exists())->toBeTrue();
});

test('business seeders isolate hardware and auto spare parts catalogs by company', function () {
    $hardwareCompany = Company::query()->create([
        'company_name' => 'Hardware Client',
        'business_type' => 'Hardware Store',
        'phone' => '+255 700 111 111',
        'whatsapp_number' => '+255 700 111 111',
    ]);
    $hardwareBranch = Branch::query()->create([
        'company_id' => $hardwareCompany->id,
        'name' => 'Hardware Main',
        'code' => 'HMAIN',
        'status' => 'active',
    ]);

    $autoCompany = Company::query()->create([
        'company_name' => 'Auto Client',
        'business_type' => 'Auto Spare Parts',
        'phone' => '+255 700 222 222',
        'whatsapp_number' => '+255 700 222 222',
    ]);
    $autoBranch = Branch::query()->create([
        'company_id' => $autoCompany->id,
        'name' => 'Auto Main',
        'code' => 'AMAIN',
        'status' => 'active',
    ]);

    (new HardwareCategorySeeder($hardwareCompany->id, $hardwareBranch->id))->run();
    (new HardwareUnitSeeder($hardwareCompany->id, $hardwareBranch->id))->run();
    (new HardwareProductSeeder($hardwareCompany->id, $hardwareBranch->id))->run();

    (new AutoPartsCategorySeeder($autoCompany->id, $autoBranch->id))->run();
    (new AutoPartsUnitSeeder($autoCompany->id, $autoBranch->id))->run();
    (new AutoPartsProductSeeder($autoCompany->id, $autoBranch->id))->run();

    expect(Product::query()->where('company_id', $hardwareCompany->id)->where('name', 'Simba Cement 50kg')->exists())->toBeTrue();
    expect(Product::query()->where('company_id', $hardwareCompany->id)->where('name', 'Front Brake Pads')->exists())->toBeFalse();

    expect(Product::query()->where('company_id', $autoCompany->id)->where('name', 'Front Brake Pads')->exists())->toBeTrue();
    expect(Product::query()->where('company_id', $autoCompany->id)->where('name', 'Simba Cement 50kg')->exists())->toBeFalse();

    expect(Category::query()->where('company_id', $hardwareCompany->id)->where('code', 'CEM')->where('branch_id', $hardwareBranch->id)->exists())->toBeTrue();
    expect(Category::query()->where('company_id', $autoCompany->id)->where('code', 'BRK')->where('branch_id', $autoBranch->id)->exists())->toBeTrue();
});

test('category and unit cannot be deleted while products are attached', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();

    $this->actingAs($admin);

    Volt::test('categories.index')
        ->call('deleteCategory', $product->category_id)
        ->assertHasNoErrors();

    expect(Category::find($product->category_id))->not->toBeNull();

    Volt::test('units.index')
        ->call('deleteUnit', $product->unit_id)
        ->assertHasNoErrors();

    expect(Unit::find($product->unit_id))->not->toBeNull();
});

test('super admin can update selling price when saving direct stock in', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();
    enableDirectInventoryModeForBranch((int) $admin->branch_id);

    $this->actingAs($admin);

    Volt::test('direct-stock-in.index')
        ->call('selectProduct', (string) $product->id)
        ->assertSet('cost_price', (string) $product->buying_price)
        ->assertSet('selling_price', (string) $product->selling_price)
        ->set('quantity', '2')
        ->set('cost_price', '1000')
        ->set('selling_price', '88888')
        ->set('reason', 'Manual Entry')
        ->call('save')
        ->assertSee('Direct stock in saved.');

    $movement = StockMovement::query()
        ->where('movement_type', 'direct_stock_in')
        ->where('product_id', $product->id)
        ->latest()
        ->firstOrFail();

    expect((float) $product->refresh()->selling_price)->toBe(88888.0);
    expect((float) $movement->unit_price)->toBe(88888.0);
});

test('direct stock in loads prices when product id model updates', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();
    enableDirectInventoryModeForBranch((int) $admin->branch_id);

    $this->actingAs($admin);

    Volt::test('direct-stock-in.index')
        ->set('product_id', (string) $product->id)
        ->assertSet('cost_price', (string) $product->buying_price)
        ->assertSet('selling_price', (string) $product->selling_price);
});

test('direct stock in shows validation errors when save is incomplete', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    enableDirectInventoryModeForBranch((int) $admin->branch_id);

    $this->actingAs($admin);

    Volt::test('direct-stock-in.index')
        ->set('product_id', '')
        ->set('cost_price', '')
        ->call('save')
        ->assertHasErrors(['product_id' => 'required', 'cost_price' => 'required'])
        ->assertSee('Please fix these errors:');
});

test('direct stock in reactivates inactive receiving location before saving', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();
    $location = enableDirectInventoryModeForBranch((int) $admin->branch_id);

    $location->update(['status' => 'inactive']);

    $this->actingAs($admin);

    Volt::test('direct-stock-in.index')
        ->call('selectProduct', (string) $product->id)
        ->set('quantity', '1')
        ->set('cost_price', '1000')
        ->set('reason', 'Manual Entry')
        ->call('save')
        ->assertHasNoErrors();

    expect($location->refresh()->status)->toBe('active');
    expect(StockMovement::query()
        ->where('movement_type', 'direct_stock_in')
        ->where('product_id', $product->id)
        ->exists())->toBeTrue();
});

test('non admin sees but cannot update selling price when saving direct stock in', function () {
    $branch = Branch::firstOrFail();
    $product = Product::firstOrFail();
    enableDirectInventoryModeForBranch((int) $branch->id);
    $originalSellingPrice = (float) $product->selling_price;
    $storeKeeper = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
    $storeKeeper->assignRole('Store Keeper');

    $this->actingAs($storeKeeper);

    Volt::test('direct-stock-in.index')
        ->call('selectProduct', (string) $product->id)
        ->assertSet('cost_price', (string) $product->buying_price)
        ->assertSet('selling_price', (string) $product->selling_price)
        ->set('quantity', '2')
        ->set('cost_price', '1000')
        ->set('selling_price', '88888')
        ->assertSee('TZS '.number_format($originalSellingPrice, 2))
        ->set('reason', 'Manual Entry')
        ->call('save');

    $movement = StockMovement::query()
        ->where('movement_type', 'direct_stock_in')
        ->where('product_id', $product->id)
        ->latest()
        ->firstOrFail();

    expect((float) $product->refresh()->selling_price)->toBe($originalSellingPrice);
    expect((float) $movement->unit_price)->toBe($originalSellingPrice);
});
