<?php

use App\Models\Branch;
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

function multiLocationPosScenario(): array
{
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $product = Product::where('sku', 'BM-CEM-050')->firstOrFail();
    $product->category->update(['allow_fractional_sales' => false]);
    $product->update([
        'name' => 'Paving Block 60mm',
        'buying_price' => 10,
        'selling_price' => 20,
        'selling_unit_id' => $product->unit_id,
        'conversion_factor' => 1,
        'allow_fractional_sale' => false,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'status' => 'active',
    ]);
    $cement = $product->replicate()->fill([
        'name' => 'Cement 50kg',
        'sku' => 'MULTI-CEMENT-50',
        'barcode' => null,
        'buying_price' => 15,
        'selling_price' => 30,
    ]);
    $cement->save();

    $finishedGoods = StockLocation::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Finished Goods Store',
        'code' => 'FG-MULTI-SELL',
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'is_sellable' => true,
    ]);
    $mainStore = StockLocation::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Main Store Multi Sale',
        'code' => 'MAIN-MULTI-SELL',
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'is_sellable' => true,
    ]);

    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true,
        'inventory_mode' => 'multi_location',
        'allow_sales_from_store' => true,
        'default_stock_location_id' => $finishedGoods->id,
    ]);
    $admin->stockLocations()->sync([
        $finishedGoods->id => ['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'can_view' => true, 'can_sell' => true, 'assigned_by' => $admin->id],
        $mainStore->id => ['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'can_view' => true, 'can_sell' => true, 'assigned_by' => $admin->id],
    ]);

    foreach ([
        [$product, $finishedGoods, 1200],
        [$product, $mainStore, 300],
        [$cement, $mainStore, 100],
    ] as [$stockProduct, $location, $quantity]) {
        StockMovement::create([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'product_id' => $stockProduct->id,
            'stock_location_id' => $location->id,
            'movement_type' => 'adjustment_in',
            'quantity' => $quantity,
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => $stockProduct->buying_price,
            'unit_price' => $stockProduct->selling_price,
            'created_by' => $admin->id,
            'movement_date' => today(),
        ]);
    }

    return [$admin, $branch, $product, $cement, $finishedGoods, $mainStore];
}

function multiLocationSaleRow(Product $product, StockLocation $location, float $quantity): array
{
    return [
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'sale_type' => 'retail',
        'quantity' => $quantity,
        'unit_price' => $product->selling_price,
        'discount_amount' => 0,
        'tax_amount' => 0,
    ];
}

test('one sale posts products from two locations and renders one receipt', function () {
    [$admin, $branch, $block, $cement, $finishedGoods, $mainStore] = multiLocationPosScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);

    $sale = $inventory->completeSale(
        [multiLocationSaleRow($block, $finishedGoods, 200), multiLocationSaleRow($cement, $mainStore, 20)],
        [['payment_method' => 'cash', 'amount' => 4600, 'reference_number' => 'ONE-PAYMENT']],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->items)->toHaveCount(2)
        ->and($sale->payments)->toHaveCount(1)
        ->and($sale->items->pluck('stock_location_id')->sort()->values()->all())
        ->toBe(collect([$finishedGoods->id, $mainStore->id])->sort()->values()->all())
        ->and($inventory->getProductStock($block->id, $finishedGoods->id, $branch->id))->toEqual(1000.0)
        ->and($inventory->getProductStock($cement->id, $mainStore->id, $branch->id))->toEqual(80.0);

    expect(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'sale_out')->count())->toBe(2);

    $this->get("/sales/{$sale->id}/receipt")
        ->assertOk()
        ->assertSee('Paving Block 60mm')
        ->assertSee('Cement 50kg')
        ->assertSee('From: Finished Goods Store')
        ->assertSee('From: Main Store Multi Sale');
});

test('same product remains separate by location and cancellation restores each source', function () {
    [$admin, $branch, $block, , $finishedGoods, $mainStore] = multiLocationPosScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);

    $sale = $inventory->completeSale(
        [multiLocationSaleRow($block, $finishedGoods, 700), multiLocationSaleRow($block, $mainStore, 300)],
        [['payment_method' => 'cash', 'amount' => 20000]],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    );

    expect($sale->items)->toHaveCount(2)
        ->and($inventory->getProductStock($block->id, $finishedGoods->id, $branch->id))->toEqual(500.0)
        ->and($inventory->getProductStock($block->id, $mainStore->id, $branch->id))->toEqual(0.0);

    $inventory->cancelSale($sale->id, $admin->id);

    expect($inventory->getProductStock($block->id, $finishedGoods->id, $branch->id))->toEqual(1200.0)
        ->and($inventory->getProductStock($block->id, $mainStore->id, $branch->id))->toEqual(300.0);
});

test('pos searches all authorised locations and validates a changed line location', function () {
    [$admin, , $block, $cement, $finishedGoods, $mainStore] = multiLocationPosScenario();
    $this->actingAs($admin);

    Volt::test('pos.index')
        ->call('addProduct', $block->id)
        ->assertHasErrors(['cart'])
        ->assertSet('cart', []);

    Volt::test('pos.index')
        ->call('addProduct', $cement->id)
        ->assertSet('cart.0.stock_location_id', $mainStore->id);

    $component = Volt::test('pos.index')
        ->assertSet('stock_location_id', '')
        ->assertSee('Paving Block 60mm')
        ->assertSee('Finished Goods Store')
        ->assertSee('Main Store Multi Sale')
        ->call('addProduct', $block->id, $finishedGoods->id)
        ->assertSet('cart.0.stock_location_id', $finishedGoods->id)
        ->set('cart.0.quantity', '400')
        ->call('changeLineLocation', 0, $mainStore->id)
        ->assertSet('cart.0.stock_location_id', $mainStore->id)
        ->assertSet('cart.0.unit_price', '20.00')
        ->assertSet('cart.0.discount_amount', '0')
        ->assertSet('cart.0.tax_amount', '0')
        ->assertHasErrors(['cart.0.quantity']);

    $component->set('cart.0.quantity', '100')
        ->call('changeLineLocation', 0, $finishedGoods->id)
        ->assertHasNoErrors(['cart.0.quantity'])
        ->call('addProduct', $block->id, $mainStore->id)
        ->assertSet('cart.1.stock_location_id', $mainStore->id);
});

test('unauthorised inactive and insufficient lines rollback the entire sale', function () {
    [$admin, $branch, $block, $cement, $finishedGoods, $mainStore] = multiLocationPosScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);
    $unauthorised = StockLocation::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'Unauthorised Showroom',
        'code' => 'NO-AUTH-SHOWROOM',
        'type' => 'showroom',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'is_sellable' => true,
    ]);
    StockMovement::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'product_id' => $block->id,
        'stock_location_id' => $unauthorised->id,
        'movement_type' => 'adjustment_in',
        'quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 10,
        'unit_price' => 20,
        'created_by' => $admin->id,
        'movement_date' => today(),
    ]);

    Volt::test('pos.index')->assertDontSee('Unauthorised Showroom');

    expect(fn () => $inventory->completeSale(
        [multiLocationSaleRow($block, $unauthorised, 1)],
        [['payment_method' => 'cash', 'amount' => 20]],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    ))->toThrow(ValidationException::class);

    $mainStore->update(['status' => 'inactive', 'is_active' => false]);
    expect(fn () => $inventory->completeSale(
        [multiLocationSaleRow($cement, $mainStore, 1)],
        [['payment_method' => 'cash', 'amount' => 30]],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    ))->toThrow(ValidationException::class);
    $mainStore->update(['status' => 'active', 'is_active' => true]);

    $mainStore->update(['can_sell' => false]);
    expect(fn () => $inventory->completeSale(
        [multiLocationSaleRow($cement, $mainStore, 1)],
        [['payment_method' => 'cash', 'amount' => 30]],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    ))->toThrow(ValidationException::class);
    $mainStore->update(['can_sell' => true]);

    $salesBefore = Sale::count();
    $movementsBefore = StockMovement::count();
    expect(fn () => $inventory->completeSale(
        [multiLocationSaleRow($block, $finishedGoods, 10), multiLocationSaleRow($cement, $mainStore, 101)],
        [['payment_method' => 'cash', 'amount' => 3230]],
        null,
        $finishedGoods->id,
        $branch->id,
        $admin->id,
    ))->toThrow(ValidationException::class);

    expect(Sale::count())->toBe($salesBefore)
        ->and(StockMovement::count())->toBe($movementsBefore)
        ->and($inventory->getProductStock($block->id, $finishedGoods->id, $branch->id))->toEqual(1200.0);
});

test('duplicate submission is idempotent and legacy rows use the header location', function () {
    [$admin, $branch, $block, , $finishedGoods] = multiLocationPosScenario();
    $this->actingAs($admin);
    $inventory = app(InventoryService::class);
    $token = (string) str()->uuid();
    $row = multiLocationSaleRow($block, $finishedGoods, 10);

    $first = $inventory->completeSale([$row], [['payment_method' => 'cash', 'amount' => 200]], null, $finishedGoods->id, $branch->id, $admin->id, idempotencyKey: $token);
    $second = $inventory->completeSale([$row], [['payment_method' => 'cash', 'amount' => 200]], null, $finishedGoods->id, $branch->id, $admin->id, idempotencyKey: $token);

    expect($second->id)->toBe($first->id)
        ->and(StockMovement::where('reference_type', Sale::class)->where('reference_id', $first->id)->where('movement_type', 'sale_out')->count())->toBe(1);

    $legacyRow = $row;
    unset($legacyRow['stock_location_id']);
    $legacy = $inventory->completeSale([$legacyRow], [['payment_method' => 'cash', 'amount' => 200]], null, $finishedGoods->id, $branch->id, $admin->id);

    expect($legacy->items()->firstOrFail()->stock_location_id)->toBe($finishedGoods->id);
});
