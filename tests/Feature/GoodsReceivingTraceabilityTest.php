<?php

use App\Models\Branch;
use App\Models\GoodsReceivingNoteItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->update([
        'is_active' => true,
        'can_receive_stock' => true,
    ]);
    $this->supplier = Supplier::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Traceability Supplier',
        'phone' => '255711000111',
        'status' => 'active',
    ]);
    $this->actingAs($this->admin);
});

test('existing products default to no batch or expiry tracking', function () {
    $product = Product::query()->firstOrFail();

    expect($product->tracks_batch)->toBeFalse()
        ->and($product->tracks_expiry)->toBeFalse();
});

test('ordinary product is received without batch or expiry and saves both as null', function () {
    $product = traceabilityProduct(['tracks_batch' => false, 'tracks_expiry' => false]);
    [$purchase, $item] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$product]);

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => [
            'quantity' => 2,
            'stock_location_id' => $this->location->id,
            'batch_number' => 'IGNORED-BATCH',
            'expiry_date' => 'not-a-date',
        ]],
        today()->toDateString(),
        $this->admin->id,
    );

    $receiptItem = GoodsReceivingNoteItem::query()->where('purchase_item_id', $item->id)->firstOrFail();

    expect($receiptItem->batch_number)->toBeNull()
        ->and($receiptItem->expiry_date)->toBeNull();
});

test('expiry-tracked product cannot be received without an expiry date', function () {
    $product = traceabilityProduct(['tracks_expiry' => true]);
    [$purchase, $item] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$product]);

    receiveTraceabilityPurchase($purchase, $item, $this->location, $this->admin, [
        'batch_number' => 'OPTIONAL-BATCH',
    ]);
})->throws(ValidationException::class, 'requires an Expiry Date');

test('batch-tracked product cannot be received without a batch number', function () {
    $product = traceabilityProduct(['tracks_batch' => true]);
    [$purchase, $item] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$product]);

    receiveTraceabilityPurchase($purchase, $item, $this->location, $this->admin);
})->throws(ValidationException::class, 'requires a Batch Number');

test('expiry date cannot be earlier than receiving date', function () {
    $product = traceabilityProduct(['tracks_expiry' => true]);
    [$purchase, $item] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$product]);

    receiveTraceabilityPurchase($purchase, $item, $this->location, $this->admin, [
        'expiry_date' => today()->subDay()->toDateString(),
    ]);
})->throws(ValidationException::class, 'cannot be earlier');

test('mixed receiving lines validate tracked products and ignore ordinary traceability values', function () {
    $ordinary = traceabilityProduct(['tracks_batch' => false, 'tracks_expiry' => false]);
    $paint = traceabilityProduct(['tracks_batch' => true, 'tracks_expiry' => true]);
    [$purchase, $ordinaryItem, $paintItem] = traceabilityPurchase(
        $this->supplier,
        $this->branch,
        $this->admin,
        [$ordinary, $paint],
    );

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [
            $ordinaryItem->id => [
                'quantity' => 1,
                'stock_location_id' => $this->location->id,
                'batch_number' => 'SHOULD-BE-NULL',
                'expiry_date' => 'invalid-but-ignored',
            ],
            $paintItem->id => [
                'quantity' => 1,
                'stock_location_id' => $this->location->id,
                'batch_number' => 'PAINT-BATCH-1',
                'expiry_date' => today()->addYear()->toDateString(),
            ],
        ],
        today()->toDateString(),
        $this->admin->id,
    );

    $ordinaryReceipt = GoodsReceivingNoteItem::where('purchase_item_id', $ordinaryItem->id)->firstOrFail();
    $paintReceipt = GoodsReceivingNoteItem::where('purchase_item_id', $paintItem->id)->firstOrFail();

    expect($ordinaryReceipt->batch_number)->toBeNull()
        ->and($ordinaryReceipt->expiry_date)->toBeNull()
        ->and($paintReceipt->batch_number)->toBe('PAINT-BATCH-1')
        ->and($paintReceipt->expiry_date?->toDateString())->toBe(today()->addYear()->toDateString());
});

test('receiving form hides traceability columns for ordinary products and handles mixed rows', function () {
    $ordinary = traceabilityProduct(['tracks_batch' => false, 'tracks_expiry' => false]);
    [$ordinaryPurchase] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$ordinary]);

    Volt::test('purchases.receive', ['purchase' => $ordinaryPurchase])
        ->assertDontSee('Batch Number')
        ->assertDontSee('Expiry Date');

    $paint = traceabilityProduct(['tracks_batch' => true, 'tracks_expiry' => true]);
    [$mixedPurchase] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$ordinary, $paint]);

    Volt::test('purchases.receive', ['purchase' => $mixedPurchase])
        ->assertSee('Batch Number')
        ->assertSee('Expiry Date')
        ->assertSee('Not required')
        ->assertSee('Required');
});

test('receiving form conditionally validates batch and expiry only for quantities being received', function () {
    $paint = traceabilityProduct(['tracks_batch' => true, 'tracks_expiry' => true]);
    [$purchase, $item] = traceabilityPurchase($this->supplier, $this->branch, $this->admin, [$paint]);

    $component = Volt::test('purchases.receive', ['purchase' => $purchase])
        ->set("lines.{$item->id}.quantity", '1')
        ->call('openConfirmation')
        ->assertHasErrors([
            "lines.{$item->id}.batch_number",
            "lines.{$item->id}.expiry_date",
        ]);

    $component
        ->set("lines.{$item->id}.quantity", '0')
        ->call('openConfirmation')
        ->assertHasNoErrors([
            "lines.{$item->id}.batch_number",
            "lines.{$item->id}.expiry_date",
        ])
        ->assertHasErrors(['lines']);
});

test('product form exposes batch and expiry tracking checkboxes unchecked by default', function () {
    Volt::test('products.create')
        ->assertSet('tracks_batch', false)
        ->assertSet('tracks_expiry', false)
        ->assertSee('Track Batch Number')
        ->assertSee('Track Expiry Date');
});

function traceabilityProduct(array $overrides = []): Product
{
    $template = Product::query()->firstOrFail();

    return Product::query()->create(array_merge([
        'company_id' => $template->company_id,
        'branch_id' => $template->branch_id,
        'category_id' => $template->category_id,
        'measurement_type_id' => $template->measurement_type_id,
        'unit_id' => $template->unit_id,
        'selling_unit_id' => $template->selling_unit_id ?: $template->unit_id,
        'name' => 'Traceability Product '.uniqid(),
        'sku' => 'TRACE-'.uniqid(),
        'buying_price' => 100,
        'selling_price' => 150,
        'conversion_factor' => 1,
        'allow_fractional_sale' => false,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'tracks_batch' => false,
        'tracks_expiry' => false,
        'reorder_level' => 1,
        'status' => 'active',
    ], $overrides));
}

/**
 * @param  array<int, Product>  $products
 * @return array<int, mixed>
 */
function traceabilityPurchase(Supplier $supplier, Branch $branch, User $admin, array $products): array
{
    $purchase = Purchase::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'purchase_date' => today(),
        'reference_number' => 'TRACE-PO-'.uniqid(),
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'total_amount' => count($products) * 500,
        'paid_amount' => 0,
        'balance_amount' => count($products) * 500,
        'created_by' => $admin->id,
    ]);

    $items = collect($products)->map(fn (Product $product) => $purchase->items()->create([
        'company_id' => $branch->company_id,
        'product_id' => $product->id,
        'ordered_quantity' => 5,
        'received_quantity' => 0,
        'cost_price' => 100,
        'selling_price' => 150,
        'line_total' => 500,
    ]))->all();

    return [$purchase, ...$items];
}

function receiveTraceabilityPurchase(
    Purchase $purchase,
    $item,
    StockLocation $location,
    User $admin,
    array $line = [],
): void {
    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => array_merge([
            'quantity' => 1,
            'stock_location_id' => $location->id,
        ], $line)],
        today()->toDateString(),
        $admin->id,
    );
}
