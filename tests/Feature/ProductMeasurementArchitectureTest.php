<?php

use App\Models\Branch;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Support\ProductMeasurementOptions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->update([
        'is_active' => true,
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
    ]);
    $this->actingAs($this->admin);
});

test('standard measurement types are seeded', function () {
    expect(MeasurementType::query()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Count', 'Length', 'Weight', 'Area', 'Volume', 'Other']);
});

test('count products reject decimal sale quantities', function () {
    $product = createMeasuredProductForTest(MeasurementType::COUNT);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 10);

    sellMeasuredProductForTest($product, $this->location, $this->branch, $this->admin, 1.5);
})->throws(ValidationException::class, 'whole quantities');

test('weight products accept a quarter kilogram and deduct stock directly', function () {
    $product = createMeasuredProductForTest(MeasurementType::WEIGHT, [
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.25,
        'quantity_step' => 0.25,
    ]);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 10);

    $sale = sellMeasuredProductForTest($product, $this->location, $this->branch, $this->admin, 0.25);

    expect((float) $sale->items()->firstOrFail()->base_quantity)->toBe(0.25)
        ->and(app(InventoryService::class)->getProductStock($product->id, $this->location->id, $this->branch->id))->toBe(9.75);
});

test('volume products accept half a cubic metre and deduct stock directly', function () {
    $product = createMeasuredProductForTest(MeasurementType::VOLUME, [
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
    ]);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 5);

    $sale = sellMeasuredProductForTest($product, $this->location, $this->branch, $this->admin, 0.5);

    expect((float) $sale->items()->firstOrFail()->base_quantity)->toBe(0.5)
        ->and(app(InventoryService::class)->getProductStock($product->id, $this->location->id, $this->branch->id))->toBe(4.5);
});

test('area products accept decimal quantities', function () {
    $product = createMeasuredProductForTest(MeasurementType::AREA, [
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
    ]);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 10);

    $sale = sellMeasuredProductForTest($product, $this->location, $this->branch, $this->admin, 2.5);

    expect((float) $sale->items()->firstOrFail()->base_quantity)->toBe(2.5);
});

test('length products convert sold metres into base pieces', function () {
    $metre = Unit::query()->where('short_name', 'm')->firstOrFail();
    $product = createMeasuredProductForTest(MeasurementType::LENGTH, [
        'selling_unit_id' => $metre->id,
        'conversion_factor' => 3,
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
    ]);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 10);

    $sale = sellMeasuredProductForTest($product, $this->location, $this->branch, $this->admin, 1.5);

    expect((float) $sale->items()->firstOrFail()->base_quantity)->toBe(0.5)
        ->and(app(InventoryService::class)->getProductStock($product->id, $this->location->id, $this->branch->id))->toBe(9.5);
});

test('product size and conversion factor only appear for length products', function () {
    $length = MeasurementType::where('code', MeasurementType::LENGTH)->firstOrFail();
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();
    $foot = Unit::where('short_name', 'ft')->firstOrFail();

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $length->id)
        ->assertSee('Product Size')
        ->assertDontSee('Conversion Factor')
        ->set('selling_unit_id', (string) $foot->id)
        ->assertSee('Conversion Factor')
        ->set('measurement_type_id', (string) $weight->id)
        ->assertDontSee('Search size, e.g. 2 × 4')
        ->assertDontSee('Conversion Factor')
        ->assertSee('Allow Fraction');
});

test('selling units are filtered by measurement type', function () {
    expect(ProductMeasurementOptions::sellingUnits(MeasurementType::WEIGHT)->pluck('short_name')->all())
        ->toEqualCanonicalizing(['kg', 'g', 'ton'])
        ->and(ProductMeasurementOptions::sellingUnits(MeasurementType::LENGTH)->pluck('short_name')->all())
        ->toEqualCanonicalizing(['m', 'cm', 'mm', 'ft', 'in'])
        ->and(ProductMeasurementOptions::sellingUnits(MeasurementType::VOLUME)->pluck('short_name')->all())
        ->toEqualCanonicalizing(['m³', 'L', 'ml', 'ft³', 'cm³'])
        ->and(ProductMeasurementOptions::sellingUnits(MeasurementType::AREA)->pluck('short_name')->all())
        ->toEqualCanonicalizing(['m²', 'ft²']);
});

test('changing measurement type applies sensible defaults', function () {
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();
    $volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();
    $length = MeasurementType::where('code', MeasurementType::LENGTH)->firstOrFail();
    $count = MeasurementType::where('code', MeasurementType::COUNT)->firstOrFail();
    $kg = Unit::where('short_name', 'kg')->firstOrFail();
    $cubicMetre = Unit::where('short_name', 'm³')->firstOrFail();
    $metre = Unit::where('short_name', 'm')->firstOrFail();

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $weight->id)
        ->assertSet('unit_id', (string) $kg->id)
        ->assertSet('selling_unit_id', (string) $kg->id)
        ->assertSet('allow_fractional_sale', true)
        ->assertSet('minimum_sale_quantity', '0.25')
        ->assertSet('quantity_step', '0.25')
        ->set('measurement_type_id', (string) $volume->id)
        ->assertSet('unit_id', (string) $cubicMetre->id)
        ->assertSet('selling_unit_id', (string) $cubicMetre->id)
        ->assertSet('minimum_sale_quantity', '0.5')
        ->assertSet('quantity_step', '0.5')
        ->set('measurement_type_id', (string) $length->id)
        ->assertSet('unit_id', (string) $metre->id)
        ->assertSet('selling_unit_id', (string) $metre->id)
        ->assertSet('conversion_factor', '1')
        ->set('measurement_type_id', (string) $count->id)
        ->assertSet('allow_fractional_sale', false)
        ->assertDontSee('Fractional Selling');
});

test('product form rejects a selling unit outside the measurement whitelist and requires length conversion', function () {
    $category = Product::query()->firstOrFail()->category;
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();
    $length = MeasurementType::where('code', MeasurementType::LENGTH)->firstOrFail();
    $metre = Unit::where('short_name', 'm')->firstOrFail();
    $foot = Unit::where('short_name', 'ft')->firstOrFail();

    $component = Volt::test('products.create')
        ->set('category_id', (string) $category->id)
        ->set('name', 'Filtered Unit Product')
        ->set('measurement_type_id', (string) $weight->id)
        ->set('selling_unit_id', (string) $metre->id)
        ->call('save')
        ->assertHasErrors(['selling_unit_id']);

    $component
        ->set('measurement_type_id', (string) $length->id)
        ->set('unit_id', (string) $metre->id)
        ->set('selling_unit_id', (string) $foot->id)
        ->call('save')
        ->assertHasErrors(['conversion_factor']);
});

test('purchases receive decimal stock in the product base unit', function () {
    $product = createMeasuredProductForTest(MeasurementType::VOLUME, [
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
    ]);
    $supplier = Supplier::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Measurement Supplier',
        'phone' => '255700123456',
        'status' => 'active',
    ]);
    $purchase = Purchase::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'purchase_date' => today(),
        'reference_number' => 'MEASURE-PURCHASE-1',
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'total_amount' => 50,
        'paid_amount' => 0,
        'balance_amount' => 50,
        'created_by' => $this->admin->id,
    ]);
    $item = $purchase->items()->create([
        'company_id' => $this->branch->company_id,
        'product_id' => $product->id,
        'ordered_quantity' => 0.5,
        'received_quantity' => 0,
        'cost_price' => 100,
        'selling_price' => 120,
        'line_total' => 50,
    ]);

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => ['quantity' => 0.5, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    expect(app(InventoryService::class)->getProductStock($product->id, $this->location->id, $this->branch->id))->toBe(0.5);
});

test('purchase form rejects decimal count quantities and accepts decimal weight quantities', function () {
    $supplier = Supplier::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Purchase Measurement Supplier',
        'phone' => '255700654321',
        'status' => 'active',
    ]);
    $countProduct = createMeasuredProductForTest(MeasurementType::COUNT);
    $weightProduct = createMeasuredProductForTest(MeasurementType::WEIGHT, [
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.25,
        'quantity_step' => 0.25,
    ]);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->set('items', [[
            'product_id' => (string) $countProduct->id,
            'ordered_quantity' => '1.5',
            'cost_price' => '1',
            'selling_price' => '10',
            'line_total' => 0,
        ]])
        ->call('savePurchase', 'ordered')
        ->assertHasErrors(['items.0.ordered_quantity']);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->set('items', [[
            'product_id' => (string) $weightProduct->id,
            'ordered_quantity' => '0.25',
            'cost_price' => '1',
            'selling_price' => '10',
            'line_total' => 0,
        ]])
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors(['items.0.ordered_quantity']);

    expect(Purchase::query()->whereHas('items', fn ($query) => $query
        ->where('product_id', $weightProduct->id)
        ->where('ordered_quantity', 0.25))->exists())->toBeTrue();
});

test('stock valuation reports measurement type size unit and stock', function () {
    $product = createMeasuredProductForTest(MeasurementType::WEIGHT, ['allow_fractional_sale' => true]);
    stockMeasuredProductForTest($product, $this->location, $this->admin, 125.5);

    $row = collect(app(FinancialReportService::class)->stockValuation($this->branch->id))
        ->firstWhere('product', $product->name);

    expect($row)->not->toBeNull()
        ->and($row['measurement_type'])->toBe('Weight')
        ->and($row['size'])->toBeNull()
        ->and($row['unit'])->toBe($product->unit->short_name)
        ->and($row['quantity'])->toBe(125.5);
});

function createMeasuredProductForTest(string $measurementCode, array $overrides = []): Product
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $unit = Unit::query()->where('short_name', match ($measurementCode) {
        MeasurementType::WEIGHT => 'kg',
        MeasurementType::AREA => 'm²',
        MeasurementType::VOLUME => 'm³',
        default => 'pcs',
    })->firstOrFail();
    $type = MeasurementType::query()->where('code', $measurementCode)->firstOrFail();

    return Product::query()->create(array_merge([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'category_id' => Product::query()->firstOrFail()->category_id,
        'measurement_type_id' => $type->id,
        'unit_id' => $unit->id,
        'selling_unit_id' => $unit->id,
        'name' => str($measurementCode)->title().' Test Product '.uniqid(),
        'sku' => 'MEASURE-'.strtoupper($measurementCode).'-'.uniqid(),
        'buying_price' => 1,
        'selling_price' => 10,
        'conversion_factor' => 1,
        'allow_fractional_sale' => false,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'reorder_level' => 1,
        'status' => 'active',
    ], $overrides))->load(['category', 'measurementType', 'unit', 'sellingUnit']);
}

function stockMeasuredProductForTest(Product $product, StockLocation $location, User $user, float $quantity): void
{
    StockMovement::query()->create([
        'company_id' => $product->company_id,
        'branch_id' => $location->branch_id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => $quantity,
        'quantity_in' => $quantity,
        'quantity_out' => 0,
        'unit_cost' => 1,
        'unit_price' => 10,
        'created_by' => $user->id,
        'movement_date' => today(),
    ]);
}

function sellMeasuredProductForTest(Product $product, StockLocation $location, Branch $branch, User $user, float $quantity)
{
    return app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->selling_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => $quantity * (float) $product->selling_price, 'reference_number' => null]],
        null,
        $location->id,
        $branch->id,
        $user->id,
    );
}
