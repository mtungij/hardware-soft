<?php

use App\Models\Branch;
use App\Models\InternalSale;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InternalSaleService;
use App\Services\LocationPriceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill([
        'name' => 'Fallback Mabati', 'sku' => 'INTERNAL-FALLBACK-'.str()->random(5),
        'barcode' => null, 'buying_price' => 16500, 'selling_price' => 29500,
    ]);
    $this->product->save();
    $this->source = fallbackLocation($this->admin, $this->branch, 'Mombasa Godown');
    $this->destination = fallbackLocation($this->admin, $this->branch, 'Lukman');
    StockMovement::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->source->id,
        'movement_type' => 'purchase_receipt', 'quantity' => 100, 'unit_cost' => 16500,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
});

function fallbackLocation(User $user, Branch $branch, string $name): StockLocation
{
    return StockLocation::create([
        'company_id' => $user->company_id, 'branch_id' => $branch->id,
        'name' => $name, 'code' => 'FB-'.str()->random(8), 'type' => 'store',
        'status' => 'active', 'is_active' => true,
        'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true,
        'can_sell' => true, 'is_sellable' => true,
    ]);
}

function fallbackSaleData(object $test, float $price, ?ProductUnitConversion $conversion = null): array
{
    return [
        'branch_id' => $test->branch->id,
        'from_location_id' => $test->source->id,
        'to_location_id' => $test->destination->id,
        'internal_sale_number' => 'FB-'.str()->random(10),
        'sale_date' => today()->toDateString(),
        'items' => [[
            'product_id' => $test->product->id,
            'product_unit_conversion_id' => $conversion?->id,
            'quantity' => 1,
            'internal_unit_price' => $price,
        ]],
    ];
}

test('source internal price wins over product default and its source is snapshotted', function () {
    $prices = app(LocationPriceService::class);
    $prices->savePrices($this->product, $this->source, null, ['internal_sale_price' => 27000]);
    $resolved = $prices->internalPriceFor($this->product, $this->source);
    expect($resolved)->toBe(['price' => 27000.0, 'source' => $this->source->name.' Internal Price']);
    $sale = app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 27000), $this->admin);
    expect((float) $sale->items()->first()->internal_unit_price)->toBe(27000.0)
        ->and($sale->items()->first()->price_source)->toBe($this->source->name.' Internal Price');
});

test('blank location price falls back to product selling price without using buying price', function () {
    $resolved = app(LocationPriceService::class)->internalPriceFor($this->product, $this->source);
    expect($resolved)->toBe(['price' => 29500.0, 'source' => 'Product Default Selling Price']);
    $sale = app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 29500), $this->admin);
    expect($sale->items()->first()->price_source)->toBe('Product Default Selling Price');
    $this->product->update(['selling_price' => 0]);
    expect(app(LocationPriceService::class)->internalPriceFor($this->product->fresh(), $this->source))
        ->toBe(['price' => null, 'source' => null]);
});

test('alternative unit uses its retail price and location alternative-unit override wins', function () {
    $unit = Unit::create([
        'company_id' => $this->admin->company_id, 'name' => 'Fallback Box',
        'short_name' => 'fb-box', 'measurement_type_id' => $this->product->measurement_type_id,
        'status' => 'active',
    ]);
    $box = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $unit->id, 'conversion_factor' => 12, 'retail_price' => 340000,
        'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
    $prices = app(LocationPriceService::class);
    expect($prices->internalPriceFor($this->product, $this->source, $box))
        ->toBe(['price' => 340000.0, 'source' => 'Product Unit Default Price']);
    $prices->savePrices($this->product, $this->source, $box, ['internal_sale_price' => 320000]);
    expect($prices->internalPriceFor($this->product, $this->source, $box))
        ->toBe(['price' => 320000.0, 'source' => $this->source->name.' Internal Price']);
    $sale = app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 320000, $box), $this->admin);
    expect((float) $sale->items()->first()->base_quantity)->toBe(12.0)
        ->and($sale->items()->first()->price_source)->toBe($this->source->name.' Internal Price');
    $box->update(['retail_price' => null]);
    $prices->savePrices($this->product, $this->source, $box, ['internal_sale_price' => null]);
    expect($prices->internalPriceFor($this->product, $this->source, $box))
        ->toBe(['price' => null, 'source' => null]);
});

test('manual override requires permission and snapshots the entered price', function () {
    $sale = app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 28000), $this->admin);
    expect((float) $sale->items()->first()->internal_unit_price)->toBe(28000.0)
        ->and($sale->items()->first()->price_source)->toBe('Manual Override');

    $restricted = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $role = Role::create(['name' => 'Fallback restricted '.str()->random(6), 'guard_name' => 'web', 'stock_scope' => 'assigned_locations']);
    $role->givePermissionTo('internal_sales.create');
    $restricted->assignRole($role);
    $restricted->stockLocations()->attach($this->source->id, [
        'company_id' => $restricted->company_id, 'branch_id' => $restricted->branch_id, 'can_transfer' => true,
    ]);
    $restricted->stockLocations()->attach($this->destination->id, [
        'company_id' => $restricted->company_id, 'branch_id' => $restricted->branch_id, 'can_receive' => true,
    ]);
    expect(fn () => app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 28000), $restricted))
        ->toThrow(ValidationException::class);
    expect(app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 29500), $restricted)->items()->first()->price_source)
        ->toBe('Product Default Selling Price');
});

test('completed fallback price and separate company cost stay fixed after default price changes', function () {
    $service = app(InternalSaleService::class);
    $sale = $service->saveDraft(fallbackSaleData($this, 29500), $this->admin);
    $before = collect(app(FinancialReportService::class)->stockValuation($this->branch->id))->sum('value');
    $service->complete($sale, $this->admin);
    $this->product->update(['selling_price' => 31000]);
    $item = $sale->items()->first();
    $rows = StockMovement::where('reference_type', InternalSale::class)->where('reference_id', $sale->id)->orderBy('id')->get();
    expect((float) $item->internal_unit_price)->toBe(29500.0)
        ->and($item->price_source)->toBe('Product Default Selling Price')
        ->and((float) $item->company_base_unit_cost)->toBe(16500.0)
        ->and((float) $item->destination_acquisition_base_unit_cost)->toBe(29500.0)
        ->and($rows->pluck('unit_cost')->all())->toBe(['16500.000000', '16500.000000'])
        ->and($rows->pluck('location_acquisition_unit_cost')->last())->toBe('29500.000000')
        ->and(collect(app(FinancialReportService::class)->stockValuation($this->branch->id))->sum('value'))->toEqual($before);
});

test('create and edit forms resolve defaults reactively and preserve manual entries on reload', function () {
    $form = Volt::test('internal-sales.create')
        ->set('items.0.selection', $this->product->id.':')
        ->assertSet('items.0.internal_unit_price', '29500.00')
        ->assertSet('items.0.price_source', 'Product Default Selling Price');
    app(LocationPriceService::class)->savePrices($this->product, $this->source, null, ['internal_sale_price' => 27000]);
    $form->set('from_location_id', (string) $this->source->id)
        ->assertSet('items.0.internal_unit_price', '27000.00')
        ->assertSet('items.0.price_source', $this->source->name.' Internal Price')
        ->set('items.0.internal_unit_price', '28000.00')
        ->assertSet('items.0.price_source', 'Manual Override')
        ->call('loadPrices')
        ->assertSet('items.0.internal_unit_price', '28000.00')
        ->call('resetItemPrice', 0)
        ->assertSet('items.0.internal_unit_price', '27000.00');

    $sale = app(InternalSaleService::class)->saveDraft(fallbackSaleData($this, 27000), $this->admin);
    $this->get(route('internal-sales.edit', $sale))->assertOk()->assertSee('Edit Internal Sale');
    Volt::test('internal-sales.create', ['internalSale' => $sale])
        ->assertSet('editingSaleId', $sale->id)
        ->assertSet('items.0.internal_unit_price', '27000.00')
        ->assertSet('items.0.price_source', $this->source->name.' Internal Price');
});
