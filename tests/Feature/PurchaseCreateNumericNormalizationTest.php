<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AccountingService;
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
        ->assertSee('TZS 0')
        ->assertDontSee('TZS 0.00');
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

test('quantity and paid amount use blur bindings instead of per-keystroke requests', function () {
    Volt::test('purchases.create')
        ->assertSeeHtml('wire:model.blur="items.0.ordered_quantity"')
        ->assertSeeHtml('wire:model.blur="paid_amount"')
        ->assertSeeHtml('wire:submit="submitPurchase"')
        ->assertDontSeeHtml('wire:model.live="items.0.ordered_quantity"')
        ->assertDontSeeHtml('wire:model.live="paid_amount"');

    $javascript = file_get_contents(resource_path('js/app.js'));
    $inputHandler = str($javascript)
        ->between("display.addEventListener('input'", "display.addEventListener('focus'")
        ->toString();

    expect($javascript)
        ->toContain("attributeName.includes('.blur')")
        ->toContain("display.addEventListener('blur', async")
        ->toContain('display.value = normalizeMoneyValue(value.value)')
        ->toContain('window.Livewire.find(id)')
        ->toContain('const wire = moneyLivewire(field);')
        ->toContain('await wire.$set(model, nextValue, true)')
        ->toContain("document.addEventListener('submit'")
        ->toContain('synchronizePendingMoneyFields(form)')
        ->toContain("typeof wire.\$set === 'function'")
        ->not->toContain('component.$wire.$set')
        ->not->toContain('paidAmount')
        ->and($inputHandler)->toContain('if (commitsOnBlur)')
        ->and(strpos($inputHandler, 'return;'))->toBeLessThan(strpos($inputHandler, 'display.value = formatMoneyValue(normalized)'));
});

test('large and fractional typed quantities remain exact after recalculation', function () {
    $product = $this->products->first();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '10')
        ->set('items.0.ordered_quantity', '10000')
        ->assertSet('items.0.ordered_quantity', 10000.0)
        ->assertSet('items.0.line_total', 100000.0)
        ->set('paid_amount', '50000')
        ->assertSet('paid_amount', 50000.0)
        ->assertSet('balance_amount', 50000.0);

    $component
        ->set('items.0.ordered_quantity', '0.25')
        ->assertSet('items.0.ordered_quantity', 0.25)
        ->assertSet('items.0.line_total', 2.5);
});

test('purchase rows retain stable uuid keys while values change', function () {
    $product = $this->products->first();
    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id);

    $uuid = $component->get('items.0.uuid');

    expect($uuid)->toBeString()->not->toBeEmpty();

    $component
        ->set('items.0.ordered_quantity', '123456')
        ->set('items.0.cost_price', '28,000')
        ->assertSet('items.0.uuid', $uuid)
        ->assertSet('items.0.ordered_quantity', 123456.0)
        ->assertSet('items.0.cost_price', 28000.0)
        ->assertSeeHtml('wire:key="purchase-item-'.$uuid.'"');
});

test('editing one purchase row does not reset another row', function () {
    [$firstProduct, $secondProduct] = $this->products->values();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $firstProduct->id)
        ->call('addItem')
        ->call('selectProduct', 1, (string) $secondProduct->id)
        ->set('items.1.ordered_quantity', '7.25')
        ->set('items.1.cost_price', '12,500');

    $secondUuid = $component->get('items.1.uuid');

    $component
        ->set('items.0.ordered_quantity', '123456')
        ->assertSet('items.1.uuid', $secondUuid)
        ->assertSet('items.1.product_id', (string) $secondProduct->id)
        ->assertSet('items.1.ordered_quantity', 7.25)
        ->assertSet('items.1.cost_price', 12500.0);
});

test('full partial and zero payments calculate the correct live balance', function () {
    $product = $this->products->first();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '9,500,000')
        ->set('paid_amount', '9,500,000')
        ->assertSet('grand_total', 9500000.0)
        ->assertSet('paid_amount', 9500000.0)
        ->assertSet('balance_amount', 0.0)
        ->assertSee('Balance: TZS 0')
        ->assertDontSee('Balance: TZS 0.00');

    $component
        ->set('paid_amount', '2 000 000')
        ->assertSet('paid_amount', 2000000.0)
        ->assertSet('balance_amount', 7500000.0)
        ->assertSee('Balance: TZS 7,500,000');

    $component
        ->set('paid_amount', '')
        ->assertSet('paid_amount', 0.0)
        ->assertSet('balance_amount', 9500000.0);
});

test('exact formatted full payment updates canonical state summary and immediate submit', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '2,800,000')
        ->set('paid_amount', '2,800,000')
        ->set('reference_number', 'PO-EXACT-PAID-BLUR')
        ->assertSet('paid_amount', 2800000.0)
        ->assertSet('grand_total', 2800000.0)
        ->assertSet('balance_amount', 0.0)
        ->assertSee('Paid')
        ->assertSee('TZS 2,800,000')
        ->assertSee('Balance: TZS 0')
        ->call('submitPurchase')
        ->assertHasNoErrors();

    $purchase = Purchase::where('reference_number', 'PO-EXACT-PAID-BLUR')->firstOrFail();

    expect((float) $purchase->paid_amount)->toBe(2800000.0)
        ->and((float) $purchase->balance_amount)->toBe(0.0)
        ->and($purchase->payment_status)->toBe('paid');
});

test('raw fast typing and fractional paid amounts remain exact', function () {
    $product = $this->products->first();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '2800000')
        ->set('paid_amount', '2800000')
        ->assertSet('paid_amount', 2800000.0)
        ->assertSet('balance_amount', 0.0)
        ->assertSee('TZS 2,800,000');

    $component
        ->set('items.0.cost_price', '100.50')
        ->set('paid_amount', '50.25')
        ->assertSet('paid_amount', 50.25)
        ->assertSet('grand_total', 100.50)
        ->assertSet('balance_amount', 50.25)
        ->assertSee('Paid')
        ->assertSee('TZS 50.25');
});

test('quantity and line removal update grand total and balance', function () {
    [$firstProduct, $secondProduct] = $this->products->values();

    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $firstProduct->id)
        ->set('items.0.cost_price', '100')
        ->set('items.0.ordered_quantity', '2')
        ->call('addItem')
        ->call('selectProduct', 1, (string) $secondProduct->id)
        ->set('items.1.cost_price', '50')
        ->set('paid_amount', '30')
        ->assertSet('grand_total', 250.0)
        ->assertSet('balance_amount', 220.0);

    $component
        ->set('items.0.ordered_quantity', '3')
        ->assertSet('grand_total', 350.0)
        ->assertSet('balance_amount', 320.0)
        ->call('removeItem', 1)
        ->assertSet('grand_total', 300.0)
        ->assertSet('balance_amount', 270.0);
});

test('paid amount above purchase total is rejected with a clear message', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '100')
        ->set('paid_amount', '101')
        ->call('savePurchase', 'ordered')
        ->assertHasErrors(['paid_amount'])
        ->assertSee('Paid amount cannot exceed the purchase total.');
});

test('purchase payment statuses supplier balance and initial ledger payment are consistent', function () {
    $product = $this->products->first();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '1000')
        ->set('paid_amount', '1000')
        ->set('payment_method', 'mobile_money')
        ->set('payment_reference_number', 'M-PESA-FULL')
        ->set('reference_number', 'PO-BALANCE-FULL')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '1000')
        ->set('paid_amount', '250')
        ->set('payment_method', 'cash')
        ->set('reference_number', 'PO-BALANCE-PARTIAL')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $product->id)
        ->set('items.0.cost_price', '1000')
        ->set('paid_amount', '0')
        ->set('reference_number', 'PO-BALANCE-UNPAID')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();

    $full = Purchase::where('reference_number', 'PO-BALANCE-FULL')->firstOrFail();
    $partial = Purchase::where('reference_number', 'PO-BALANCE-PARTIAL')->firstOrFail();
    $unpaid = Purchase::where('reference_number', 'PO-BALANCE-UNPAID')->firstOrFail();
    $fullPayment = SupplierPayment::where('purchase_id', $full->id)->firstOrFail();

    expect($full->payment_status)->toBe('paid')
        ->and((float) $full->balance_amount)->toBe(0.0)
        ->and($partial->payment_status)->toBe('partial')
        ->and((float) $partial->balance_amount)->toBe(750.0)
        ->and($unpaid->payment_status)->toBe('unpaid')
        ->and((float) $unpaid->balance_amount)->toBe(1000.0)
        ->and($fullPayment->payment_method)->toBe('mobile_money')
        ->and($fullPayment->reference_number)->toBe('M-PESA-FULL')
        ->and((float) $fullPayment->amount)->toBe(1000.0)
        ->and(SupplierPayment::where('purchase_id', $partial->id)->count())->toBe(1)
        ->and(SupplierPayment::where('purchase_id', $unpaid->id)->count())->toBe(0)
        ->and(app(AccountingService::class)->supplierBalance($this->supplier->fresh()))->toBe(1750.0);

    app(AccountingService::class)->recordInitialPurchasePayment($full, [
        'amount' => 1000,
        'payment_method' => 'cash',
        'payment_date' => today()->toDateString(),
    ], $this->admin->id);

    expect(SupplierPayment::where('purchase_id', $full->id)->count())->toBe(1);

    $this->get(route('supplier-balances.show', $this->supplier))
        ->assertOk()
        ->assertSee('Supplier Ledger')
        ->assertSee('Debit / Owed')
        ->assertSee('Credit / Paid')
        ->assertSee('M-PESA-FULL');
});
