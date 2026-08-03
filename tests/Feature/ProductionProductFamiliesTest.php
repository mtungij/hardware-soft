<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::query()->findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->actingAs($this->admin);
    ProductFamily::ensureDefaultsForCompany($this->company->id);
});

test('default concrete manufacturing catalog contains every supported family', function () {
    expect(ProductFamily::query()->forCurrentCompany()->count())->toBe(10)
        ->and(ProductFamily::query()->forCurrentCompany()->pluck('name')->all())->toContain(
            'Concrete Blocks', 'Hollow Blocks', 'Solid Blocks', 'Paving Blocks', 'Kerbstones',
            'Concrete Pipes', 'Culverts', 'Cover Slabs', 'Channels', 'Other Concrete Products',
        );

    $this->get(route('production.product-families.index'))
        ->assertOk()
        ->assertSee(__('production.product_families.title'))
        ->assertSee('Concrete Blocks')
        ->assertSee('Other Concrete Products');
});

test('admin can create update and delete an unused product family with defaults', function () {
    $unit = Unit::query()->where('company_id', $this->company->id)->firstOrFail();

    Volt::test('production.product-families.index')
        ->set('name', 'Decorative Concrete')
        ->set('code', 'decorative-concrete')
        ->set('description', 'Architectural concrete products')
        ->set('icon', 'shapes')
        ->set('colour', 'emerald')
        ->set('default_requires_curing', true)
        ->set('default_earliest_release_days', '7')
        ->set('default_curing_days', '28')
        ->set('default_requires_qc', true)
        ->set('default_inventory_unit_id', (string) $unit->id)
        ->set('default_selling_unit_id', (string) $unit->id)
        ->call('save')
        ->assertHasNoErrors();

    $family = ProductFamily::query()->forCurrentCompany()->where('code', 'decorative-concrete')->firstOrFail();
    expect($family->default_requires_curing)->toBeTrue()
        ->and($family->default_earliest_release_days)->toBe(7)
        ->and($family->default_curing_days)->toBe(28)
        ->and($family->default_requires_qc)->toBeTrue()
        ->and($family->default_inventory_unit_id)->toBe($unit->id);

    Volt::test('production.product-families.index')
        ->call('editFamily', $family->id)
        ->set('name', 'Decorative Precast')
        ->set('active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($family->refresh()->name)->toBe('Decorative Precast')
        ->and($family->active)->toBeFalse();

    Volt::test('production.product-families.index')->call('deleteFamily', $family->id);
    expect(ProductFamily::query()->forCurrentCompany()->whereKey($family->id)->exists())->toBeFalse();
});

test('manufactured products receive concrete blocks by default without changing product overrides', function () {
    $template = Product::query()->firstOrFail();
    $product = $template->replicate()->forceFill([
        'name' => 'Existing Manufactured Block',
        'sku' => 'EXISTING-MFG-BLOCK',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => null,
        'requires_curing' => true,
        'sellable_after_days' => 5,
        'curing_days_required' => 14,
        'requires_quality_control' => true,
    ]);
    $product->save();

    expect($product->refresh()->productFamily?->code)->toBe(ProductFamily::DEFAULT_CODE)
        ->and($product->requires_curing)->toBeTrue()
        ->and($product->sellable_after_days)->toBe(5)
        ->and($product->curing_days_required)->toBe(14)
        ->and($product->requires_quality_control)->toBeTrue();
});

test('family defaults populate manufactured product authoring and remain individually overridable', function () {
    $unit = Unit::query()->where('company_id', $this->company->id)->firstOrFail();
    $family = ProductFamily::query()->forCurrentCompany()->where('code', 'paving-blocks')->firstOrFail();
    $family->update([
        'default_requires_curing' => true,
        'default_earliest_release_days' => 7,
        'default_curing_days' => 21,
        'default_requires_qc' => true,
        'default_inventory_unit_id' => $unit->id,
        'default_selling_unit_id' => $unit->id,
    ]);

    Volt::test('products.create')
        ->set('inventory_source', Product::INVENTORY_SOURCE_MANUFACTURED)
        ->set('product_family_id', (string) $family->id)
        ->assertSet('requires_curing', true)
        ->assertSet('sellable_after_days', '7')
        ->assertSet('curing_days_required', '21')
        ->assertSet('requires_quality_control', true)
        ->assertSet('unit_id', (string) $unit->id)
        ->set('requires_curing', false)
        ->assertSet('requires_curing', false);
});

test('families assigned to products cannot be deleted and purchased products do not retain families', function () {
    $family = ProductFamily::query()->forCurrentCompany()->where('code', ProductFamily::DEFAULT_CODE)->firstOrFail();
    $template = Product::query()->firstOrFail();
    $product = $template->replicate()->forceFill([
        'name' => 'Family Protected Block', 'sku' => 'FAMILY-PROTECTED',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => $family->id,
    ]);
    $product->save();

    Volt::test('production.product-families.index')
        ->call('deleteFamily', $family->id)
        ->assertSee(__('production.product_families.in_use'));
    expect($family->fresh())->not->toBeNull();

    $product->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED]);
    expect($product->refresh()->product_family_id)->toBeNull();
});

test('product family permissions and tenant boundaries are enforced', function () {
    $viewer = User::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => Branch::query()->where('company_id', $this->company->id)->value('id'),
        'status' => 'active',
        'is_system_owner' => false,
    ]);
    $viewer->givePermissionTo(['production.view', 'production.view_product_families']);
    $this->actingAs($viewer);

    Volt::test('production.product-families.index')
        ->assertSee('Concrete Blocks')
        ->assertDontSee(__('production.product_families.create'))
        ->call('save')
        ->assertForbidden();

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Concrete Company', 'business_type' => 'Factory',
        'phone' => '+255700944444', 'whatsapp_number' => '+255700944444', 'manufacturing_enabled' => true,
    ]);
    ProductFamily::ensureDefaultsForCompany($otherCompany->id);
    $otherFamily = ProductFamily::query()->withoutGlobalScopes()->where('company_id', $otherCompany->id)->firstOrFail();

    $this->actingAs($this->admin);
    expect(fn () => Volt::test('production.product-families.index')->call('editFamily', $otherFamily->id))
        ->toThrow(ModelNotFoundException::class);
});

test('product family pages are unavailable when manufacturing is disabled', function () {
    $this->company->update(['manufacturing_enabled' => false]);

    $this->get(route('production.product-families.index'))->assertForbidden();
    Volt::test('production.product-families.index')->assertForbidden();
});
