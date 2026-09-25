<?php

use App\Models\Branch;
use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\OpeningStockService;
use App\Services\StockLocationDefaultService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::create([
        'company_id' => $this->admin->company_id, 'name' => 'Kibaha Branch',
        'code' => 'KIBAHA-MULTI', 'status' => 'active',
    ]);
    $this->admin->update(['branch_id' => $this->branch->id]);
    $this->actingAs($this->admin);
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true, 'inventory_mode' => 'multi_location',
        'allow_multiple_dispensing_locations' => true,
    ]);

    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill([
        'branch_id' => $this->branch->id, 'name' => 'Kibaha Cement',
        'sku' => 'KIBAHA-CEMENT', 'barcode' => null,
        'buying_price' => 100, 'selling_price' => 150,
        'selling_unit_id' => $this->product->unit_id,
        'conversion_factor' => 1, 'status' => 'active',
    ]);
    $this->product->save();

    $definitions = [
        ['Main Warehouse', 'KIB-WH', 'warehouse', 500, 100],
        ['Main Shop', 'KIB-SHOP', 'dispensing', 40, 110],
        ['Cement Counter', 'KIB-CEMENT', 'dispensing', 120, 120],
        ['Wholesale Counter', 'KIB-WHOLESALE', 'dispensing', 80, 130],
    ];
    $this->locations = collect($definitions)->map(function (array $row) {
        [$name, $code, $type, $quantity, $cost] = $row;
        $location = StockLocation::create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
            'name' => $name, 'code' => $code, 'type' => $type,
            'status' => 'active', 'is_active' => true,
            'is_warehouse' => $type === 'warehouse',
            'is_dispensing_location' => $type === 'dispensing',
            'can_receive_stock' => true, 'can_issue_stock' => true,
            'can_sell' => $type === 'dispensing', 'is_sellable' => $type === 'dispensing',
            'can_transfer' => true, 'can_transfer_to_dispensing' => true,
        ]);
        StockMovement::create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
            'product_id' => $this->product->id, 'stock_location_id' => $location->id,
            'movement_type' => 'adjustment_in', 'quantity' => $quantity,
            'quantity_in' => $quantity, 'quantity_out' => 0, 'unit_cost' => $cost,
            'created_by' => $this->admin->id, 'movement_date' => today(),
        ]);

        return $location;
    })->keyBy('name');
    $this->admin->stockLocations()->sync($this->locations->mapWithKeys(fn ($location) => [$location->id => [
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'can_view' => true, 'can_sell' => $location->can_sell,
        'can_transfer' => true, 'can_receive' => true, 'can_adjust' => true,
        'is_default' => $location->name === 'Main Shop',
    ]])->all());
});

function kibahaQuantity(object $test, string $name): float
{
    return app(InventoryService::class)->getProductStock(
        $test->product->id, $test->locations[$name]->id, $test->branch->id
    );
}

function kibahaTransfer(object $test, string $from, string $to, float $quantity): void
{
    $transfer = StockTransfer::create([
        'company_id' => $test->admin->company_id, 'branch_id' => $test->branch->id,
        'transfer_number' => 'KIB-'.str()->random(12),
        'from_location_id' => $test->locations[$from]->id,
        'to_location_id' => $test->locations[$to]->id,
        'transfer_date' => today(), 'status' => 'draft', 'created_by' => $test->admin->id,
    ]);
    $transfer->items()->create(['company_id' => $test->admin->company_id, 'product_id' => $test->product->id, 'quantity' => $quantity]);
    app(InventoryService::class)->completeStockTransfer($transfer->id, $test->admin->id);
}

test('three dispensing counters stay separate in POS and honor assigned users', function () {
    $inventory = app(InventoryService::class);
    expect(array_map(fn ($name) => kibahaQuantity($this, $name), ['Main Warehouse', 'Main Shop', 'Cement Counter', 'Wholesale Counter']))
        ->toBe([500.0, 40.0, 120.0, 80.0]);

    foreach (['Main Shop', 'Cement Counter', 'Wholesale Counter'] as $name) {
        expect(collect(InventorySettings::allowedSaleLocationsForUser($this->admin, $this->branch->id))->pluck('id'))
            ->toContain($this->locations[$name]->id);
    }
    Volt::test('pos.index')->assertSee('Main Shop')->assertSee('Cement Counter')->assertSee('Wholesale Counter');

    foreach (['Cashier A' => 'Main Shop', 'Cashier B' => 'Cement Counter'] as $name => $allowed) {
        $cashier = User::factory()->create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
            'name' => $name, 'status' => 'active',
        ]);
        $cashier->assignRole('Cashier');
        $cashier->stockLocations()->sync([$this->locations[$allowed]->id => [
            'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
            'can_view' => true, 'can_sell' => true, 'is_default' => true,
        ]]);
        expect(collect(InventorySettings::allowedSaleLocationsForUser($cashier, $this->branch->id))->pluck('id')->all())
            ->toBe([$this->locations[$allowed]->id]);
        $this->actingAs($cashier);
        Volt::test('pos.index')->assertSee($allowed)->assertDontSee($name === 'Cashier A' ? 'Cement Counter' : 'Main Shop');
        expect(AuthorizationScope::stockLocationIds($cashier)->all())->toBe([$this->locations[$allowed]->id]);
    }

    $this->actingAs($this->admin);
    $counter = $this->locations['Cement Counter'];
    $sale = $inventory->completeSale([
        ['product_id' => $this->product->id, 'stock_location_id' => $counter->id,
            'sale_type' => 'retail', 'quantity' => 2, 'unit_price' => 150,
            'discount_amount' => 0, 'tax_amount' => 0],
    ], [['payment_method' => 'cash', 'amount' => 300]], null, $counter->id, $this->branch->id, $this->admin->id);
    expect($sale->items->first()->stock_location_id)->toBe($counter->id)
        ->and(kibahaQuantity($this, 'Cement Counter'))->toBe(118.0)
        ->and(kibahaQuantity($this, 'Main Shop'))->toBe(40.0)
        ->and(kibahaQuantity($this, 'Wholesale Counter'))->toBe(80.0);
    expect(AuthorizationScope::reports(Sale::query(), $this->admin)->whereHas('items', fn ($query) => $query->where('stock_location_id', $counter->id))->count())->toBe(1);
    expect($sale->sale_date->toDateString())->toBe(today()->toDateString())
        ->and(AuthorizationScope::reports(Sale::query(), $this->admin)->whereDate('sale_date', '>=', now()->startOfMonth()->toDateString())->whereDate('sale_date', '<=', today()->toDateString())->whereHas('items', fn ($query) => $query->where('stock_location_id', $counter->id))->count())->toBe(1);
    Volt::test('reports.sales')->set('stock_location_id', (string) $counter->id)->assertSee($sale->sale_number);
});

test('dispensing and summary pages expose all location balances and valuation remains keyed by ID', function () {
    Volt::test('dispensing-stock.index')
        ->assertSee('All Authorised Selling Locations')
        ->assertSee('Main Shop')->assertSee('Cement Counter')->assertSee('Wholesale Counter')
        ->set('locationFilter', (string) $this->locations['Cement Counter']->id)
        ->assertSee('Kibaha Cement');
    Volt::test('inventory-summary.index')
        ->assertSee('Main Warehouse: 500')
        ->assertSee('Main Shop: 40')
        ->assertSee('Cement Counter: 120')
        ->assertSee('Wholesale Counter: 80')
        ->assertSee('240')->assertSee('740');

    expect(app(InventoryService::class)->getDispensingStock($this->product->id, $this->branch->id))->toBe(240.0)
        ->and(InventorySettings::saleLocations($this->branch->id))->toHaveCount(3);

    $valuation = app(FinancialReportService::class);
    $expected = ['Main Warehouse' => 50000, 'Main Shop' => 4400, 'Cement Counter' => 14400, 'Wholesale Counter' => 10400];
    foreach ($expected as $name => $value) {
        $rows = collect($valuation->stockValuation($this->branch->id, $this->locations[$name]->id));
        expect($rows->sum('value'))->toEqual($value)
            ->and($rows->pluck('stock_location_id')->unique()->all())->toBe([$this->locations[$name]->id]);
    }
    expect(collect($valuation->stockValuation($this->branch->id))->sum('value'))->toEqual(array_sum($expected));
    Volt::test('reports.stock-valuation')->set('branch_id', (string) $this->branch->id)
        ->set('stock_location_id', (string) $this->locations['Cement Counter']->id)
        ->assertSee('Cement Counter');
    Volt::test('stock-movements.index')->set('locationFilter', (string) $this->locations['Cement Counter']->id)
        ->assertSee('Cement Counter');
});

test('transfers, adjustment, opening stock and GRN use selected locations', function () {
    $inventory = app(InventoryService::class);
    foreach (['Main Shop', 'Cement Counter', 'Wholesale Counter'] as $name) {
        kibahaTransfer($this, 'Main Warehouse', $name, 5);
    }
    kibahaTransfer($this, 'Main Shop', 'Wholesale Counter', 2);
    expect([kibahaQuantity($this, 'Main Warehouse'), kibahaQuantity($this, 'Main Shop'), kibahaQuantity($this, 'Cement Counter'), kibahaQuantity($this, 'Wholesale Counter')])
        ->toBe([485.0, 43.0, 125.0, 87.0]);

    $adjustment = $inventory->createStockAdjustment([
        'branch_id' => $this->branch->id, 'stock_location_id' => $this->locations['Cement Counter']->id,
        'adjustment_date' => today()->toDateString(), 'reference_number' => 'KIB-ADJ',
    ], [['product_id' => $this->product->id, 'physical_quantity' => 123,
        'reason' => 'Physical count difference']], $this->admin->id);
    $inventory->approveAdjustment($adjustment, $this->admin->id);
    expect(kibahaQuantity($this, 'Cement Counter'))->toBe(123.0)
        ->and(kibahaQuantity($this, 'Main Shop'))->toBe(43.0)
        ->and(kibahaQuantity($this, 'Wholesale Counter'))->toBe(87.0);

    $opening = app(OpeningStockService::class)->create([
        'branch_id' => $this->branch->id, 'stock_location_id' => $this->locations['Main Shop']->id,
        'opening_date' => today()->toDateString(), 'lines' => [[
            'product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 100,
        ]],
    ], $this->admin);
    expect($opening->stock_location_id)->toBe($this->locations['Main Shop']->id)
        ->and(kibahaQuantity($this, 'Main Shop'))->toBe(46.0)
        ->and(kibahaQuantity($this, 'Cement Counter'))->toBe(123.0);

    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Kibaha Supplier', 'phone' => '255700000001', 'status' => 'active',
    ]);
    $purchase = Purchase::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id, 'purchase_date' => today(),
        'reference_number' => 'KIB-PO', 'status' => 'ordered', 'payment_status' => 'unpaid',
        'total_amount' => 400, 'paid_amount' => 0, 'balance_amount' => 400,
        'created_by' => $this->admin->id,
    ]);
    $item = $purchase->items()->create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'purchase_unit_id' => $this->product->purchase_unit_id,
        'stock_unit_id' => $this->product->unit_id,
        'purchase_conversion_factor' => $this->product->purchaseConversionFactor(),
        'ordered_quantity' => 4, 'received_quantity' => 0,
        'cost_price' => 100, 'selling_price' => 150, 'line_total' => 400,
    ]);
    $grn = $inventory->receivePurchase($purchase, [$item->id => [
        'quantity' => 2, 'stock_location_id' => $this->locations['Cement Counter']->id,
    ]], today()->toDateString(), $this->admin->id);
    $inventory->receivePurchase($purchase->fresh(), [$item->id => [
        'quantity' => 2, 'stock_location_id' => $this->locations['Wholesale Counter']->id,
    ]], today()->toDateString(), $this->admin->id);
    expect(StockMovement::where('reference_type', GoodsReceivingNote::class)->where('reference_id', $grn->id)
        ->pluck('stock_location_id')->unique()->all())->toBe([$this->locations['Cement Counter']->id])
        ->and(kibahaQuantity($this, 'Cement Counter'))->toBe(125.0)
        ->and(kibahaQuantity($this, 'Wholesale Counter'))->toBe(89.0)
        ->and(kibahaQuantity($this, 'Main Shop'))->toBe(46.0);
});

test('branch-wide defaults are replaced transactionally without changing user defaults', function () {
    $service = app(StockLocationDefaultService::class);
    $service->makeDefault($this->locations['Main Shop']);
    $service->makeDefault($this->locations['Cement Counter']);
    expect(StockLocation::where('branch_id', $this->branch->id)->where('is_default', true)->pluck('id')->all())
        ->toBe([$this->locations['Cement Counter']->id])
        ->and($this->admin->stockLocations()->wherePivot('is_default', true)->pluck('stock_locations.id')->all())
        ->toBe([$this->locations['Main Shop']->id]);
});

test('Cement Counter adjustment from 120 to 118 leaves the other counters unchanged', function () {
    $inventory = app(InventoryService::class);
    $adjustment = $inventory->createStockAdjustment([
        'branch_id' => $this->branch->id,
        'stock_location_id' => $this->locations['Cement Counter']->id,
        'adjustment_date' => today()->toDateString(),
        'reference_number' => 'KIB-ADJ-120-118',
    ], [[
        'product_id' => $this->product->id,
        'physical_quantity' => 118,
        'reason' => 'Physical count difference',
    ]], $this->admin->id);
    $inventory->approveAdjustment($adjustment, $this->admin->id);

    expect(kibahaQuantity($this, 'Cement Counter'))->toBe(118.0)
        ->and(kibahaQuantity($this, 'Main Shop'))->toBe(40.0)
        ->and(kibahaQuantity($this, 'Wholesale Counter'))->toBe(80.0)
        ->and(kibahaQuantity($this, 'Main Warehouse'))->toBe(500.0);
});

test('the company setting explicitly controls creation of another dispensing location', function () {
    Setting::query()->firstOrFail()->update(['allow_multiple_dispensing_locations' => false]);
    $fields = [
        'name' => 'Extra Counter', 'code' => 'KIB-EXTRA', 'type' => 'dispensing',
        'branch_id' => (string) $this->branch->id, 'is_dispensing_location' => true,
        'can_receive_stock' => true, 'can_issue_stock' => true,
        'can_sell' => true, 'is_active' => true,
    ];
    $component = Volt::test('stock-locations.index');
    foreach ($fields as $field => $value) {
        $component->set($field, $value);
    }
    $component->call('save')->assertHasErrors(['type']);
    expect(StockLocation::where('code', 'KIB-EXTRA')->exists())->toBeFalse();

    Setting::query()->firstOrFail()->update(['allow_multiple_dispensing_locations' => true]);
    $component->call('save')->assertHasNoErrors();
    expect(StockLocation::where('code', 'KIB-EXTRA')->exists())->toBeTrue();
});

test('a sell-enabled location classified by capability appears without dispensing type', function () {
    $annex = StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Counter Annex', 'code' => 'KIB-ANNEX', 'type' => 'other',
        'status' => 'active', 'is_active' => true, 'is_dispensing_location' => true,
        'can_sell' => true, 'is_sellable' => true, 'can_receive_stock' => true,
    ]);
    $this->admin->stockLocations()->attach($annex->id, [
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'can_view' => true, 'can_sell' => true, 'can_receive' => true,
    ]);

    expect(collect(InventorySettings::allowedSaleLocationsForUser($this->admin, $this->branch->id))->pluck('id'))
        ->toContain($annex->id);
    Volt::test('dispensing-stock.index')->assertSee('Counter Annex');
    Volt::test('reports.sales')->assertSee('Counter Annex');
});
