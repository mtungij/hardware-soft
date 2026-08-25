<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->product = Product::where('sku', 'BM-NON-Y12')->firstOrFail();
    $this->createTemplate = Product::where('sku', 'BM-MAB-G28')->firstOrFail();
});

function productSkuCreateComponent(object $test, string $sku, string $name = 'SKU Validation Product')
{
    $template = $test->createTemplate;

    return Volt::test('products.create')
        ->set('branch_id', (string) $template->branch_id)
        ->set('category_id', (string) $template->category_id)
        ->set('measurement_type_id', (string) $template->measurement_type_id)
        ->set('purchase_unit_id', (string) $template->unit_id)
        ->set('unit_id', (string) $template->unit_id)
        ->set('selling_unit_id', (string) $template->unit_id)
        ->set('name', $name)
        ->set('sku', $sku)
        ->set('buying_price', '100')
        ->set('selling_price', '150')
        ->set('reorder_level', '0');
}

function productSkuForeignCompany(): Company
{
    return Company::create([
        'company_name' => 'Foreign SKU Company',
        'business_type' => 'Hardware',
        'phone' => '+255700000999',
        'whatsapp_number' => '+255700000999',
        'country' => 'Tanzania',
        'currency' => 'TZS',
        'timezone' => 'Africa/Dar_es_Salaam',
        'language' => 'en',
    ]);
}

function productSkuCloneForCompany(Product $product, Company $company, string $sku): Product
{
    $clone = $product->replicate();
    $clone->forceFill([
        'company_id' => $company->id,
        'branch_id' => null,
        'name' => 'Foreign '.$product->name,
        'sku' => $sku,
        'barcode' => null,
    ]);
    $clone->saveQuietly();

    return $clone;
}

test('edit accepts its unchanged sku even when another company uses the same sku', function () {
    productSkuCloneForCompany($this->product, productSkuForeignCompany(), $this->product->sku);

    Volt::test('products.edit', ['product' => $this->product])
        ->call('save')
        ->assertHasNoErrors();

    expect($this->product->refresh()->sku)->toBe('BM-NON-Y12');
});

test('edit can change only the product name while retaining its sku', function () {
    Volt::test('products.edit', ['product' => $this->product])
        ->set('name', 'Nondo Y12 Updated')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->product->refresh()->name)->toBe('Nondo Y12 Updated')
        ->and($this->product->sku)->toBe('BM-NON-Y12');
});

test('edit rejects another product sku from the same company', function () {
    $other = Product::whereKeyNot($this->product->id)->whereNotNull('sku')->firstOrFail();

    Volt::test('products.edit', ['product' => $this->product])
        ->set('sku', $other->sku)
        ->call('save')
        ->assertHasErrors(['sku' => 'unique']);

    expect($this->product->refresh()->sku)->toBe('BM-NON-Y12');
});

test('create rejects a duplicate sku from the current company', function () {
    productSkuCreateComponent($this, $this->product->sku)
        ->call('save')
        ->assertHasErrors(['sku' => 'unique']);
});

test('create permits a sku used only by another company', function () {
    $sku = 'CROSS-COMPANY-SKU';
    productSkuCloneForCompany($this->product, productSkuForeignCompany(), $sku);

    productSkuCreateComponent($this, $sku)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('company_id', $this->admin->company_id)->where('sku', $sku)->count())->toBe(1);
});

test('blank sku remains optional and is stored as null', function () {
    Volt::test('products.edit', ['product' => $this->product])
        ->set('sku', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->product->refresh()->sku)->toBeNull();

    productSkuCreateComponent($this, '', 'Product Without SKU')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('name', 'Product Without SKU')->firstOrFail()->sku)->toBeNull();
});

test('sku reuse from a deleted product remains blocked', function () {
    DB::table('products')->where('id', $this->product->id)->update(['deleted_at' => now()]);

    productSkuCreateComponent($this, $this->product->sku, 'Deleted SKU Reuse Attempt')
        ->call('save')
        ->assertHasErrors(['sku' => 'unique']);
});
