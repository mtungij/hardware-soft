<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->supplier = Supplier::query()->create([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->admin->branch_id,
        'name' => 'Numeric Purchase Supplier',
        'phone' => '+255 700 808 808',
        'status' => 'active',
    ]);
    $this->products = Product::query()->limit(2)->get();
    $this->actingAs($this->admin);
});

test('product selection assigns database prices as floats and safely renders the line', function () {
    $product = $this->products->first();
    $product->update([
        'buying_price' => 28000.50,
        'selling_price' => 35000.75,
    ]);

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->assertSet('items.0.product_id', (string) $product->id)
        ->assertSet('items.0.cost_price', 28000.50)
        ->assertSet('items.0.selling_price', 35000.75)
        ->assertSet('items.0.line_total', 28000.50)
        ->assertSee('TZS 28,000.50');
});

test('formatted cost decimal quantity and empty quantity are normalized without render errors', function () {
    $product = $this->products->first();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '1.5')
        ->set('items.0.cost_price', '28,000.50')
        ->assertSet('items.0.ordered_quantity', 1.5)
        ->assertSet('items.0.cost_price', 28000.50)
        ->assertSet('items.0.line_total', 42000.75)
        ->assertSet('subtotal', 42000.75)
        ->assertSet('grand_total', 42000.75)
        ->assertSee('TZS 42,000.75');

    $component
        ->set('items.0.ordered_quantity', '')
        ->assertSet('items.0.ordered_quantity', 0.0)
        ->assertSet('items.0.line_total', 0.0)
        ->assertSet('grand_total', 0.0)
        ->assertSee('TZS 0.00');
});

test('multiple formatted purchase lines calculate subtotal grand total paid and balance', function () {
    [$firstProduct, $secondProduct] = $this->products->values();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $firstProduct->id)
        ->call('addItem')
        ->call('selectProduct', 1, (string) $secondProduct->id)
        ->set('items.0.ordered_quantity', '2')
        ->set('items.0.cost_price', '28,000')
        ->set('items.1.ordered_quantity', '0.5')
        ->set('items.1.cost_price', '10,000.50')
        ->set('paid_amount', '10,000')
        ->assertSet('items.0.line_total', 56000.0)
        ->assertSet('items.1.line_total', 5000.25)
        ->assertSet('subtotal', 61000.25)
        ->assertSet('grand_total', 61000.25)
        ->assertSet('paid_amount', 10000.0)
        ->assertSet('balance_amount', 51000.25)
        ->assertSee('TZS 61,000.25')
        ->assertSee('Balance: TZS 51,000.25');
});

test('discount tax and null numeric values use the same normalization pipeline', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '2')
        ->set('items.0.cost_price', '28,000')
        ->set('items.0.discount', '1,000')
        ->set('items.0.tax', '500.50')
        ->set('items.0.received_quantity', null)
        ->set('paid_amount', null)
        ->assertSet('items.0.received_quantity', 0.0)
        ->assertSet('items.0.discount', 1000.0)
        ->assertSet('items.0.tax', 500.50)
        ->assertSet('items.0.line_total', 55500.50)
        ->assertSet('subtotal', 56000.0)
        ->assertSet('grand_total', 55500.50)
        ->assertSet('balance_amount', 55500.50);
});

test('normalized values pass numeric validation and are persisted consistently', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '2')
        ->set('items.0.cost_price', '28,000')
        ->set('paid_amount', '10,000')
        ->set('reference_number', 'PO-NUMERIC-NORMALIZATION')
        ->call('savePurchase', 'draft')
        ->assertHasNoErrors();

    $purchase = Purchase::query()
        ->where('reference_number', 'PO-NUMERIC-NORMALIZATION')
        ->with('items')
        ->firstOrFail();

    expect((float) $purchase->total_amount)->toBe(56000.0)
        ->and((float) $purchase->paid_amount)->toBe(10000.0)
        ->and((float) $purchase->balance_amount)->toBe(46000.0)
        ->and((float) $purchase->items->first()->ordered_quantity)->toBe(2.0)
        ->and((float) $purchase->items->first()->cost_price)->toBe(28000.0)
        ->and((float) $purchase->items->first()->line_total)->toBe(56000.0);
});

test('empty ordered quantity fails validation instead of crashing arithmetic', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.ordered_quantity', '')
        ->call('savePurchase', 'draft')
        ->assertHasErrors(['items.0.ordered_quantity']);
});
