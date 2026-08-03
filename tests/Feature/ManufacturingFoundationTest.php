<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\CompanyFeatures;
use Database\Seeders\DatabaseSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->actingAs($this->admin);
});

function manufacturingProductForm(string $name): Testable
{
    $template = Product::query()->firstOrFail();

    return Volt::test('products.create')
        ->set('branch_id', (string) ($template->branch_id ?: ''))
        ->set('category_id', (string) $template->category_id)
        ->set('measurement_type_id', (string) $template->measurement_type_id)
        ->set('purchase_unit_id', (string) ($template->purchase_unit_id ?: $template->unit_id))
        ->set('purchase_conversion_factor', (string) ($template->purchase_conversion_factor ?: 1))
        ->set('unit_id', (string) $template->unit_id)
        ->set('selling_unit_id', (string) ($template->selling_unit_id ?: $template->unit_id))
        ->set('uses_product_size', (bool) $template->uses_product_size)
        ->set('product_size_id', (string) ($template->product_size_id ?: ''))
        ->set('name', $name)
        ->set('sku', 'MFG-'.str()->upper(str()->random(10)))
        ->set('buying_price', '100')
        ->set('selling_price', '150')
        ->set('reorder_level', '1')
        ->set('status', 'active');
}

test('existing and newly created companies default to manufacturing disabled', function () {
    expect($this->company->manufacturingEnabled())->toBeFalse();

    $newCompany = Company::create([
        'company_name' => 'New Hardware',
        'business_type' => 'Hardware Store',
        'phone' => '+255700123123',
        'whatsapp_number' => '+255700123123',
    ]);

    expect($newCompany->manufacturingEnabled())->toBeFalse();
});

test('the central feature check is safe without a company and false when disabled', function () {
    expect(CompanyFeatures::manufacturingEnabled())->toBeFalse();

    auth()->logout();

    expect(CompanyFeatures::manufacturingEnabled())->toBeFalse();
});

test('an authorised administrator can enable manufacturing for their own company', function () {
    Volt::test('settings.company')
        ->set('manufacturing_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->company->refresh()->manufacturingEnabled())->toBeTrue();
});

test('an ordinary user cannot change manufacturing settings', function () {
    $cashier = User::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->admin->branch_id,
        'status' => 'active',
        'is_system_owner' => false,
    ]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);

    $this->get(route('settings.company'))->assertForbidden();
    Volt::test('settings.company')->assertForbidden();

    expect($this->company->refresh()->manufacturingEnabled())->toBeFalse();
});

test('saving company settings cannot alter another company manufacturing flag', function () {
    $otherCompany = Company::create([
        'company_name' => 'Other Hardware',
        'business_type' => 'Hardware Store',
        'phone' => '+255700456456',
        'whatsapp_number' => '+255700456456',
    ]);

    Volt::test('settings.company')
        ->set('manufacturing_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->company->refresh()->manufacturingEnabled())->toBeTrue()
        ->and($otherCompany->refresh()->manufacturingEnabled())->toBeFalse();
});

test('existing products and direct new products default to purchased', function () {
    expect(Product::query()->where('inventory_source', '!=', Product::INVENTORY_SOURCE_PURCHASED)->doesntExist())->toBeTrue();

    $template = Product::query()->firstOrFail();
    $product = Product::create([
        ...$template->only([
            'branch_id', 'category_id', 'measurement_type_id', 'purchase_unit_id',
            'purchase_conversion_factor', 'unit_id', 'selling_unit_id', 'buying_price',
            'selling_price', 'conversion_factor', 'allow_fractional_sale',
            'minimum_sale_quantity', 'quantity_step', 'reorder_level', 'taxable', 'status',
        ]),
        'name' => 'Default Purchased Product',
        'sku' => 'DEFAULT-PURCHASED',
    ]);

    expect($product->inventory_source)->toBe(Product::INVENTORY_SOURCE_PURCHASED);
});

test('disabled manufacturing hides source and normalizes a malicious product submission', function () {
    manufacturingProductForm('Normalized Product')
        ->assertDontSee(__('products.inventory_source.label'))
        ->set('inventory_source', Product::INVENTORY_SOURCE_MANUFACTURED)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('name', 'Normalized Product')->firstOrFail()->inventory_source)
        ->toBe(Product::INVENTORY_SOURCE_PURCHASED);
});

test('manufactured products can be created when manufacturing is enabled', function () {
    $this->company->update(['manufacturing_enabled' => true]);

    manufacturingProductForm('Heavy Block 6')
        ->assertSee(__('products.inventory_source.label'))
        ->set('inventory_source', Product::INVENTORY_SOURCE_MANUFACTURED)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('name', 'Heavy Block 6')->firstOrFail()->inventory_source)
        ->toBe(Product::INVENTORY_SOURCE_MANUFACTURED);
});

test('editing while manufacturing is disabled hides and normalizes inventory source', function () {
    $this->company->update(['manufacturing_enabled' => true]);
    $product = Product::query()->firstOrFail();
    $product->update(['inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED]);
    $this->company->update(['manufacturing_enabled' => false]);

    Volt::test('products.edit', ['product' => $product])
        ->assertDontSee(__('products.inventory_source.label'))
        ->set('name', 'Normalized Existing Product')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->inventory_source)->toBe(Product::INVENTORY_SOURCE_PURCHASED);
});

test('purchase selection excludes manufactured goods but keeps purchased raw materials', function () {
    $this->company->update(['manufacturing_enabled' => true]);
    $manufactured = Product::query()->firstOrFail();
    $manufactured->update([
        'name' => 'Paving Blocks',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
    ]);
    $rawMaterial = Product::query()->whereKeyNot($manufactured->id)->firstOrFail();
    $rawMaterial->update([
        'name' => 'Manufacturing Cement Raw Material',
        'inventory_source' => Product::INVENTORY_SOURCE_PURCHASED,
    ]);
    $supplier = Supplier::query()->create([
        'branch_id' => $this->admin->branch_id,
        'name' => 'Manufacturing Test Supplier',
        'phone' => '+255700888888',
        'opening_balance' => 0,
        'status' => 'active',
    ]);

    expect(Product::query()->purchasable()->pluck('id'))
        ->not->toContain($manufactured->id)
        ->toContain($rawMaterial->id);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->assertDontSee('Paving Blocks')
        ->assertSee('Manufacturing Cement Raw Material')
        ->call('selectProduct', 0, (string) $manufactured->id)
        ->assertSet('items.0.product_id', '');
});

test('server validation rejects a manufactured product injected into a new purchase', function () {
    $this->company->update(['manufacturing_enabled' => true]);
    $product = Product::query()->firstOrFail();
    $product->update(['inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED]);
    $supplier = Supplier::query()->create([
        'branch_id' => $this->admin->branch_id,
        'name' => 'Injected Product Supplier',
        'phone' => '+255700777777',
        'opening_balance' => 0,
        'status' => 'active',
    ]);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $supplier->id)
        ->set('items.0.product_id', (string) $product->id)
        ->set('items.0.ordered_quantity', 1)
        ->set('items.0.cost_price', 100)
        ->set('items.0.selling_price', 150)
        ->call('savePurchase', 'ordered')
        ->assertHasErrors(['items.0.product_id']);
});

test('manufactured products remain visible and addable in pos when stock is available', function () {
    $this->company->update(['manufacturing_enabled' => true]);
    $product = Product::query()->firstOrFail();
    $product->update([
        'name' => 'Sellable Manufactured Block',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
    ]);
    $location = StockLocation::query()
        ->where('branch_id', $this->admin->branch_id)
        ->where('type', 'dispensing')
        ->firstOrFail();
    $location->forceFill(['status' => 'active', 'is_active' => true, 'can_sell' => true])->save();

    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->admin->branch_id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'quantity' => 10,
        'cost_price' => $product->buying_price,
        'selling_price' => $product->selling_price,
        'reason' => 'Manufacturing foundation POS test',
        'notes' => '',
        'movement_date' => now()->toDateString(),
    ], $this->admin->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $location->id)
        ->set('search', 'Sellable Manufactured Block')
        ->assertSee('Sellable Manufactured Block')
        ->call('addProduct', $product->id)
        ->assertSet('cart.0.product_id', $product->id);

    expect($product->refresh()->inventory_source)->toBe(Product::INVENTORY_SOURCE_MANUFACTURED);
});
