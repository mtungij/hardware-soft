<?php

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
});

test('a user can create and classify a Unicode unit from the Units module', function () {
    $volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();

    Volt::test('units.index')
        ->set('name', 'Custom Drum Volume')
        ->set('short_name', 'dr³')
        ->set('measurement_type_id', (string) $volume->id)
        ->set('status', 'active')
        ->set('description', 'User-created cubic drum unit.')
        ->call('save')
        ->assertHasNoErrors();

    $unit = Unit::where('short_name', 'dr³')->firstOrFail();

    expect($unit->measurement_type_id)->toBe($volume->id)
        ->and($unit->description)->toBe('User-created cubic drum unit.');
});

test('a newly created active volume unit immediately appears only for volume products', function () {
    $volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();
    $weight = MeasurementType::where('code', MeasurementType::WEIGHT)->firstOrFail();

    Unit::query()->create([
        'company_id' => $this->admin->company_id,
        'name' => 'Fluid Ounce',
        'short_name' => 'fl oz',
        'measurement_type_id' => $volume->id,
        'status' => 'active',
    ]);

    expect(ProductMeasurementOptions::baseUnits(MeasurementType::VOLUME)->pluck('short_name'))->toContain('fl oz')
        ->and(ProductMeasurementOptions::baseUnits(MeasurementType::WEIGHT)->pluck('short_name'))->not->toContain('fl oz');

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $volume->id)
        ->assertSee('Fluid Ounce / fl oz')
        ->set('measurement_type_id', (string) $weight->id)
        ->assertDontSee('Fluid Ounce / fl oz');
});

test('inactive user-created units are excluded from product forms', function () {
    $volume = MeasurementType::where('code', MeasurementType::VOLUME)->firstOrFail();

    Unit::query()->create([
        'company_id' => $this->admin->company_id,
        'name' => 'Inactive Volume',
        'short_name' => 'iv',
        'measurement_type_id' => $volume->id,
        'status' => 'inactive',
    ]);

    Volt::test('products.create')
        ->set('measurement_type_id', (string) $volume->id)
        ->assertDontSee('Inactive Volume / iv');
});

test('existing standard units have safe measurement classifications', function () {
    expect(Unit::where('short_name', 'pcs')->firstOrFail()->measurementType?->code)->toBe(MeasurementType::COUNT)
        ->and(Unit::where('short_name', 'bag')->firstOrFail()->measurementType?->code)->toBe(MeasurementType::COUNT)
        ->and(Unit::where('short_name', 'kg')->firstOrFail()->measurementType?->code)->toBe(MeasurementType::WEIGHT)
        ->and(Unit::where('short_name', 'm')->firstOrFail()->measurementType?->code)->toBe(MeasurementType::LENGTH)
        ->and(Unit::where('short_name', 'L')->firstOrFail()->measurementType?->code)->toBe(MeasurementType::VOLUME);
});

test('canonical cubic metre keeps its Unicode symbol and stable record', function () {
    $unit = Unit::where('code', 'm3')->firstOrFail();
    $originalId = $unit->id;

    Volt::test('units.index')
        ->call('editUnit', $unit->id)
        ->set('description', 'Used for sand, gravel, and concrete.')
        ->call('save')
        ->assertHasNoErrors();

    expect($unit->refresh()->id)->toBe($originalId)
        ->and($unit->short_name)->toBe('m³')
        ->and($unit->code)->toBe('m3');
});

test('unit seeding remains idempotent', function () {
    $idsBefore = Unit::whereIn('code', ['m3', 'l', 'ml', 'ft3', 'cm3'])->pluck('id', 'code');

    (new HardwareUnitSeeder($this->admin->company_id, $this->admin->branch_id))->run();
    (new HardwareUnitSeeder($this->admin->company_id, $this->admin->branch_id))->run();

    expect(Unit::whereIn('code', ['m3', 'l', 'ml', 'ft3', 'cm3'])->pluck('id', 'code')->all())
        ->toBe($idsBefore->all());
});

test('editing unit details preserves product assignments', function () {
    $product = Product::query()->firstOrFail();
    $unit = $product->unit;
    $unitId = $unit->id;
    $productId = $product->id;

    Volt::test('units.index')
        ->call('editUnit', $unitId)
        ->set('description', 'Updated without changing assignments.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::findOrFail($productId)->unit_id)->toBe($unitId)
        ->and(Unit::findOrFail($unitId)->description)->toBe('Updated without changing assignments.');
});

test('changing an assigned unit to an incompatible measurement type is blocked', function () {
    $product = Product::query()->whereNotNull('measurement_type_id')->firstOrFail();
    $unit = $product->unit;
    $incompatibleType = MeasurementType::query()
        ->where('id', '!=', $product->measurement_type_id)
        ->orderBy('id')
        ->firstOrFail();

    Volt::test('units.index')
        ->call('editUnit', $unit->id)
        ->set('measurement_type_id', (string) $incompatibleType->id)
        ->call('save')
        ->assertHasErrors(['measurement_type_id']);

    expect($unit->refresh()->measurement_type_id)->not->toBe($incompatibleType->id)
        ->and($product->refresh()->unit_id)->toBe($unit->id);
});
