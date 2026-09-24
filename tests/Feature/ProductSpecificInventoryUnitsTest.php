<?php

use App\Models\Branch;
use App\Models\GoodsReceivingNoteItem;
use App\Models\MeasurementType;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OpeningStockService;
use App\Services\ProductUnitConversionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->forceFill(['status' => 'active', 'is_active' => true, 'can_receive_stock' => true])->save();
    Setting::query()->firstOrFail()->update(['enable_warehouse' => true]);

    $metre = Unit::where('short_name', 'm')->firstOrFail();
    $this->roll = Unit::where('short_name', 'roll')->firstOrFail();
    $this->bundle = Unit::where('short_name', 'bundle')->firstOrFail();
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill([
        'name' => 'Unit Test Electrical Cable', 'sku' => 'UNIT-CABLE', 'barcode' => null,
        'measurement_type_id' => MeasurementType::where('code', MeasurementType::LENGTH)->value('id'),
        'unit_id' => $metre->id, 'purchase_unit_id' => $metre->id,
        'selling_unit_id' => $metre->id, 'purchase_conversion_factor' => 1,
        'buying_price' => 500, 'selling_price' => 700,
    ]);
    $this->product->save();
    $this->rollConversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $this->roll->id, 'conversion_factor' => 100,
        'purchase_price' => 95000, 'retail_price' => 120000,
        'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
    $this->bundleConversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $this->bundle->id, 'conversion_factor' => 500,
        'purchase_price' => 460000, 'retail_price' => 600000,
        'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
});

test('tonne is a weight unit and admins can add future Units master entries', function () {
    $tonne = Unit::where('short_name', 'tonne')->firstOrFail();
    expect($tonne->measurementType?->code)->toBe(MeasurementType::WEIGHT)
        ->and($tonne->status)->toBe('active');
    (new \Database\Seeders\HardwareUnitSeeder($this->admin->company_id, $this->branch->id))->run();
    expect(Unit::where('short_name', 'tonne')->count())->toBe(1)
        ->and(Unit::where('short_name', 'tonne')->firstOrFail()->id)->toBe($tonne->id);

    $count = MeasurementType::where('code', MeasurementType::COUNT)->firstOrFail();
    Volt::test('units.index')
        ->set('name', 'Cable Crate')
        ->set('short_name', 'cable-crate')
        ->set('measurement_type_id', (string) $count->id)
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors();
    $crate = Unit::where('short_name', 'cable-crate')->firstOrFail();
    ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $crate->id, 'conversion_factor' => 20, 'purchase_price' => 18000,
        'can_purchase' => true, 'can_sell' => false, 'active' => true,
    ]);
    expect($this->product->unitConversions()->where('unit_id', $crate->id)->exists())->toBeTrue();
    Volt::test('units.index')->call('deleteUnit', $crate->id);
    expect(Unit::whereKey($crate->id)->exists())->toBeTrue();
    Volt::test('products.create')->assertSee('+ Ongeza Kipimo');
});

test('Purchase lists only product purchase units and suggests their own transaction-unit prices', function () {
    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Unit Test Supplier', 'phone' => '255700000123', 'status' => 'active',
    ]);
    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('items.0.use_base_unit', true)
        ->assertSet('items.0.purchase_unit_id', $this->product->unit_id)
        ->assertSet('items.0.cost_price', 500.0)
        ->call('selectPurchaseUnit', 0, (string) $this->rollConversion->id)
        ->assertSet('items.0.purchase_unit_id', $this->roll->id)
        ->assertSet('items.0.purchase_conversion_factor', 100.0)
        ->assertSet('items.0.cost_price', 95000.0);
    preg_match('/<select[^>]*wire:change="selectPurchaseUnit\\(0, \\$event.target.value\\)"[^>]*>(.*?)<\\/select>/s', $component->html(), $matches);
    expect($matches[1] ?? '')->toContain('roll')->toContain('bundle')->not->toContain('tonne');

    $component->set('items.0.ordered_quantity', 0.5)
        ->call('savePurchase', 'ordered')
        ->assertHasErrors(['items.0.ordered_quantity']);
    $component->set('items.0.ordered_quantity', 10)
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();
    $item = Purchase::latest('id')->firstOrFail()->items()->firstOrFail();
    expect($item->purchase_unit_code_snapshot)->toBe('roll')
        ->and((float) $item->purchase_conversion_factor)->toBe(100.0)
        ->and((float) $item->base_ordered_quantity)->toBe(1000.0)
        ->and((float) $item->cost_price)->toBe(95000.0);
});

test('partial GRNs preserve PO transaction units and historical factors after conversion changes', function () {
    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Partial Unit Supplier', 'phone' => '255700000124', 'status' => 'active',
    ]);
    $purchase = Purchase::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id, 'purchase_date' => today(),
        'reference_number' => 'UNIT-PO-001', 'status' => 'ordered',
        'payment_status' => 'unpaid', 'total_amount' => 950000,
        'paid_amount' => 0, 'balance_amount' => 950000, 'created_by' => $this->admin->id,
    ]);
    $item = $purchase->items()->create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'product_unit_conversion_id' => $this->rollConversion->id,
        'purchase_unit_id' => $this->roll->id, 'stock_unit_id' => $this->product->unit_id,
        'purchase_unit_name_snapshot' => $this->roll->name, 'purchase_unit_code_snapshot' => $this->roll->short_name,
        'stock_unit_name_snapshot' => $this->product->unit->name, 'stock_unit_code_snapshot' => $this->product->unit->short_name,
        'purchase_conversion_factor' => 100, 'ordered_quantity' => 10, 'base_ordered_quantity' => 1000,
        'received_quantity' => 0, 'base_received_quantity' => 0,
        'cost_price' => 95000, 'selling_price' => 700, 'line_total' => 950000,
    ]);
    $this->rollConversion->update(['conversion_factor' => 200, 'purchase_price' => 180000]);
    $inventory = app(InventoryService::class);
    $first = $inventory->receivePurchase($purchase, [$item->id => ['quantity' => 4, 'stock_location_id' => $this->location->id]], today()->toDateString(), $this->admin->id);
    $second = $inventory->receivePurchase($purchase->fresh(), [$item->id => ['quantity' => 6, 'stock_location_id' => $this->location->id]], today()->toDateString(), $this->admin->id);
    $receiptItems = GoodsReceivingNoteItem::whereIn('goods_receiving_note_id', [$first->id, $second->id])->orderBy('id')->get();
    expect($receiptItems->pluck('received_quantity')->map(fn ($v) => (float) $v)->all())->toBe([4.0, 6.0])
        ->and($receiptItems->pluck('stock_quantity')->map(fn ($v) => (float) $v)->all())->toBe([400.0, 600.0])
        ->and($receiptItems->pluck('purchase_unit_code_snapshot')->all())->toBe(['roll', 'roll'])
        ->and($receiptItems->pluck('conversion_factor_snapshot')->map(fn ($v) => (float) $v)->all())->toBe([100.0, 100.0])
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe(1000.0);
    $this->roll->update(['short_name' => 'renamed-roll']);
    $this->get(route('goods-receipts.show', $first))->assertOk()
        ->assertSee('4 roll')->assertSee('1 roll = 100 m')->assertDontSee('renamed-roll');
});

test('Opening Stock suggests configured purchase-unit cost and persists entered-unit snapshots', function () {
    $component = Volt::test('opening-stock.create')
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('lines.0.unit_cost', (string) $this->product->buying_price)
        ->assertSee($this->roll->name)
        ->assertSee($this->bundle->name)
        ->call('selectUnit', 0, (string) $this->rollConversion->id)
        ->assertSet('lines.0.unit_cost', '95000.00')
        ->set('lines.0.unit_cost', '97000')
        ->assertSet('lines.0.cost_edited', true)
        ->call('selectUnit', 0, (string) $this->bundleConversion->id)
        ->assertSet('lines.0.unit_cost', '97000');

    $opening = app(OpeningStockService::class)->create([
        'branch_id' => $this->branch->id, 'stock_location_id' => $this->location->id,
        'opening_date' => today()->toDateString(), 'lines' => [[
            'product_id' => $this->product->id,
            'product_unit_conversion_id' => $this->rollConversion->id,
            'quantity' => 5, 'unit_cost' => 95000,
        ]],
    ], $this->admin);
    $line = $opening->lines->first();
    $movement = StockMovement::where('reference_type', OpeningStock::class)->where('reference_id', $opening->id)->firstOrFail();
    expect((float) $line->transaction_quantity)->toBe(5.0)
        ->and($line->transaction_unit_code_snapshot)->toBe('roll')
        ->and((float) $line->conversion_factor_snapshot)->toBe(100.0)
        ->and((float) $line->base_quantity)->toBe(500.0)
        ->and((float) $opening->total_value)->toBe(475000.0)
        ->and((float) $movement->unit_cost)->toBe(950.0);
    $this->rollConversion->update(['conversion_factor' => 200, 'purchase_price' => 180000]);
    expect((float) $line->fresh()->base_quantity)->toBe(500.0)
        ->and((float) $line->fresh()->conversion_factor_snapshot)->toBe(100.0)
        ->and((float) $movement->fresh()->unit_cost)->toBe(950.0);
});

test('editing a PO preserves saved unit snapshots until the user selects another unit', function () {
    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Edit Unit Supplier', 'phone' => '255700000125', 'status' => 'active',
    ]);
    $purchase = Purchase::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id, 'purchase_date' => today(),
        'reference_number' => 'UNIT-EDIT-PO', 'status' => 'ordered',
        'payment_status' => 'unpaid', 'total_amount' => 475000,
        'paid_amount' => 0, 'balance_amount' => 475000, 'created_by' => $this->admin->id,
    ]);
    $purchase->items()->create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'product_unit_conversion_id' => $this->rollConversion->id,
        'purchase_unit_id' => $this->roll->id, 'stock_unit_id' => $this->product->unit_id,
        'purchase_unit_name_snapshot' => $this->roll->name, 'purchase_unit_code_snapshot' => 'roll',
        'stock_unit_name_snapshot' => $this->product->unit->name, 'stock_unit_code_snapshot' => 'm',
        'purchase_conversion_factor' => 100, 'ordered_quantity' => 5, 'base_ordered_quantity' => 500,
        'received_quantity' => 0, 'base_received_quantity' => 0,
        'cost_price' => 95000, 'selling_price' => 700, 'line_total' => 475000,
    ]);
    $this->rollConversion->update(['conversion_factor' => 200, 'purchase_price' => 180000]);
    Volt::test('purchases.edit', ['purchase' => $purchase])
        ->assertSee('roll')
        ->set('notes', 'No unit change')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();
    $saved = $purchase->fresh()->items()->firstOrFail();
    expect($saved->purchase_unit_code_snapshot)->toBe('roll')
        ->and((float) $saved->purchase_conversion_factor)->toBe(100.0)
        ->and((float) $saved->base_ordered_quantity)->toBe(500.0);

    Volt::test('purchases.edit', ['purchase' => $purchase->fresh()])
        ->call('selectPurchaseUnit', 0, (string) $this->bundleConversion->id)
        ->assertSet('items.0.cost_price', '460000.00')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();
    $changed = $purchase->fresh()->items()->firstOrFail();
    expect($changed->purchase_unit_code_snapshot)->toBe('bundle')
        ->and((float) $changed->purchase_conversion_factor)->toBe(500.0)
        ->and((float) $changed->base_ordered_quantity)->toBe(2500.0);
});

test('tonne converts directly to different base quantities for different products', function () {
    $tonne = Unit::where('short_name', 'tonne')->firstOrFail();
    $bag = Unit::where('short_name', 'bag')->firstOrFail();
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $count = MeasurementType::where('code', MeasurementType::COUNT)->firstOrFail();

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $count->id)
        ->set('unit_id', (string) $bag->id)
        ->assertSee('Tonne / tonne')
        ->call('addUnitConversion')
        ->assertSee('Tonne / tonne');

    $cement = $this->product->replicate();
    $cement->fill(['name' => 'Tonne Cement', 'sku' => 'TONNE-CEMENT', 'barcode' => null,
        'measurement_type_id' => $count->id, 'unit_id' => $bag->id,
        'purchase_unit_id' => $bag->id, 'selling_unit_id' => $bag->id]);
    $cement->save();
    $nondo = $this->product->replicate();
    $nondo->fill(['name' => 'Tonne Nondo', 'sku' => 'TONNE-NONDO', 'barcode' => null,
        'measurement_type_id' => $count->id, 'unit_id' => $piece->id,
        'purchase_unit_id' => $piece->id, 'selling_unit_id' => $piece->id]);
    $nondo->save();
    $cementConversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $cement->id,
        'unit_id' => $tonne->id, 'conversion_factor' => 20,
        'purchase_price' => 300000, 'can_purchase' => true, 'can_sell' => false, 'active' => true,
    ]);
    $nondoConversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $nondo->id,
        'unit_id' => $tonne->id, 'conversion_factor' => 94,
        'purchase_price' => 470000, 'can_purchase' => true, 'can_sell' => false, 'active' => true,
    ]);
    $normalizer = app(ProductUnitConversionService::class);
    expect($normalizer->normalizePurchase($cement, $cementConversion->id, 1, 300000)['base_quantity'])->toBe(20.0)
        ->and($normalizer->normalizePurchase($nondo, $nondoConversion->id, 1, 470000)['base_quantity'])->toBe(94.0)
        ->and((float) $cementConversion->conversion_factor)->toBe(20.0)
        ->and((float) $nondoConversion->conversion_factor)->toBe(94.0);

    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Tonne Supplier', 'phone' => '255700000127', 'status' => 'active',
    ]);
    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $cement->id)
        ->call('selectPurchaseUnit', 0, (string) $cementConversion->id)
        ->set('items.0.ordered_quantity', 0.5)
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();
    $item = Purchase::latest('id')->firstOrFail()->items()->firstOrFail();
    expect((float) $item->ordered_quantity)->toBe(0.5)
        ->and((float) $item->base_ordered_quantity)->toBe(10.0)
        ->and($item->purchase_unit_code_snapshot)->toBe('tonne');
});

test('deactivated alternative units are unavailable for new purchases', function () {
    $this->roll->update(['status' => 'inactive']);
    $service = app(ProductUnitConversionService::class);
    expect($service->purchasable($this->product)->pluck('unit_id')->all())->not->toContain($this->roll->id)
        ->and(fn () => $service->resolveForPurchase($this->product, $this->rollConversion->id))
        ->toThrow(ValidationException::class);
});

test('legacy configured purchase unit remains selectable without a separate alternative record', function () {
    $legacy = $this->product->replicate();
    $legacy->fill([
        'name' => 'Legacy Roll Cable', 'sku' => 'UNIT-LEGACY-CABLE', 'barcode' => null,
        'purchase_unit_id' => $this->roll->id, 'purchase_conversion_factor' => 100,
    ]);
    $legacy->save();
    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Legacy Unit Supplier', 'phone' => '255700000126', 'status' => 'active',
    ]);
    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $legacy->id)
        ->assertSet('items.0.purchase_unit_id', $this->roll->id)
        ->assertSet('items.0.purchase_conversion_factor', 100.0)
        ->assertSet('items.0.cost_price', 50000.0)
        ->assertSee('roll')
        ->call('selectPurchaseUnit', 0, 'base')
        ->assertSet('items.0.purchase_unit_id', $legacy->unit_id)
        ->assertSet('items.0.purchase_conversion_factor', 1);
});

test('PO defaults to the configured alternative purchase unit instead of the first arbitrary unit', function () {
    $this->product->update([
        'purchase_unit_id' => $this->bundle->id,
        'purchase_conversion_factor' => 500,
    ]);
    $supplier = Supplier::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Configured Bundle Supplier', 'phone' => '255700000128', 'status' => 'active',
    ]);
    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('items.0.product_unit_conversion_id', (string) $this->bundleConversion->id)
        ->assertSet('items.0.purchase_unit_id', $this->bundle->id)
        ->assertSet('items.0.purchase_conversion_factor', 500.0)
        ->assertSet('items.0.cost_price', 460000.0);
});
