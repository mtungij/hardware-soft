<?php

use App\Models\Category;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\ProductMeasurementOptions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\HardwareUnitSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();
    $this->category = Category::query()->where('supports_product_sizes', false)->firstOrFail();
});

test('volume measurement shows every active canonical volume unit in both selectors', function () {
    Volt::test('products.create')
        ->set('measurement_type_id', (string) $this->volume->id)
        ->assertSee('Cubic Metre / m³')
        ->assertSee('Litre / L')
        ->assertSee('Millilitre / ml')
        ->assertSee('Cubic Foot / ft³')
        ->assertSee('Cubic Centimetre / cm³');

    expect(ProductMeasurementOptions::baseUnits(MeasurementType::VOLUME)->pluck('code')->all())
        ->toEqualCanonicalizing(['m3', 'l', 'ml', 'ft3', 'cm3'])
        ->and(ProductMeasurementOptions::sellingUnits(MeasurementType::VOLUME)->pluck('code')->all())
        ->toEqualCanonicalizing(['m3', 'l', 'ml', 'ft3', 'cm3']);
});

test('river sand saves with cubic metre as both base and selling unit', function () {
    $cubicMetre = Unit::where('code', 'm3')->firstOrFail();

    volumeProductForm('River Sand')
        ->set('unit_id', (string) $cubicMetre->id)
        ->set('selling_unit_id', (string) $cubicMetre->id)
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('name', 'River Sand')->firstOrFail();

    expect($product->unit_id)->toBe($cubicMetre->id)
        ->and($product->selling_unit_id)->toBe($cubicMetre->id)
        ->and((float) $product->conversion_factor)->toBe(1.0);
});

test('paint saves with litre and same-unit conversion is forced to one', function () {
    $litre = Unit::where('code', 'l')->firstOrFail();

    volumeProductForm('Volume Paint')
        ->set('unit_id', (string) $litre->id)
        ->set('selling_unit_id', (string) $litre->id)
        ->set('conversion_factor', '999')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('name', 'Volume Paint')->firstOrFail();

    expect($product->unit_id)->toBe($litre->id)
        ->and($product->selling_unit_id)->toBe($litre->id)
        ->and((float) $product->conversion_factor)->toBe(1.0);
});

test('different volume units show direction and require a conversion factor', function () {
    $litre = Unit::where('code', 'l')->firstOrFail();
    $millilitre = Unit::where('code', 'ml')->firstOrFail();

    $component = volumeProductForm('Chemical')
        ->set('unit_id', (string) $litre->id)
        ->set('selling_unit_id', (string) $millilitre->id)
        ->assertSee('1 L = [conversion factor] ml')
        ->call('save')
        ->assertHasErrors(['conversion_factor']);

    $component
        ->set('conversion_factor', '1000')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) Product::where('name', 'Chemical')->firstOrFail()->conversion_factor)->toBe(1000.0);
});

test('existing compatible volume selections survive a measurement type refresh', function () {
    $litre = Unit::where('code', 'l')->firstOrFail();
    $millilitre = Unit::where('code', 'ml')->firstOrFail();

    Volt::test('products.create')
        ->set('unit_id', (string) $litre->id)
        ->set('selling_unit_id', (string) $millilitre->id)
        ->set('conversion_factor', '1000')
        ->set('measurement_type_id', (string) $this->volume->id)
        ->assertSet('unit_id', (string) $litre->id)
        ->assertSet('selling_unit_id', (string) $millilitre->id)
        ->assertSet('conversion_factor', '1000');
});

test('volume validation rejects a unit classified for another measurement type', function () {
    $kilogram = Unit::where('short_name', 'kg')->firstOrFail();
    $litre = Unit::where('code', 'l')->firstOrFail();

    volumeProductForm('Invalid Volume Unit')
        ->set('unit_id', (string) $kilogram->id)
        ->set('selling_unit_id', (string) $litre->id)
        ->set('conversion_factor', '1')
        ->call('save')
        ->assertHasErrors(['unit_id']);
});

test('volume unit seeding is idempotent and does not create duplicates', function () {
    (new HardwareUnitSeeder($this->admin->company_id, $this->admin->branch_id))->run();
    (new HardwareUnitSeeder($this->admin->company_id, $this->admin->branch_id))->run();

    foreach (['m3', 'l', 'ml', 'ft3', 'cm3'] as $code) {
        expect(Unit::where('code', $code)->count())->toBe(1);
    }
});

function volumeProductForm(string $name)
{
    $volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();
    $category = Category::query()->where('supports_product_sizes', false)->firstOrFail();

    return Volt::test('products.create')
        ->set('category_id', (string) $category->id)
        ->set('measurement_type_id', (string) $volume->id)
        ->set('name', $name)
        ->set('sku', 'VOL-'.str()->uuid())
        ->set('buying_price', '1000')
        ->set('selling_price', '1500')
        ->set('reorder_level', '1');
}
