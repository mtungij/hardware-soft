<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-MAB-G28')->firstOrFail();
    $this->product->update(['buying_price' => 2000, 'selling_price' => 2500]);
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->forceFill(['status' => 'active', 'is_active' => true, 'can_receive_stock' => true])->save();
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => false,
        'allow_direct_stock_in' => true,
        'default_stock_location_id' => $this->location->id,
    ]);
});

function directStockInConversion(Product $product, Unit $unit, array $overrides = []): ProductUnitConversion
{
    return ProductUnitConversion::create(array_merge([
        'company_id' => $product->company_id,
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'conversion_factor' => 20,
        'purchase_price' => 30000,
        'retail_price' => 40000,
        'can_purchase' => true,
        'can_sell' => true,
        'active' => true,
    ], $overrides));
}

function directStockInData(object $test, array $overrides = []): array
{
    return array_merge([
        'branch_id' => $test->branch->id,
        'product_id' => $test->product->id,
        'product_unit_conversion_id' => null,
        'quantity' => 100,
        'cost_price' => 2000,
        'stock_location_id' => $test->location->id,
        'reason' => 'Direct Purchase',
        'notes' => null,
        'movement_date' => today()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ], $overrides);
}

test('base unit direct stock in remains backward compatible and snapshots its context', function () {
    Volt::test('direct-stock-in.index')
        ->set('product_id', (string) $this->product->id)
        ->assertCount('stock_in_lines', 1)
        ->assertSet('stock_in_lines.0.product_unit_conversion_id', '')
        ->assertSet('stock_in_lines.0.quantity', '');

    $movement = app(InventoryService::class)->directStockIn(directStockInData($this), $this->admin->id);

    expect((float) $movement->quantity_in)->toBe(100.0)
        ->and((float) $movement->unit_cost)->toBe(2000.0)
        ->and((float) $movement->transaction_quantity)->toBe(100.0)
        ->and((float) $movement->conversion_factor_snapshot)->toBe(1.0)
        ->and($movement->transaction_unit_id)->toBe($this->product->unit_id)
        ->and(StockMovement::where('idempotency_key', $movement->idempotency_key)->count())->toBe(1);
});

test('simba cement Trip conversion refreshes in Livewire and posts authoritative base stock', function () {
    $this->product = Product::where('name', 'Simba Cement 50kg')->firstOrFail();
    $this->product->update(['buying_price' => 15000, 'selling_price' => 18000]);
    $trip = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'trip')->firstOrFail();
    $inactiveUnit = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Inactive Trip', 'short_name' => 'inactive-trip', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $blockedUnit = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Sales Trip', 'short_name' => 'sales-trip', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $foreignUnit = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Foreign Trip', 'short_name' => 'foreign-trip', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $conversion = directStockInConversion($this->product, $trip, [
        'conversion_factor' => 2,
        'purchase_price' => 30000,
        'retail_price' => 36000,
    ]);
    directStockInConversion($this->product, $inactiveUnit, ['active' => false]);
    directStockInConversion($this->product, $blockedUnit, ['can_purchase' => false]);
    $otherProduct = $this->product->replicate();
    $otherProduct->forceFill(['name' => 'Other Cement', 'sku' => 'OTHER-CEMENT'])->save();
    directStockInConversion($otherProduct, $foreignUnit);
    $baseOnlyProduct = Product::whereKeyNot($this->product->id)->whereDoesntHave('unitConversions')->firstOrFail();

    $component = Volt::test('direct-stock-in.index')
        ->set('product_id', (string) $this->product->id)
        ->assertCount('stock_in_lines', 2)
        ->assertSet('stock_in_unit_options', fn (array $options): bool => count($options) === 1
            && $options[0]['id'] === (string) $conversion->id
            && $options[0]['unit_name'] === 'Trip')
        ->assertSet('stock_in_lines.0.quantity', '')
        ->assertSet('stock_in_lines.1.product_unit_conversion_id', (string) $conversion->id)
        ->assertSet('stock_in_lines.1.quantity', '')
        ->assertSet('stock_in_lines.1.conversion_factor', '2.0000')
        ->assertSet('stock_in_lines.1.buying_price', '30000.00')
        ->assertSet('stock_in_lines.1.selling_price', '36000.00')
        ->assertSee('Stock Quantities')
        ->assertSee('Trip')
        ->assertSee('1 Trip = 2 Bag')
        ->assertSee('Quantity / trip')
        ->assertSee('Buying Price / trip')
        ->assertSee('Selling Price / trip')
        ->assertDontSee('+ Add Unit')
        ->assertDontSee('Inactive Trip')
        ->assertDontSee('Sales Trip')
        ->assertDontSee('Foreign Trip');

    $component
        ->set('product_id', (string) $baseOnlyProduct->id)
        ->assertCount('stock_in_lines', 1)
        ->assertSet('stock_in_unit_options', [])
        ->assertSee('No additional purchase units are configured for this product.')
        ->set('product_id', (string) $this->product->id)
        ->assertCount('stock_in_lines', 2)
        ->assertSet('stock_in_lines.1.product_unit_conversion_id', (string) $conversion->id)
        ->set('stock_in_lines.0.quantity', '10')
        ->set('stock_in_lines.1.quantity', '5')
        ->assertSee('5 Trip')
        ->assertSee('Normalized Stock to Add')
        ->assertSee('20 Bag')
        ->assertSee('Total Purchase Value')
        ->assertSee('TZS 300,000')
        ->set('reason', 'Direct Purchase')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Direct stock in saved.')
        ->assertSee('10 bag + 5 trip');

    $reference = StockMovement::where('movement_type', 'direct_stock_in')
        ->where('product_unit_conversion_id', $conversion->id)->latest('id')->value('posting_reference');
    $movements = StockMovement::where('posting_reference', $reference)->orderBy('id')->get();
    $movement = $movements
        ->where('product_unit_conversion_id', $conversion->id)
        ->firstOrFail();

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('posting_reference')->unique())->toHaveCount(1)
        ->and((float) $movements->sum('quantity_in'))->toBe(20.0)
        ->and((float) $movement->quantity_in)->toBe(10.0)
        ->and((float) $movement->unit_cost)->toBe(15000.0)
        ->and((float) $movement->transaction_quantity)->toBe(5.0)
        ->and($movement->transaction_unit_id)->toBe($trip->id)
        ->and($movement->transaction_unit_name_snapshot)->toBe('Trip')
        ->and($movement->transaction_unit_code_snapshot)->toBe('trip');
});

test('box purchase quantity and package price normalize to one base-unit movement', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = directStockInConversion($this->product, $box);

    $movement = app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $conversion->id,
        'quantity' => 10,
        'cost_price' => 30000,
        'selling_price' => 40000,
    ]), $this->admin->id);

    expect((float) $movement->quantity_in)->toBe(200.0)
        ->and((float) $movement->quantity_out)->toBe(0.0)
        ->and((float) $movement->unit_cost)->toBe(1500.0)
        ->and((float) $movement->quantity_in * (float) $movement->unit_cost)->toBe(300000.0)
        ->and((float) $movement->transaction_unit_cost)->toBe(30000.0)
        ->and((float) $movement->unit_price)->toBe(2000.0)
        ->and((float) $conversion->refresh()->retail_price)->toBe(40000.0)
        ->and((float) $this->product->refresh()->selling_price)->toBe(2500.0)
        ->and(StockMovement::where('movement_type', 'direct_stock_in')->where('idempotency_key', $movement->idempotency_key)->count())->toBe(1);
});

test('one batch supports base and multiple alternative units with one reference', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $pallet = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Mixed Pallet', 'short_name' => 'mixed-pallet', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $boxConversion = directStockInConversion($this->product, $box, ['conversion_factor' => 20, 'purchase_price' => 28000]);
    $palletConversion = directStockInConversion($this->product, $pallet, ['conversion_factor' => 500, 'purchase_price' => 250000]);
    $data = directStockInData($this, ['stock_in_lines' => [
        ['product_unit_conversion_id' => null, 'quantity' => 10, 'buying_price' => 1500, 'selling_price' => 2500],
        ['product_unit_conversion_id' => $boxConversion->id, 'quantity' => 5, 'buying_price' => 28000, 'selling_price' => 40000],
        ['product_unit_conversion_id' => $palletConversion->id, 'quantity' => 2, 'buying_price' => 250000, 'selling_price' => 300000],
    ]]);

    $movements = app(InventoryService::class)->directStockInBatch($data, $this->admin->id);

    expect($movements)->toHaveCount(3)
        ->and($movements->pluck('posting_reference')->unique())->toHaveCount(1)
        ->and(str_starts_with($movements->first()->posting_reference, 'DSI-'))->toBeTrue()
        ->and((float) $movements->sum('quantity_in'))->toBe(1110.0)
        ->and($movements->sum(fn (StockMovement $movement) => (float) $movement->quantity_in * (float) $movement->unit_cost))->toBe(655000.0)
        ->and($movements->whereNotNull('idempotency_key'))->toHaveCount(1)
        ->and((float) $this->product->refresh()->selling_price)->toBe(2500.0)
        ->and((float) $boxConversion->refresh()->retail_price)->toBe(40000.0)
        ->and((float) $palletConversion->refresh()->retail_price)->toBe(300000.0);
});

test('mixed-unit batch idempotency and weighted average costing remain correct', function () {
    $this->product = Product::where('name', 'Simba Cement 50kg')->firstOrFail();
    $trip = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'trip')->firstOrFail();
    $conversion = directStockInConversion($this->product, $trip, ['conversion_factor' => 2, 'purchase_price' => 28000]);
    $inventory = app(InventoryService::class);

    $inventory->directStockIn(directStockInData($this, [
        'product_id' => $this->product->id,
        'quantity' => 100,
        'cost_price' => 14000,
    ]), $this->admin->id);

    $batch = directStockInData($this, [
        'product_id' => $this->product->id,
        'stock_in_lines' => [
            ['product_unit_conversion_id' => null, 'quantity' => 10, 'buying_price' => 15000, 'selling_price' => 18000],
            ['product_unit_conversion_id' => $conversion->id, 'quantity' => 5, 'buying_price' => 28000, 'selling_price' => 36000],
        ],
    ]);

    $first = $inventory->directStockInBatch($batch, $this->admin->id);
    $second = $inventory->directStockInBatch($batch, $this->admin->id);

    expect($first)->toHaveCount(2)
        ->and($second->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and(StockMovement::where('posting_reference', $first->first()->posting_reference)->count())->toBe(2)
        ->and($inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe(120.0)
        ->and($inventory->getAverageCost($this->product->id, $this->location->id, $this->branch->id))->toBe(14083.33);
});

test('warehouse-enabled batch posts every normalized row to the selected location', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = directStockInConversion($this->product, $box, ['conversion_factor' => 20]);
    $store = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $store->forceFill(['status' => 'active', 'is_active' => true, 'can_receive_stock' => true])->save();
    Setting::query()->firstOrFail()->update(['enable_warehouse' => true, 'default_stock_location_id' => $store->id]);
    $data = directStockInData($this, [
        'stock_location_id' => $store->id,
        'stock_in_lines' => [
            ['product_unit_conversion_id' => null, 'quantity' => 4, 'buying_price' => 2000],
            ['product_unit_conversion_id' => $conversion->id, 'quantity' => 3, 'buying_price' => 30000],
        ],
    ]);

    $movements = app(InventoryService::class)->directStockInBatch($data, $this->admin->id);

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('stock_location_id')->unique()->all())->toBe([$store->id])
        ->and((float) $movements->sum('quantity_in'))->toBe(64.0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $store->id, $this->branch->id))->toBe(64.0);
});

test('invalid or duplicate batch rows roll back every movement and price change', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $invalid = directStockInConversion($this->product, $box, ['can_purchase' => false]);
    $originalSellingPrice = (float) $this->product->selling_price;
    $data = directStockInData($this, ['stock_in_lines' => [
        ['product_unit_conversion_id' => null, 'quantity' => 10, 'buying_price' => 1000, 'selling_price' => 99999],
        ['product_unit_conversion_id' => $invalid->id, 'quantity' => 2, 'buying_price' => 2000],
    ]]);

    expect(fn () => app(InventoryService::class)->directStockInBatch($data, $this->admin->id))
        ->toThrow(ValidationException::class)
        ->and(StockMovement::where('idempotency_key', $data['idempotency_key'])->exists())->toBeFalse()
        ->and((float) $this->product->refresh()->selling_price)->toBe($originalSellingPrice);

    $duplicate = directStockInData($this, ['stock_in_lines' => [
        ['product_unit_conversion_id' => null, 'quantity' => 1, 'buying_price' => 1000],
        ['product_unit_conversion_id' => null, 'quantity' => 1, 'buying_price' => 1000],
    ]]);
    expect(fn () => app(InventoryService::class)->directStockInBatch($duplicate, $this->admin->id))
        ->toThrow(ValidationException::class);
});

test('all eligible alternative rows load automatically without duplicates', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $crate = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Crate', 'short_name' => 'crate', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $boxConversion = directStockInConversion($this->product, $box);
    $crateConversion = directStockInConversion($this->product, $crate, ['conversion_factor' => 40]);
    $component = Volt::test('direct-stock-in.index')
        ->set('product_id', (string) $this->product->id)
        ->assertCount('stock_in_lines', 3)
        ->assertSet('stock_in_lines.1.product_unit_conversion_id', (string) $boxConversion->id)
        ->assertSet('stock_in_lines.2.product_unit_conversion_id', (string) $crateConversion->id)
        ->assertSet('stock_in_lines', fn (array $lines): bool => collect($lines)
            ->pluck('product_unit_conversion_id')->filter()->unique()->count() === 2)
        ->assertSee('Box')
        ->assertSee('Crate')
        ->assertDontSee('+ Add Unit');
});

test('blank rows are ignored and at least one positive quantity is required', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = directStockInConversion($this->product, $box);

    $empty = Volt::test('direct-stock-in.index')
        ->set('product_id', (string) $this->product->id)
        ->call('save')
        ->assertHasErrors('stock_in_lines');

    expect(StockMovement::where('movement_type', 'direct_stock_in')->where('product_id', $this->product->id)->exists())->toBeFalse();

    $empty->set('stock_in_lines.0.quantity', '3')
        ->set('reason', 'Direct Purchase')
        ->call('save')
        ->assertHasNoErrors();

    $movements = StockMovement::where('movement_type', 'direct_stock_in')->where('product_id', $this->product->id)->get();
    expect($movements)->toHaveCount(1)
        ->and($movements->first()->product_unit_conversion_id)->toBeNull()
        ->and((float) $movements->first()->quantity_in)->toBe(3.0)
        ->and($movements->where('product_unit_conversion_id', $conversion->id))->toBeEmpty();
});

test('pallet stock in normalizes quantity cost and total value', function () {
    $pallet = Unit::create([
        'company_id' => $this->branch->company_id,
        'name' => 'Pallet',
        'short_name' => 'pallet',
        'measurement_type_id' => $this->product->measurement_type_id,
        'status' => 'active',
    ]);
    $conversion = directStockInConversion($this->product, $pallet, [
        'conversion_factor' => 500,
        'purchase_price' => 250000,
    ]);

    $movement = app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $conversion->id,
        'quantity' => 2,
        'cost_price' => 250000,
    ]), $this->admin->id);

    expect((float) $movement->quantity_in)->toBe(1000.0)
        ->and((float) $movement->unit_cost)->toBe(500.0)
        ->and((float) $movement->quantity_in * (float) $movement->unit_cost)->toBe(500000.0);
});

test('server rejects disallowed inactive foreign and invalid conversion input', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $notPurchasable = directStockInConversion($this->product, $box, ['can_purchase' => false]);

    expect(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $notPurchasable->id,
    ]), $this->admin->id))->toThrow(ValidationException::class);

    $notPurchasable->update(['can_purchase' => true, 'active' => false]);
    expect(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $notPurchasable->id,
    ]), $this->admin->id))->toThrow(ValidationException::class);

    $otherProduct = Product::whereKeyNot($this->product->id)->firstOrFail();
    $foreign = directStockInConversion($otherProduct, $box);
    expect(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $foreign->id,
    ]), $this->admin->id))->toThrow(ValidationException::class)
        ->and(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, ['quantity' => 0]), $this->admin->id))->toThrow(ValidationException::class)
        ->and(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, ['cost_price' => -1]), $this->admin->id))->toThrow(ValidationException::class)
        ->and(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, ['product_unit_conversion_id' => 'not-an-id']), $this->admin->id))->toThrow(ValidationException::class);

    $notPurchasable->forceFill(['active' => true, 'conversion_factor' => 0])->saveQuietly();
    expect(fn () => app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $notPurchasable->id,
    ]), $this->admin->id))->toThrow(ValidationException::class);
});

test('conversion snapshots preserve historical quantity and value after configuration changes', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = directStockInConversion($this->product, $box);
    $movement = app(InventoryService::class)->directStockIn(directStockInData($this, [
        'product_unit_conversion_id' => $conversion->id,
        'quantity' => 10,
        'cost_price' => 30000,
    ]), $this->admin->id);

    $conversion->update(['conversion_factor' => 50, 'purchase_price' => 90000]);
    $movement->refresh();

    expect((float) $movement->transaction_quantity)->toBe(10.0)
        ->and((float) $movement->conversion_factor_snapshot)->toBe(20.0)
        ->and((float) $movement->quantity_in)->toBe(200.0)
        ->and((float) $movement->unit_cost)->toBe(1500.0)
        ->and((float) $movement->quantity_in * (float) $movement->unit_cost)->toBe(300000.0);
});

test('warehouse-disabled component posts normalized stock to dispensing and only lists purchasable active units', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = directStockInConversion($this->product, $box);
    $inactiveUnit = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Inactive Crate', 'short_name' => 'inactive-crate', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $blockedUnit = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Selling Bundle', 'short_name' => 'selling-bundle', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    directStockInConversion($this->product, $inactiveUnit, ['active' => false]);
    directStockInConversion($this->product, $blockedUnit, ['can_purchase' => false]);

    Volt::test('direct-stock-in.index')
        ->call('selectProduct', (string) $this->product->id)
        ->assertSee('Stock Quantities')
        ->assertCount('stock_in_lines', 2)
        ->assertSee($box->name)
        ->assertDontSee($inactiveUnit->name)
        ->assertDontSee($blockedUnit->name)
        ->assertSet('stock_in_lines.1.buying_price', (string) $conversion->purchase_price)
        ->set('stock_in_lines.0.quantity', '1')
        ->set('stock_in_lines.1.quantity', '10')
        ->set('stock_in_lines.1.buying_price', '30000')
        ->set('reason', 'Direct Purchase')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Direct stock in saved.');

    $movements = StockMovement::where('movement_type', 'direct_stock_in')->where('product_id', $this->product->id)->latest()->limit(2)->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('stock_location_id')->unique()->all())->toBe([$this->location->id])
        ->and((float) $movements->sum('quantity_in'))->toBe(201.0)
        ->and((float) $movements->firstWhere('product_unit_conversion_id', $conversion->id)->unit_cost)->toBe(1500.0);
});

test('idempotency key prevents duplicate direct stock movements', function () {
    $data = directStockInData($this);
    $inventory = app(InventoryService::class);

    $first = $inventory->directStockIn($data, $this->admin->id);
    $second = $inventory->directStockIn($data, $this->admin->id);

    expect($second->id)->toBe($first->id)
        ->and(StockMovement::where('idempotency_key', $data['idempotency_key'])->count())->toBe(1)
        ->and((float) StockMovement::where('idempotency_key', $data['idempotency_key'])->sum('quantity_in'))->toBe(100.0);
});

test('weighted average cost remains correct across base box and pallet receipts', function () {
    $box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
    $boxConversion = directStockInConversion($this->product, $box);
    $pallet = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Cost Pallet', 'short_name' => 'cost-pallet', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $palletConversion = directStockInConversion($this->product, $pallet, ['conversion_factor' => 500, 'purchase_price' => 250000]);
    $inventory = app(InventoryService::class);

    $inventory->directStockIn(directStockInData($this, ['quantity' => 100, 'cost_price' => 2000]), $this->admin->id);
    $inventory->directStockIn(directStockInData($this, ['product_unit_conversion_id' => $boxConversion->id, 'quantity' => 10, 'cost_price' => 30000]), $this->admin->id);
    $inventory->directStockIn(directStockInData($this, ['product_unit_conversion_id' => $palletConversion->id, 'quantity' => 2, 'cost_price' => 250000]), $this->admin->id);

    expect($inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe(1300.0)
        ->and($inventory->getAverageCost($this->product->id, $this->location->id, $this->branch->id))->toBe(769.23);
});
