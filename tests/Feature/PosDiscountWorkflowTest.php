<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->inventory = app(InventoryService::class);
    $this->location = $this->inventory->getDispensingLocation($this->branch->id);
    $this->location->update(['is_active' => true, 'is_sellable' => true, 'can_sell' => true]);
    $this->product = Product::where('sku', 'BM-MAB-G28')->firstOrFail();
    $this->product->update(['buying_price' => 10, 'selling_price' => 100, 'wholesale_price' => 80, 'taxable' => false]);

    StockMovement::create([
        'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in', 'quantity' => 100, 'quantity_in' => 100, 'quantity_out' => 0,
        'unit_cost' => 10, 'unit_price' => 100, 'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
});

function completeDiscountSale(object $test, array $cart, float $payment, array $discountDetails): Sale
{
    return $test->inventory->completeSale(
        $cart,
        [['payment_method' => 'cash', 'amount' => $payment, 'reference_number' => null]],
        null,
        $test->location->id,
        $test->branch->id,
        $test->admin->id,
        additionalCharges: [],
        discountDetails: $discountDetails,
    );
}

test('discount selector is rendered once inside Cart and Payment and not beside price mode', function () {
    $template = file_get_contents(resource_path('views/livewire/pos/index.blade.php'));
    $priceSection = strpos($template, 'data-pos-section="price-mode"');
    $priceSectionEnd = strpos($template, '</x-card>', $priceSection);
    $cartPanel = strpos($template, '<x-card title="Cart & Payment"');
    $discountSection = strpos($template, 'data-pos-section="cart-discount-mode"');

    expect($priceSection)->not->toBeFalse()
        ->and($cartPanel)->not->toBeFalse()
        ->and($discountSection)->toBeGreaterThan($cartPanel)
        ->and(substr($template, $priceSection, $priceSectionEnd - $priceSection))->not->toContain('data-discount-mode-selector')
        ->and(substr_count($template, 'data-pos-section="cart-discount-mode"'))->toBe(1)
        ->and($template)->toContain('<select data-discount-mode-selector wire:model.live="discount_mode"')
        ->and($template)->toContain('<option value="none">Hakuna</option>')
        ->and($template)->toContain('<option value="item">Kwa Kila Bidhaa</option>')
        ->and($template)->toContain('<option value="order">Jumla ya Sale</option>')
        ->and($template)->not->toContain('wire:model.live="discount_mode" value=')
        ->and($template)->not->toContain('wire:model.live="cart.{{ $index }}.tax_amount"')
        ->and($template)->not->toContain('Line Subtotal')
        ->and($template)->toContain('Line Total')
        ->and($template)->not->toContain("\$t('Change')");
});

test('discount mode reactively renders only the matching editor in the cart panel', function () {
    $component = Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->assertSeeHtml('data-pos-section="cart-discount-mode"')
        ->assertDontSeeHtml('data-item-discount-editor')
        ->assertDontSeeHtml('data-order-discount-editor');

    $component->set('discount_mode', 'item')
        ->assertSeeHtml('data-item-discount-editor')
        ->assertDontSeeHtml('data-order-discount-editor');

    $component->set('discount_mode', 'order')
        ->assertDontSeeHtml('data-item-discount-editor')
        ->assertSeeHtml('data-order-discount-editor');
});

test('POS unit price is readonly and manipulated browser price is ignored', function () {
    $template = file_get_contents(resource_path('views/livewire/pos/index.blade.php'));
    expect($template)->toContain('aria-readonly="true"')->not->toContain('wire:model.live="cart.{{ $index }}.unit_price"');

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->location->id)
        ->call('addProduct', $this->product->id)
        ->set('cart.0.unit_price', '1')
        ->call('completeSale')
        ->assertHasNoErrors();

    expect((float) Sale::latest('id')->firstOrFail()->items()->firstOrFail()->unit_price)->toBe(100.0);
});

test('retail and wholesale prices remain server authoritative', function () {
    $retail = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 1,
        'unit_price' => 1, 'discount_amount' => 999, 'tax_amount' => 999,
    ]], 120, ['mode' => 'none']);
    $wholesale = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'wholesale', 'quantity' => 1,
        'unit_price' => 1, 'discount_amount' => 999, 'tax_amount' => 999,
    ]], 80, ['mode' => 'none']);

    expect((float) $retail->items()->first()->unit_price)->toBe(100.0)
        ->and((float) $wholesale->items()->first()->unit_price)->toBe(80.0)
        ->and((float) $retail->discount_amount)->toBe(0.0)
        ->and((float) $retail->tax_amount)->toBe(0.0)
        ->and((float) $wholesale->tax_amount)->toBe(0.0)
        ->and((float) $retail->change_amount)->toBe(20.0);
});

test('per-item fixed and percentage discounts persist resolved snapshots', function () {
    $fixed = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 2,
        'discount_type' => 'fixed', 'discount_value' => 30,
    ]], 170, ['mode' => 'item']);
    $percentage = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 2,
        'discount_type' => 'percentage', 'discount_value' => 10,
    ]], 180, ['mode' => 'item']);

    expect($fixed->discount_mode)->toBe('item')->and((float) $fixed->discount_amount)->toBe(30.0)
        ->and($fixed->items()->first()->item_discount_type)->toBe('fixed')
        ->and((float) $fixed->items()->first()->item_discount_value)->toBe(30.0)
        ->and((float) $percentage->discount_amount)->toBe(20.0);
});

test('whole-sale fixed and percentage discounts are allocated exactly and deterministically', function () {
    $products = collect(range(1, 3))->map(function (int $number) {
        $product = $this->product->replicate();
        $product->sku = 'ORDER-DISCOUNT-'.$number;
        $product->barcode = null;
        $product->save();
        StockMovement::create([
            'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'stock_location_id' => $this->location->id,
            'movement_type' => 'direct_stock_in', 'quantity' => 10, 'quantity_in' => 10, 'quantity_out' => 0,
            'unit_cost' => 10, 'unit_price' => 100, 'created_by' => $this->admin->id, 'movement_date' => today(),
        ]);

        return $product;
    });
    $cart = $products->map(fn (Product $product) => [
        'product_id' => $product->id, 'sale_type' => 'retail', 'quantity' => 1,
        'discount_type' => 'fixed', 'discount_value' => 99,
    ])->all();

    $fixed = completeDiscountSale($this, $cart, 290, ['mode' => 'order', 'type' => 'fixed', 'value' => 10]);
    $percentage = completeDiscountSale($this, $cart, 270, ['mode' => 'order', 'type' => 'percentage', 'value' => 10]);

    expect($fixed->discount_mode)->toBe('order')
        ->and((float) $fixed->discount_amount)->toBe(10.0)
        ->and($fixed->items()->orderBy('id')->pluck('allocated_order_discount')->map(fn ($value) => (float) $value)->all())->toBe([3.33, 3.33, 3.34])
        ->and((float) $fixed->items()->sum('allocated_order_discount'))->toBe(10.0)
        ->and((float) $percentage->discount_amount)->toBe(30.0);

    $this->get(route('sales.receipt', $fixed))->assertOk()->assertSee('Order Discount');
});

test('discount validation rejects over-limit values and modes cannot be combined', function () {
    expect(fn () => completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 1,
        'discount_type' => 'percentage', 'discount_value' => 101,
    ]], 0, ['mode' => 'item']))->toThrow(ValidationException::class);

    expect(fn () => completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 1,
        'discount_type' => 'fixed', 'discount_value' => 90,
    ]], 90, ['mode' => 'order', 'type' => 'fixed', 'value' => 110]))->toThrow(ValidationException::class);
});

test('VAT remains calculated before discount while stock quantity and COGS stay unchanged', function () {
    $this->product->update(['taxable' => true]);
    $before = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $expectedBaseUnitCost = $this->inventory->getAverageCost($this->product->id, $this->location->id, $this->branch->id);
    $sale = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 2,
        'discount_type' => 'fixed', 'discount_value' => 20, 'tax_amount' => 999,
    ]], 216, ['mode' => 'item']);
    $item = $sale->items()->firstOrFail();

    expect((float) $sale->subtotal)->toBe(200.0)->and((float) $sale->discount_amount)->toBe(20.0)
        ->and((float) $sale->tax_amount)->toBe(36.0)->and((float) $sale->total_amount)->toBe(216.0)
        ->and((float) $item->base_unit_cost)->toBe((float) $expectedBaseUnitCost)
        ->and($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - 2);
});

test('discount permission is enforced and historical sales retain nullable legacy snapshots', function () {
    $cashier = User::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);
    $cashier->assignRole('Cashier');

    expect(fn () => $this->inventory->completeSale(
        [['product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => 1]],
        [['payment_method' => 'cash', 'amount' => 99]],
        null,
        $this->location->id,
        $this->branch->id,
        $cashier->id,
        discountDetails: ['mode' => 'item'],
    ))->toThrow(ValidationException::class);

    $this->actingAs($cashier);
    Volt::test('pos.index')
        ->assertSeeHtml('data-pos-section="cart-discount-mode"')
        ->assertDontSeeHtml('data-discount-mode-selector')
        ->assertSee('Hakuna');

    expect(Sale::where('sale_number', 'SALE-SEED-0001')->firstOrFail()->discount_mode)->toBeNull();
});

test('cancelling a discounted sale restores the same base stock quantity', function () {
    $before = $this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $sale = completeDiscountSale($this, [[
        'product_id' => $this->product->id, 'sale_type' => 'retail', 'quantity' => 2,
        'discount_type' => 'fixed', 'discount_value' => 20,
    ]], 180, ['mode' => 'item']);

    expect($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - 2);
    $this->inventory->cancelSale($sale->id, $this->admin->id);
    expect($this->inventory->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before);
});
