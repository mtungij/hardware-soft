<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('inventory setup pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();

    $this->actingAs($admin)->get('/categories')->assertOk()->assertSee('Categories');
    $this->actingAs($admin)->get('/units')->assertOk()->assertSee('Units');
    $this->actingAs($admin)->get('/products')->assertOk()->assertSee('Products');
    $this->actingAs($admin)->get('/products/create')->assertOk()->assertSee('Create Product');
    $this->actingAs($admin)->get("/products/{$product->id}/edit")->assertOk()->assertSee('Edit Product');
    $this->actingAs($admin)->get('/suppliers')->assertOk()->assertSee('Suppliers');
    $this->actingAs($admin)->get('/suppliers/create')->assertOk()->assertSee('Create Supplier');
    $this->actingAs($admin)->get('/customers')->assertOk()->assertSee('Customers');
    $this->actingAs($admin)->get('/customers/create')->assertOk()->assertSee('Create Customer');
});

test('view roles can see inventory lists but not create pages', function () {
    $storeKeeper = User::factory()->create(['status' => 'active']);
    $storeKeeper->assignRole('Store Keeper');

    $this->actingAs($storeKeeper)->get('/categories')->assertOk();
    $this->actingAs($storeKeeper)->get('/units')->assertOk();
    $this->actingAs($storeKeeper)->get('/products')->assertOk();
    $this->actingAs($storeKeeper)->get('/suppliers')->assertOk();
    $this->actingAs($storeKeeper)->get('/customers')->assertOk();

    $this->actingAs($storeKeeper)->get('/products/create')->assertForbidden();
    $this->actingAs($storeKeeper)->get('/suppliers/create')->assertForbidden();
    $this->actingAs($storeKeeper)->get('/customers/create')->assertForbidden();
});

test('default inventory setup seed data exists', function () {
    expect(Category::where('code', 'CEM')->exists())->toBeTrue();
    expect(Unit::where('short_name', 'pcs')->exists())->toBeTrue();
    expect(Product::where('sku', 'BM-CEM-050')->exists())->toBeTrue();
});

test('category and unit cannot be deleted while products are attached', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $product = Product::firstOrFail();

    $this->actingAs($admin);

    Volt::test('categories.index')
        ->call('deleteCategory', $product->category_id)
        ->assertHasNoErrors();

    expect(Category::find($product->category_id))->not->toBeNull();

    Volt::test('units.index')
        ->call('deleteUnit', $product->unit_id)
        ->assertHasNoErrors();

    expect(Unit::find($product->unit_id))->not->toBeNull();
});
