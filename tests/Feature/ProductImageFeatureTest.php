<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\ProductAvatar;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
});

function validProductImageForm(?UploadedFile $image = null): Testable
{
    $template = Product::query()->with(['measurementType', 'purchaseUnit', 'unit', 'sellingUnit'])->firstOrFail();
    $component = Volt::test('products.create')
        ->set('branch_id', (string) ($template->branch_id ?: ''))
        ->set('category_id', (string) $template->category_id)
        ->set('measurement_type_id', (string) $template->measurement_type_id)
        ->set('purchase_unit_id', (string) ($template->purchase_unit_id ?: $template->unit_id))
        ->set('purchase_conversion_factor', (string) ($template->purchase_conversion_factor ?: 1))
        ->set('unit_id', (string) $template->unit_id)
        ->set('selling_unit_id', (string) ($template->selling_unit_id ?: $template->unit_id))
        ->set('uses_product_size', (bool) $template->uses_product_size)
        ->set('product_size_id', (string) ($template->product_size_id ?: ''))
        ->set('name', 'Image Test Product '.str()->random(8))
        ->set('sku', 'IMG-'.str()->upper(str()->random(10)))
        ->set('buying_price', '100')
        ->set('selling_price', '150')
        ->set('reorder_level', '1')
        ->set('status', 'active');

    if ($image) {
        $component->set('image_upload', $image);
    }

    return $component;
}

test('a product can be created without an image', function () {
    $component = validProductImageForm();
    $name = $component->get('name');

    $component->call('save')->assertHasNoErrors();

    expect(Product::where('name', $name)->firstOrFail()->image_path)->toBeNull();
});

test('a valid product image is optimized and stored on the public disk', function () {
    $component = validProductImageForm(UploadedFile::fake()->image('drill.png', 1600, 900)->size(900));
    $name = $component->get('name');

    $component->call('save')->assertHasNoErrors();

    $product = Product::where('name', $name)->firstOrFail();
    Storage::disk('public')->assertExists($product->image_path);
    $storedImage = getimagesize(Storage::disk('public')->path($product->image_path));

    expect($product->image_path)
        ->toStartWith('products/'.$this->admin->company_id.'/product-'.$product->id.'-')
        ->toEndWith('.webp')
        ->and($storedImage['mime'])->toBe('image/webp')
        ->and(max($storedImage[0], $storedImage[1]))->toBeLessThanOrEqual(1200);
});

test('invalid and oversized product image uploads are rejected', function () {
    validProductImageForm(UploadedFile::fake()->create('payload.php', 20, 'application/x-php'))
        ->call('save')
        ->assertHasErrors(['image_upload']);

    validProductImageForm(UploadedFile::fake()->image('large.jpg')->size(2049))
        ->call('save')
        ->assertHasErrors(['image_upload']);
});

test('a product image can be replaced and the old file is removed', function () {
    $product = Product::query()->firstOrFail();
    $oldPath = "products/{$product->company_id}/old.webp";
    Storage::disk('public')->put($oldPath, 'old image');
    $product->update(['image_path' => $oldPath]);

    Volt::test('products.edit', ['product' => $product])
        ->set('image_upload', UploadedFile::fake()->image('replacement.jpg', 800, 600))
        ->call('save')
        ->assertHasNoErrors();

    $newPath = $product->refresh()->image_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($oldPath);
});

test('a product image can be removed', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/remove.webp";
    Storage::disk('public')->put($path, 'image');
    $product->update(['image_path' => $path]);

    Volt::test('products.edit', ['product' => $product])
        ->call('removeImage')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('permanently deleting a product removes only its owned image', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/delete.webp";
    Storage::disk('public')->put($path, 'image');
    $product->update(['image_path' => $path]);

    $product->delete();

    Storage::disk('public')->assertMissing($path);
});

test('a null product image uses the local placeholder', function () {
    $product = Product::query()->firstOrFail();

    expect($product->image_url)->toBe('/images/product-placeholder.svg');
});

test('a missing product image file uses the local placeholder', function () {
    $product = Product::query()->firstOrFail();

    $product->update(['image_path' => "products/{$product->company_id}/missing.webp"]);
    expect($product->refresh()->image_url)->toBe('/images/product-placeholder.svg');
});

test('an existing product image generates a host independent public URL', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/url-test.webp";
    Storage::disk('public')->put($path, 'image');
    $product->update(['image_path' => $path]);

    expect(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($product->refresh()->image_url)->toBe('/storage/'.$path);
});

test('products index and pos render lazy product images', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/visible.webp";
    Storage::disk('public')->put($path, 'image');
    $product->update(['image_path' => $path]);

    Volt::test('products.index')
        ->set('search', $product->sku)
        ->assertSee($product->refresh()->image_url, false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('data-product-image-fallback', false)
        ->assertSee('this.hidden=true', false);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) StockLocation::query()->where('branch_id', $this->admin->branch_id)->firstOrFail()->id)
        ->set('search', $product->sku)
        ->assertSee($product->image_url, false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('data-product-image-fallback', false)
        ->assertSee('this.hidden=true', false);
});

test('product image component renders an actual image above its fallback', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/component-image.webp";
    Storage::disk('public')->put($path, 'browser image bytes');
    $product->update(['image_path' => $path]);

    $html = Blade::render('<x-product-image :product="$product" class="h-20 w-full sm:h-24 xl:h-28" />', compact('product'));

    expect($html)->toContain('/storage/'.$path)
        ->toContain('data-product-image-source')
        ->toContain('data-product-image-fallback')
        ->toContain('loading="lazy"');
});

test('product image component renders initials without the generic placeholder', function () {
    $product = Product::query()->firstOrFail();
    $product->update(['name' => 'Paving Block 60mm', 'image_path' => null, 'image' => null]);

    $html = Blade::render('<x-product-image :product="$product" class="h-12 w-12" />', compact('product'));

    expect($html)->toContain('data-product-initials="PM"')
        ->toContain('>PM</span>')
        ->not->toContain('data-product-image-source')
        ->not->toContain('/images/product-placeholder.svg');
});

test('a browser image failure hides the broken image and reveals initials', function () {
    $product = Product::query()->firstOrFail();
    $path = "products/{$product->company_id}/broken-in-browser.webp";
    Storage::disk('public')->put($path, 'not a decodable image');
    $product->update(['name' => 'Claw Hammer', 'image_path' => $path]);

    $html = Blade::render('<x-product-image :product="$product" class="h-12 w-12" />', compact('product'));

    expect($html)->toContain('data-product-initials="CR"')
        ->toContain('onerror="this.onerror=null;this.hidden=true;"')
        ->toContain('data-product-image-fallback');
});

test('product initials and accent are deterministic and follow naming rules', function () {
    $examples = [
        'Ceramic Floor Tiles 40x40' => 'CE',
        'Claw Hammer' => 'CR',
        'Electrical Cable 2.5mm Roll' => 'EL',
        'Paving Block 60mm' => 'PM',
        'Cement' => 'CT',
        ' @ ' => 'PR',
    ];

    foreach ($examples as $name => $initials) {
        expect(ProductAvatar::for($name, 42)['initials'])->toBe($initials);
    }

    expect(ProductAvatar::for('Claw Hammer', 42)['classes'])
        ->toBe(ProductAvatar::for('Renamed Product', 42)['classes'])
        ->and(ProductAvatar::for('A', 42)['initials'])->toBe('A');
});

test('pos initials use the first letters of the first and last meaningful words', function () {
    expect(ProductAvatar::wordInitials('Paving Block 60mm'))->toBe('PB')
        ->and(ProductAvatar::wordInitials('Ceramic Floor Tiles'))->toBe('CT')
        ->and(ProductAvatar::wordInitials('Electrical Cable 2.5mm Roll'))->toBe('ER')
        ->and(ProductAvatar::wordInitials('Heavy Block 6'))->toBe('HB')
        ->and(ProductAvatar::wordInitials('Gloss Paint 4L'))->toBe('GP')
        ->and(ProductAvatar::wordInitials('Toyota Oil Filter'))->toBe('TF');
});

test('pos product avatar preserves premium responsive product card dimensions', function () {
    $product = Product::query()->firstOrFail();
    $product->update(['name' => 'Ceramic Floor Tiles 40x40', 'image_path' => null, 'image' => null]);

    Volt::test('pos.index')
        ->set('search', $product->sku)
        ->assertSee('data-product-initials="CT"', false)
        ->assertSee('h-[130px] w-full', false)
        ->assertSee('bg-[#FF6A00] text-white', false)
        ->assertSee('text-[56px] font-bold leading-none tracking-[2px]', false)
        ->assertSee('rounded-[18px]', false)
        ->assertSee('sm:grid-cols-2 xl:grid-cols-3', false)
        ->assertDontSee('/images/product-placeholder.svg', false);
});

test('product forms include gallery and rear camera compatible inputs', function () {
    Volt::test('products.create')
        ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
        ->assertSee('accept="image/*"', false)
        ->assertSee('capture="environment"', false)
        ->assertSee(__('products.image.take_photo'));
});

test('a company user cannot edit another company product image', function () {
    $company = Company::query()->create([
        'company_name' => 'Other Company',
        'business_type' => 'Hardware Store',
        'phone' => '+255700999999',
        'whatsapp_number' => '+255700999999',
    ]);
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Other Main',
        'code' => 'OTHER',
        'status' => 'active',
    ]);
    $template = Product::query()->firstOrFail();
    $foreign = Product::withoutGlobalScopes()->create([
        ...$template->only([
            'category_id', 'measurement_type_id', 'purchase_unit_id', 'purchase_conversion_factor',
            'unit_id', 'selling_unit_id', 'buying_price', 'selling_price', 'conversion_factor',
            'allow_fractional_sale', 'minimum_sale_quantity', 'quantity_step', 'reorder_level',
            'taxable', 'status',
        ]),
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Foreign Product',
        'sku' => 'FOREIGN-IMAGE',
    ]);
    $companyAdmin = User::factory()->create([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->admin->branch_id,
        'status' => 'active',
        'is_system_owner' => false,
    ]);
    $companyAdmin->assignRole('Admin');
    $this->actingAs($companyAdmin);

    $this->get(route('products.edit', $foreign->id))->assertNotFound();
    expect($foreign->refresh()->image_path)->toBeNull();
});
