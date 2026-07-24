<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->update(['is_active' => true, 'can_sell' => true, 'can_receive_stock' => true]);
    $this->actingAs($this->admin);
});

test('existing sized products retain their selected size and are treated as using product sizes', function () {
    [$product, $size] = backwardCompatibleSizedProduct('Flat Bar');

    $product->forceFill(['uses_product_size' => false])->save();
    $product->refresh()->load(['category', 'measurementType', 'size']);

    expect($product->product_size_id)->toBe($size->id)
        ->and($product->usesProductSize())->toBeTrue()
        ->and($product->sizeLabel())->toBe('2 × 4 (2mm)')
        ->and(ProductSize::query()->whereKey($size->id)->exists())->toBeTrue();
});

test('length products show the size selector while weight products hide it by default', function () {
    $length = MeasurementType::where('code', MeasurementType::LENGTH)->firstOrFail();
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $length->id)
        ->assertSet('uses_product_size', true)
        ->assertSee('Search size, e.g. 2 × 4')
        ->set('measurement_type_id', (string) $weight->id)
        ->assertSet('uses_product_size', false)
        ->assertDontSee('Search size, e.g. 2 × 4');
});

test('a weight product can be created without a product size', function () {
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();
    $category = Category::query()->where('supports_product_sizes', false)->firstOrFail();

    Volt::test('products.create')
        ->set('category_id', (string) $category->id)
        ->set('measurement_type_id', (string) $weight->id)
        ->set('name', 'Nails by Weight')
        ->set('buying_price', '1000')
        ->set('selling_price', '1500')
        ->set('reorder_level', '5')
        ->call('save')
        ->assertHasNoErrors(['product_size_id', 'uses_product_size']);

    $product = Product::where('name', 'Nails by Weight')->firstOrFail();

    expect($product->product_size_id)->toBeNull()
        ->and($product->uses_product_size)->toBeFalse();
});

test('editing an old sized product does not clear its size', function () {
    [$product, $size] = backwardCompatibleSizedProduct('Channel');

    Volt::test('products.edit', ['product' => $product])
        ->set('name', 'Channel Updated')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->product_size_id)->toBe($size->id)
        ->and($product->uses_product_size)->toBeTrue();
});

test('POS and purchase forms display size based product labels', function () {
    [$product] = backwardCompatibleSizedProduct('Square Tube');
    stockBackwardCompatibleProduct($product, $this->location, $this->admin);
    $supplier = Supplier::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Size Product Supplier',
        'phone' => '255700123456',
        'status' => 'active',
    ]);

    Volt::test('pos.index')
        ->set('branch_id', (string) $this->branch->id)
        ->set('stock_location_id', (string) $this->location->id)
        ->set('search', 'Square Tube')
        ->assertSee('Size')
        ->assertSee('2 × 4 (2mm)');

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->assertSee('Square Tube - 2 × 4 (2mm)');
});

test('stock reports retain product sizes', function () {
    [$product] = backwardCompatibleSizedProduct('Angle Iron');
    stockBackwardCompatibleProduct($product, $this->location, $this->admin);

    $rows = collect(app(FinancialReportService::class)->stockValuation($this->branch->id));
    $row = $rows->firstWhere('product', 'Angle Iron');

    expect($row)->not->toBeNull()
        ->and($row['size'])->toBe('2 × 4 (2mm)');
});

test('historical sales retain their original product size after the product changes', function () {
    [$product, $originalSize] = backwardCompatibleSizedProduct('Flat Bar Historical');
    $replacementSize = ProductSize::query()->firstOrCreate([
        'company_id' => $this->branch->company_id,
        'symbol' => '3 × 3',
    ], [
        'name' => '3 × 3',
        'status' => 'active',
    ]);
    stockBackwardCompatibleProduct($product, $this->location, $this->admin);

    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->selling_price,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => $product->selling_price, 'reference_number' => null]],
        null,
        $this->location->id,
        $this->branch->id,
        $this->admin->id,
    );

    $product->update(['product_size_id' => $replacementSize->id]);
    $saleItem = $sale->items()->with(['product.size', 'productSize'])->firstOrFail();

    expect($saleItem->product_size_id)->toBe($originalSize->id)
        ->and($saleItem->sizeLabel())->toBe('2 × 4 (2mm)')
        ->and($saleItem->productDisplayNameWithSize())->toContain('Flat Bar Historical - 2 × 4 (2mm)');
});

function backwardCompatibleSizedProduct(string $name): array
{
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $length = MeasurementType::where('code', MeasurementType::LENGTH)->firstOrFail();
    $unit = Unit::where('short_name', 'pcs')->firstOrFail();
    $category = Category::query()->firstOrFail();
    $size = ProductSize::query()->firstOrCreate([
        'company_id' => $branch->company_id,
        'symbol' => '2 × 4 (2mm)',
    ], [
        'name' => '2 × 4 (2mm)',
        'status' => 'active',
    ]);

    $product = Product::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'measurement_type_id' => $length->id,
        'unit_id' => $unit->id,
        'selling_unit_id' => $unit->id,
        'product_size_id' => $size->id,
        'uses_product_size' => true,
        'name' => $name,
        'sku' => 'SIZE-'.str()->uuid(),
        'buying_price' => 1000,
        'selling_price' => 1500,
        'conversion_factor' => 1,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'reorder_level' => 1,
        'taxable' => false,
        'status' => 'active',
    ]);

    return [$product->load(['category', 'measurementType', 'size', 'unit', 'sellingUnit']), $size];
}

function stockBackwardCompatibleProduct(Product $product, StockLocation $location, User $user): void
{
    StockMovement::query()->create([
        'company_id' => $product->company_id,
        'branch_id' => $location->branch_id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 1000,
        'unit_price' => 1500,
        'created_by' => $user->id,
        'movement_date' => today(),
    ]);
}
