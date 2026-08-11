<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\ProductUnitConversionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->inventory = app(InventoryService::class);
    $this->location = $this->inventory->getDispensingLocation($this->branch->id);
    $this->location->update(['is_active' => true, 'is_sellable' => true, 'can_sell' => true, 'can_receive_stock' => true]);
    $this->product = Product::where('sku', 'BM-MAB-G28')->firstOrFail();
    $this->product->update(['buying_price' => 100, 'selling_price' => 150, 'wholesale_price' => 130]);
    $this->box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();
});

test('generic alternatives are product specific active and directly normalized to base stock', function () {
    $service = app(ProductUnitConversionService::class);
    $dozen = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Dozen', 'short_name' => 'doz', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $bundle = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bundle')->firstOrFail();
    $service->sync($this->product, [
        ['unit_id' => $this->box->id, 'conversion_factor' => 12, 'retail_price' => 1500, 'wholesale_price' => 1400, 'purchase_price' => 1200, 'can_purchase' => true, 'can_sell' => true, 'active' => true],
        ['unit_id' => $dozen->id, 'conversion_factor' => 12, 'can_purchase' => false, 'can_sell' => true, 'active' => true],
        ['unit_id' => $bundle->id, 'conversion_factor' => 20, 'can_purchase' => true, 'can_sell' => true, 'active' => true],
    ]);

    $conversion = $this->product->unitConversions()->where('unit_id', $this->box->id)->firstOrFail();

    expect($conversion->baseQuantity(2))->toBe(24.0)
        ->and($this->product->unitConversions()->count())->toBe(3)
        ->and($service->resolveForSale($this->product, $conversion->id)->id)->toBe($conversion->id)
        ->and($service->resolveForPurchase($this->product, $conversion->id)->id)->toBe($conversion->id);

    $conversion->update(['active' => false]);

    expect(fn () => $service->resolveForSale($this->product, $conversion->id))
        ->toThrow(ValidationException::class);

    $ton = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'ton')->firstOrFail();
    expect(fn () => ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $ton->id,
        'conversion_factor' => 1000,
        'can_sell' => true,
        'active' => true,
    ]))->toThrow(ValidationException::class, 'incompatible');
});

test('an existing product can add and later deactivate a conversion from edit product', function () {
    $component = Volt::test('products.edit', ['product' => $this->product])
        ->assertSee('Unit Conversions & Pricing')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && $rows === [])
        ->assertSee('No alternative units configured. Base-unit buying and selling continue to work as before.')
        ->call('addUnitConversion')
        ->set('unit_conversions.0.unit_id', (string) $this->box->id)
        ->set('unit_conversions.0.conversion_factor', '12')
        ->set('unit_conversions.0.retail_price', '1500')
        ->set('unit_conversions.0.wholesale_price', '1400')
        ->set('unit_conversions.0.purchase_price', '1200')
        ->set('unit_conversions.0.can_purchase', true)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->assertSee('Box contains')
        ->assertSee('1 Box =')
        ->assertSee('Enter how many pcs are contained in one Box.')
        ->assertSee('Retail Price / Box')
        ->assertSee('Wholesale Price / Box')
        ->assertSee('Purchase Price / Box')
        ->assertSeeHtml('aria-label="How many pcs are contained in 1 Box"')
        ->assertDontSee('Conversion to Base');

    $bundle = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bundle')->firstOrFail();
    $component
        ->set('unit_conversions.0.unit_id', (string) $bundle->id)
        ->assertSee('Bundle contains')
        ->assertSee('Retail Price / Bundle')
        ->assertSeeHtml('aria-label="How many pcs are contained in 1 Bundle"')
        ->set('unit_conversions.0.unit_id', (string) $this->box->id)
        ->call('save')
        ->assertHasNoErrors();

    $conversion = $this->product->unitConversions()->firstOrFail();
    expect((float) $conversion->conversion_factor)->toBe(12.0)
        ->and($conversion->can_purchase)->toBeTrue()
        ->and($conversion->can_sell)->toBeTrue();

    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();
    Volt::test('products.edit', ['product' => $this->product->fresh()])
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->assertSee('Box contains')
        ->assertSee('Retail Price / Box')
        ->set('unit_id', (string) $bag->id)
        ->assertSeeHtml('aria-label="How many bag are contained in 1 Box"')
        ->call('addUnitConversion')
        ->assertCount('unit_conversions', 2)
        ->call('removeUnitConversion', 1)
        ->assertCount('unit_conversions', 1)
        ->set('unit_conversions.0.active', false)
        ->set('unit_id', (string) $this->product->unit_id)
        ->call('save')
        ->assertHasNoErrors();

    expect($conversion->fresh()->active)->toBeFalse();
});

test('create product uses the same dynamic human readable conversion labels', function () {
    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $this->product->measurement_type_id)
        ->set('unit_id', (string) $this->product->unit_id)
        ->set('unit_conversions', [[
            'unit_id' => (string) $this->box->id,
            'conversion_factor' => '12',
            'retail_price' => '',
            'wholesale_price' => '',
            'purchase_price' => '',
            'can_purchase' => true,
            'can_sell' => true,
            'active' => true,
        ]])
        ->assertSee('Box contains')
        ->assertSee('1 Box =')
        ->assertSee('Retail Price / Box')
        ->assertSeeHtml('aria-label="How many pcs are contained in 1 Box"')
        ->set('unit_id', (string) $bag->id)
        ->assertSeeHtml('aria-label="How many bag are contained in 1 Box"')
        ->assertDontSee('Conversion to Base');
});

test('base unit pricing labels are explicit and update with the base stock unit', function () {
    $kg = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'kg')->firstOrFail();

    Volt::test('products.edit', ['product' => $this->product])
        ->assertSee('Base Unit Pricing')
        ->assertSee('Base Stock Unit:')
        ->assertSee('Buying Price / pc')
        ->assertSee('Retail Price / pc')
        ->assertSee('Wholesale Price / pc')
        ->assertDontSee('Selling Price')
        ->set('measurement_type_id', (string) $kg->measurement_type_id)
        ->set('unit_id', (string) $kg->id)
        ->assertSee('Buying Price / kg')
        ->assertSee('Retail Price / kg')
        ->assertSee('Wholesale Price / kg');
});

test('package prices show references warnings and respect purchase and sale flags', function () {
    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();

    $component = Volt::test('products.edit', ['product' => $this->product])
        ->set('buying_price', '15000')
        ->set('selling_price', '20000')
        ->set('wholesale_price', '19000')
        ->set('unit_conversions', [[
            'unit_id' => (string) $bag->id,
            'conversion_factor' => '50',
            'retail_price' => '15000',
            'wholesale_price' => '15000',
            'purchase_price' => '15000',
            'can_purchase' => true,
            'can_sell' => true,
            'active' => true,
        ]])
        ->assertSee('Purchase Price / Bag')
        ->assertSee('Retail Price / Bag')
        ->assertSee('Wholesale Price / Bag')
        ->assertSee('Base-cost equivalent: TZS 750,000 / Bag')
        ->assertSee('Base-price equivalent: TZS 1,000,000 / Bag')
        ->assertSee('Base-price equivalent: TZS 950,000 / Bag')
        ->assertSee('This means an effective cost of TZS 300 / pc')
        ->assertSeeHtml('data-price-field="purchase" data-enabled="true"')
        ->assertSeeHtml('data-price-field="retail" data-enabled="true"');

    $component
        ->set('unit_conversions.0.can_purchase', false)
        ->set('unit_conversions.0.can_sell', false)
        ->assertSeeHtml('data-price-field="purchase" data-enabled="false"')
        ->assertSeeHtml('data-price-field="retail" data-enabled="false"')
        ->assertSeeHtml('data-price-field="wholesale" data-enabled="false"');
});

test('purchase selection uses package purchase price and rejects a purchase-disabled unit', function () {
    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $bag->id,
        'conversion_factor' => 50,
        'purchase_price' => 700000,
        'can_purchase' => true,
        'can_sell' => false,
        'active' => true,
    ]);
    $supplier = Supplier::create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'name' => 'Bag Supplier', 'phone' => '0700111222', 'opening_balance' => 0, 'status' => 'active']);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('items.0.product_unit_conversion_id', (string) $conversion->id)
        ->assertSet('items.0.purchase_conversion_factor', '50.0000')
        ->assertSet('items.0.cost_price', 700000.0);

    $conversion->update(['can_purchase' => false]);
    expect(fn () => app(ProductUnitConversionService::class)->resolveForPurchase($this->product, $conversion->id))
        ->toThrow(ValidationException::class);
});

test('retail and wholesale POS use package prices while deducting canonical base stock', function () {
    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $bag->id,
        'conversion_factor' => 50,
        'retail_price' => 950000,
        'wholesale_price' => 900000,
        'can_purchase' => false,
        'can_sell' => true,
        'active' => true,
    ]);
    StockMovement::create([
        'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'stock_location_id' => $this->location->id, 'movement_type' => 'direct_stock_in', 'quantity' => 150,
        'quantity_in' => 150, 'quantity_out' => 0, 'unit_cost' => 100, 'unit_price' => 150,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);

    $retail = $this->inventory->completeSale(
        [['product_id' => $this->product->id, 'product_unit_conversion_id' => $conversion->id, 'sale_type' => 'retail', 'quantity' => 1, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 950000]], null, $this->location->id, $this->branch->id, $this->admin->id,
    );
    $wholesale = $this->inventory->completeSale(
        [['product_id' => $this->product->id, 'product_unit_conversion_id' => $conversion->id, 'sale_type' => 'wholesale', 'quantity' => 1, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 900000]], null, $this->location->id, $this->branch->id, $this->admin->id,
    );

    expect((float) $retail->items()->firstOrFail()->unit_price)->toBe(950000.0)
        ->and((float) $wholesale->items()->firstOrFail()->unit_price)->toBe(900000.0)
        ->and((float) $retail->items()->firstOrFail()->base_quantity)->toBe(50.0)
        ->and((float) $wholesale->items()->firstOrFail()->base_quantity)->toBe(50.0)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe(50.0);

    $conversion->update(['can_sell' => false]);
    expect(fn () => app(ProductUnitConversionService::class)->resolveForSale($this->product, $conversion->id))
        ->toThrow(ValidationException::class);
});

test('create product adds exactly one removable alternative unit row per action', function () {
    Volt::test('products.create')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && $rows === [])
        ->assertSee('No alternative units configured. Base-unit buying and selling continue to work as before.')
        ->set('unit_conversions', null)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && $rows === [])
        ->call('addUnitConversion')
        ->assertCount('unit_conversions', 1)
        ->assertSet('unit_conversions.0', [
            'unit_id' => null,
            'conversion_factor' => null,
            'retail_price' => null,
            'wholesale_price' => null,
            'purchase_price' => null,
            'can_purchase' => false,
            'can_sell' => true,
            'active' => true,
        ])
        ->assertSee('Contains')
        ->assertSee('Retail Price')
        ->assertDontSee('Retail Price / Alternative unit')
        ->call('addUnitConversion')
        ->assertCount('unit_conversions', 2)
        ->call('removeUnitConversion', 0)
        ->assertCount('unit_conversions', 1)
        ->assertSet('unit_conversions.0.unit_id', null)
        ->assertSet('unit_conversions.0.can_purchase', false)
        ->assertSet('unit_conversions.0.can_sell', true)
        ->assertSet('unit_conversions.0.active', true);
});

test('create product saves with a valid alternative unit conversion', function () {
    $bag = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bag')->firstOrFail();
    $bundle = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'bundle')->firstOrFail();

    Volt::test('products.create')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && $rows === [])
        ->set('branch_id', (string) $this->branch->id)
        ->set('category_id', (string) $this->product->category_id)
        ->set('measurement_type_id', (string) $this->product->measurement_type_id)
        ->set('purchase_unit_id', (string) $this->product->unit_id)
        ->set('unit_id', (string) $this->product->unit_id)
        ->set('selling_unit_id', (string) $this->product->unit_id)
        ->set('name', 'Create Product With Bag')
        ->set('sku', 'CREATE-WITH-BAG')
        ->set('buying_price', '100')
        ->set('selling_price', '150')
        ->set('reorder_level', '0')
        ->call('addUnitConversion')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.unit_id', (string) $bag->id)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.conversion_factor', '50')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.retail_price', '1500')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.wholesale_price', '1400')
        ->set('unit_conversions.0.purchase_price', '1200')
        ->set('unit_conversions.0.can_purchase', true)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.can_sell', false)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.can_sell', true)
        ->set('unit_conversions.0.active', false)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->set('unit_conversions.0.active', true)
        ->call('addUnitConversion')
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 2)
        ->set('unit_conversions.1.unit_id', (string) $bundle->id)
        ->set('unit_conversions.1.conversion_factor', '20')
        ->call('removeUnitConversion', 1)
        ->assertSet('unit_conversions', fn ($rows) => is_array($rows) && count($rows) === 1)
        ->assertSet('unit_conversions.0.unit_id', (string) $bag->id)
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('sku', 'CREATE-WITH-BAG')->firstOrFail();
    $conversion = $product->unitConversions()->firstOrFail();

    expect($conversion->unit_id)->toBe($bag->id)
        ->and((float) $conversion->conversion_factor)->toBe(50.0)
        ->and((float) $conversion->retail_price)->toBe(1500.0)
        ->and($conversion->can_purchase)->toBeTrue()
        ->and($conversion->can_sell)->toBeTrue()
        ->and($conversion->active)->toBeTrue();
});

test('create product saves with no alternative unit conversions', function () {
    Volt::test('products.create')
        ->assertCount('unit_conversions', 0)
        ->set('branch_id', (string) $this->branch->id)
        ->set('category_id', (string) $this->product->category_id)
        ->set('measurement_type_id', (string) $this->product->measurement_type_id)
        ->set('purchase_unit_id', (string) $this->product->unit_id)
        ->set('unit_id', (string) $this->product->unit_id)
        ->set('selling_unit_id', (string) $this->product->unit_id)
        ->set('name', 'Create Product Without Conversion')
        ->set('sku', 'CREATE-NO-CONVERSION')
        ->set('buying_price', '100')
        ->set('selling_price', '150')
        ->set('reorder_level', '0')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('sku', 'CREATE-NO-CONVERSION')->firstOrFail();

    expect($product->unitConversions()->count())->toBe(0);
});

test('multiple package rows retain independent contained quantities and labels', function () {
    $pack = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'pack')->firstOrFail();
    $dozen = Unit::create(['company_id' => $this->branch->company_id, 'name' => 'Dozen', 'short_name' => 'doz', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);

    Volt::test('products.edit', ['product' => $this->product])
        ->set('unit_conversions', [
            ['unit_id' => (string) $pack->id, 'conversion_factor' => '12', 'retail_price' => '', 'wholesale_price' => '', 'purchase_price' => '', 'can_purchase' => true, 'can_sell' => true, 'active' => true],
            ['unit_id' => (string) $this->box->id, 'conversion_factor' => '24', 'retail_price' => '', 'wholesale_price' => '', 'purchase_price' => '', 'can_purchase' => true, 'can_sell' => true, 'active' => true],
            ['unit_id' => (string) $dozen->id, 'conversion_factor' => '12', 'retail_price' => '', 'wholesale_price' => '', 'purchase_price' => '', 'can_purchase' => false, 'can_sell' => true, 'active' => true],
        ])
        ->assertSee('Pack contains')
        ->assertSee('Box contains')
        ->assertSee('Dozen contains')
        ->assertSee('Retail Price / Pack')
        ->assertSee('Retail Price / Box')
        ->assertSee('Retail Price / Dozen')
        ->assertSet('unit_conversions.0.conversion_factor', '12')
        ->assertSet('unit_conversions.1.conversion_factor', '24')
        ->assertSet('unit_conversions.2.conversion_factor', '12');
});

test('duplicate units zero factors and negative prices are rejected', function () {
    Volt::test('products.edit', ['product' => $this->product])
        ->set('buying_price', '-1')
        ->set('selling_price', '-2')
        ->set('wholesale_price', '-3')
        ->set('unit_conversions', [
            ['unit_id' => (string) $this->box->id, 'conversion_factor' => '0', 'retail_price' => '-1', 'wholesale_price' => '-2', 'purchase_price' => '-3', 'can_purchase' => true, 'can_sell' => true, 'active' => true],
            ['unit_id' => (string) $this->box->id, 'conversion_factor' => '12', 'retail_price' => '', 'wholesale_price' => '', 'purchase_price' => '', 'can_purchase' => false, 'can_sell' => true, 'active' => true],
        ])
        ->call('save')
        ->assertHasErrors([
            'buying_price' => 'min',
            'selling_price' => 'min',
            'wholesale_price' => 'min',
            'unit_conversions.0.unit_id' => 'distinct',
            'unit_conversions.1.unit_id' => 'distinct',
            'unit_conversions.0.conversion_factor' => 'gt',
            'unit_conversions.0.retail_price' => 'min',
            'unit_conversions.0.wholesale_price' => 'min',
            'unit_conversions.0.purchase_price' => 'min',
        ]);
});

test('purchase and partial receipts retain conversion snapshots and post only base stock', function () {
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $this->box->id,
        'conversion_factor' => 12,
        'purchase_price' => 1200,
        'can_purchase' => true,
        'can_sell' => false,
        'active' => true,
    ]);
    $supplier = Supplier::create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Package Supplier',
        'phone' => '0700000000',
        'opening_balance' => 0,
        'status' => 'active',
    ]);
    $purchase = Purchase::create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'purchase_date' => today(),
        'reference_number' => 'PO-UNIT-001',
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'total_amount' => 12000,
        'paid_amount' => 0,
        'balance_amount' => 12000,
        'created_by' => $this->admin->id,
    ]);
    $item = $purchase->items()->create([
        'product_id' => $this->product->id,
        'product_unit_conversion_id' => $conversion->id,
        'purchase_unit_id' => $this->box->id,
        'stock_unit_id' => $this->product->unit_id,
        'purchase_conversion_factor' => 12,
        'purchase_unit_name_snapshot' => $this->box->name,
        'purchase_unit_code_snapshot' => $this->box->short_name,
        'stock_unit_name_snapshot' => $this->product->unit->name,
        'stock_unit_code_snapshot' => $this->product->unit->short_name,
        'ordered_quantity' => 10,
        'base_ordered_quantity' => 120,
        'received_quantity' => 0,
        'base_received_quantity' => 0,
        'cost_price' => 1200,
        'selling_price' => 150,
        'line_total' => 12000,
    ]);

    $first = $this->inventory->receivePurchase($purchase, [$item->id => ['quantity' => 8, 'stock_location_id' => $this->location->id]], today()->toDateString(), $this->admin->id);
    expect((float) $first->items()->firstOrFail()->stock_quantity)->toBe(96.0)
        ->and((float) $item->refresh()->base_received_quantity)->toBe(96.0)
        ->and($purchase->refresh()->status)->toBe('ordered');

    $second = $this->inventory->receivePurchase($purchase, [$item->id => ['quantity' => 2, 'stock_location_id' => $this->location->id]], today()->toDateString(), $this->admin->id);
    expect((float) $second->items()->firstOrFail()->stock_quantity)->toBe(24.0)
        ->and((float) $item->refresh()->base_received_quantity)->toBe(120.0)
        ->and($purchase->refresh()->status)->toBe('received')
        ->and((float) StockMovement::where('product_id', $this->product->id)->where('movement_type', 'purchase_receipt')->sum('quantity_in'))->toBe(120.0);

    $conversion->update(['purchase_price' => 999999]);
    $this->product->update(['buying_price' => 777]);
    expect((float) $item->refresh()->cost_price)->toBe(1200.0);

    $this->get('/reports/purchases')->assertOk()->assertSee('Base Qty Ordered')->assertSee('Received / Base Received');
});

test('retail wholesale mixed unit sales and cancellation use immutable base snapshots', function () {
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $this->box->id,
        'conversion_factor' => 12,
        'retail_price' => 1500,
        'wholesale_price' => 1400,
        'can_purchase' => false,
        'can_sell' => true,
        'active' => true,
    ]);
    StockMovement::create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 100,
        'quantity_in' => 100,
        'quantity_out' => 0,
        'unit_cost' => 100,
        'unit_price' => 150,
        'created_by' => $this->admin->id,
        'movement_date' => today(),
    ]);

    $sale = $this->inventory->completeSale([
        ['product_id' => $this->product->id, 'product_unit_conversion_id' => $conversion->id, 'sale_type' => 'wholesale', 'quantity' => 2, 'discount_amount' => 0, 'tax_amount' => 0],
        ['product_id' => $this->product->id, 'selling_unit_id' => $this->product->unit_id, 'sale_type' => 'retail', 'quantity' => 3, 'discount_amount' => 0, 'tax_amount' => 0],
    ], [['payment_method' => 'cash', 'amount' => 3250]], null, $this->location->id, $this->branch->id, $this->admin->id);

    expect($sale->items()->count())->toBe(2)
        ->and((float) $sale->items()->sum('base_quantity'))->toBe(27.0)
        ->and((float) $sale->items()->whereNotNull('product_unit_conversion_id')->firstOrFail()->unit_price)->toBe(1400.0)
        ->and((float) StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->sum('quantity_out'))->toBe(27.0);

    $profit = app(FinancialReportService::class)->profitLoss($this->branch->id, today()->toDateString(), today()->toDateString());
    expect($profit['cogs'])->toBe(2700.0)
        ->and($profit['gross_profit'])->toBe(550.0);
    $this->get('/reports/sales')->assertOk()->assertSee('Base Qty Sold')->assertSee('COGS');

    $conversion->update(['retail_price' => 10, 'wholesale_price' => 10]);
    $this->product->update(['selling_price' => 10, 'wholesale_price' => 10]);
    expect((float) $sale->items()->whereNotNull('conversion_factor_to_base')->firstOrFail()->unit_price)->toBe(1400.0);
    $conversion->delete();
    expect((float) $sale->items()->whereNotNull('conversion_factor_to_base')->firstOrFail()->conversion_factor_to_base)->toBe(12.0);
    $this->inventory->cancelSale($sale->id, $this->admin->id);

    expect((float) StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'return_in')->sum('quantity_in'))->toBe(27.0)
        ->and(fn () => $this->inventory->cancelSale($sale->id, $this->admin->id))->toThrow(ValidationException::class)
        ->and(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'return_in')->count())->toBe(2);
});

test('base stock unit cannot change after any stock movement', function () {
    StockMovement::create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 1,
        'quantity_in' => 1,
        'unit_cost' => 100,
        'created_by' => $this->admin->id,
        'movement_date' => today(),
    ]);

    expect(fn () => $this->product->update(['unit_id' => $this->box->id]))
        ->toThrow(ValidationException::class, 'Base Stock Unit cannot be changed');
});
