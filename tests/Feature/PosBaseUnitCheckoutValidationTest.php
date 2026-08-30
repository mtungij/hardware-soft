<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

/**
 * Regression coverage for the "Complete Sale" button silently doing nothing.
 *
 * Root cause: every cart line added through the real POS addProduct() flow
 * defaults `product_unit_conversion_id` to an empty string (base/default
 * selling unit), but completeSale() validated it with
 * ['nullable', 'exists:product_unit_conversions,id']. Laravel's `nullable`
 * only skips remaining rules for an actual `null`, not `''`, so the `exists`
 * check ran against '' and failed on every default-unit sale. The resulting
 * ValidationException was never logged (Laravel excludes it from reporting)
 * and never shown (no matching @error() directive, and the session-flashed
 * message renders outside the Livewire component's DOM), so checkout looked
 * like it did nothing.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->inventory = app(InventoryService::class);
    $this->location = $this->inventory->getDispensingLocation($this->branch->id);
    $this->location->update(['is_active' => true, 'is_sellable' => true, 'can_sell' => true, 'can_receive_stock' => true]);
    $this->product = Product::where('sku', 'BM-MAB-G28')->firstOrFail();
    $this->product->update(['buying_price' => 100, 'selling_price' => 150, 'wholesale_price' => 130]);
    $this->box = Unit::where('company_id', $this->branch->company_id)->where('short_name', 'box')->firstOrFail();

    StockMovement::create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 100,
        'quantity_in' => 100,
        'quantity_out' => 0,
        'unit_cost' => 100,
        'unit_price' => 150,
        'created_by' => $this->admin->id,
        'movement_date' => today(),
    ]);
});

test('checkout succeeds for a default/base selling unit added through the real POS flow', function () {
    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->assertSet('cart.0.product_unit_conversion_id', '')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $item = $sale->items()->firstOrFail();

    expect($item->product_id)->toBe($this->product->id)
        ->and($item->product_unit_conversion_id)->toBeNull()
        ->and((float) $item->quantity)->toBe(1.0)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))
        ->toBe($startingStock - 1.0);
});

test('quantity input uses a moderate live debounce instead of aggressive or blur-only updates', function () {
    $template = file_get_contents(resource_path('views/livewire/pos/index.blade.php'));

    expect($template)
        ->toContain('wire:model.live.debounce.400ms="cart.{{ $index }}.quantity"')
        ->not->toContain('wire:model.live.debounce.75ms="cart.{{ $index }}.quantity"')
        ->not->toContain('wire:model.blur="cart.{{ $index }}.quantity"');
});

test('multi-digit quantities commit with recalculated line grand and automatic payment totals', function () {
    $component = Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.quantity', '20')
        ->assertSet('cart.0.quantity', '20')
        ->assertSet('payments.0.amount', '3000');

    expect(substr_count($component->html(), 'TZS 3,000'))->toBeGreaterThanOrEqual(3);

    $component
        ->set('cart.0.quantity', '100')
        ->assertSet('cart.0.quantity', '100')
        ->assertSet('payments.0.amount', '15000');

    expect(substr_count($component->html(), 'TZS 15,000'))->toBeGreaterThanOrEqual(3);
});

test('fractional quantity commits without losing its decimal and recalculates totals', function () {
    $this->product->update([
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.25,
        'quantity_step' => 0.25,
    ]);

    $component = Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.quantity', '12.5')
        ->assertSet('cart.0.quantity', '12.5')
        ->assertSet('payments.0.amount', '1875');

    expect(substr_count($component->html(), 'TZS 1,875'))->toBeGreaterThanOrEqual(3);
});

test('committed base-unit quantity checks out with the correct total and stock deduction', function () {
    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->call('changeLineUnit', 0, 'base')
        ->set('cart.0.quantity', '20')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $item = $sale->items()->firstOrFail();

    expect((float) $sale->total_amount)->toBe(3000.0)
        ->and((float) $item->quantity)->toBe(20.0)
        ->and((float) $item->base_quantity)->toBe(20.0)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($startingStock - 20.0);
});

test('committed conversion-unit quantity preserves transaction and normalized base quantities', function () {
    $crate = Unit::create([
        'company_id' => $this->branch->company_id,
        'measurement_type_id' => $this->product->unit?->measurement_type_id,
        'name' => 'Crate',
        'short_name' => 'crate-qty-ux',
        'status' => 'active',
    ]);
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $crate->id,
        'conversion_factor' => 24,
        'retail_price' => 3600,
        'wholesale_price' => 3120,
        'can_purchase' => false,
        'can_sell' => true,
        'active' => true,
    ]);
    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->call('changeLineUnit', 0, (string) $conversion->id)
        ->set('cart.0.quantity', '2')
        ->assertSet('payments.0.amount', '7200')
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $item = $sale->items()->firstOrFail();

    expect((float) $sale->total_amount)->toBe(7200.0)
        ->and((float) $item->quantity)->toBe(2.0)
        ->and((float) $item->base_quantity)->toBe(48.0)
        ->and($item->product_unit_conversion_id)->toBe($conversion->id)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($startingStock - 48.0);
});

test('quantity beyond available stock remains blocked by authoritative checkout', function () {
    $saleCount = Sale::query()->count();
    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.quantity', (string) ($startingStock + 1))
        ->assertHasErrors(['cart.0.quantity'])
        ->call('completeSale')
        ->assertHasErrors(['cart.0.quantity']);

    expect(Sale::query()->count())->toBe($saleCount)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($startingStock);
});

test('checkout succeeds for an explicit unit conversion selected through the real POS flow', function () {
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $this->product->id,
        'unit_id' => $this->box->id,
        'conversion_factor' => 12,
        'retail_price' => 1500,
        'wholesale_price' => 1400,
        'can_purchase' => false,
        'can_sell' => true,
        'active' => true,
    ]);

    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->call('changeLineUnit', 0, (string) $conversion->id)
        ->assertSet('cart.0.product_unit_conversion_id', (string) $conversion->id)
        ->call('completeSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->latest('id')->firstOrFail();
    $item = $sale->items()->firstOrFail();

    expect($item->product_unit_conversion_id)->toBe($conversion->id)
        ->and((float) $item->unit_price)->toBe(1500.0)
        ->and((float) $item->base_quantity)->toBe(12.0)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))
        ->toBe($startingStock - 12.0);
});

test('checkout rejects a nonexistent unit conversion id without creating a sale or touching stock', function () {
    $saleCountBefore = Sale::count();
    $startingStock = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.product_unit_conversion_id', '999999999')
        ->call('completeSale')
        ->assertHasErrors(['cart.0.product_unit_conversion_id']);

    expect(Sale::count())->toBe($saleCountBefore)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))
        ->toBe($startingStock);
});

test('checkout rejects a unit conversion that belongs to a different product', function () {
    $otherProduct = $this->product->replicate();
    $otherProduct->sku = $this->product->sku.'-CLONE';
    $otherProduct->name = $this->product->name.' Clone';
    $otherProduct->save();

    $foreignConversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id,
        'product_id' => $otherProduct->id,
        'unit_id' => $this->box->id,
        'conversion_factor' => 10,
        'retail_price' => 1000,
        'can_purchase' => false,
        'can_sell' => true,
        'active' => true,
    ]);

    $saleCountBefore = Sale::count();

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.product_unit_conversion_id', (string) $foreignConversion->id)
        ->call('completeSale')
        ->assertHasErrors('sale');

    expect(Sale::count())->toBe($saleCountBefore);
});

test('checkout validation failure is visible inside the POS component without a full page reload', function () {
    $component = Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.product_unit_conversion_id', '999999999')
        ->call('completeSale');

    $component->assertHasErrors('sale');

    $message = $component->errors()->first('sale');

    expect($message)->not->toBeEmpty();

    $component->assertSee($message);
});
