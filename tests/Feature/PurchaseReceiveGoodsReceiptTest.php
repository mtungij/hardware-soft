<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->update(['can_receive_stock' => true, 'is_active' => true, 'status' => 'active']);
    $this->actingAs($this->admin);
});

test('PurchaseReceive first-time form gets an unused GRN and preserves it across rerenders', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 2);
    $component = Volt::test('purchases.receive', ['purchase' => $purchase]);
    $grnNumber = $component->get('grn_number');

    expect($grnNumber)->toMatch('/^GRN-\d{4}-\d{6}$/')
        ->and(GoodsReceivingNote::where('grn_number', $grnNumber)->exists())->toBeFalse();

    $component
        ->set("lines.{$item->id}.quantity", '1')
        ->set('notes', 'Rerender this form')
        ->assertSet('grn_number', $grnNumber)
        ->call('openConfirmation')
        ->assertHasNoErrors(['grn_number']);
});

test('PurchaseReceive sequential and partial receipts receive different GRNs', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 2);
    $service = app(InventoryService::class);

    $first = $service->receivePurchase(
        $purchase,
        [$item->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );
    $second = $service->receivePurchase(
        $purchase->fresh(),
        [$item->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    expect($first->grn_number)->not->toBe($second->grn_number)
        ->and($first->grn_number)->toEndWith('000001')
        ->and($second->grn_number)->toEndWith('000002');
});

test('PurchaseReceive concurrent browser previews are allocated different GRNs at save time', function () {
    [$firstPurchase, $firstItem] = grnPurchase($this->branch, $this->admin);
    [$secondPurchase, $secondItem] = grnPurchase($this->branch, $this->admin);
    $service = app(InventoryService::class);
    $firstPreview = $service->generateGrnNumber((int) $this->branch->company_id);
    $secondPreview = $service->generateGrnNumber((int) $this->branch->company_id);

    expect($firstPreview)->toBe($secondPreview);

    $first = $service->receivePurchase(
        $firstPurchase,
        [$firstItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
        header: ['grn_number' => $firstPreview, 'grn_is_system_generated' => true],
    );
    $second = $service->receivePurchase(
        $secondPurchase,
        [$secondItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
        header: ['grn_number' => $secondPreview, 'grn_is_system_generated' => true],
    );

    expect($first->grn_number)->not->toBe($second->grn_number);
});

test('PurchaseReceive save-time allocation skips an existing GRN when the counter is stale', function () {
    [$firstPurchase, $firstItem] = grnPurchase($this->branch, $this->admin);
    $service = app(InventoryService::class);
    $first = $service->receivePurchase(
        $firstPurchase,
        [$firstItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    DocumentSequence::query()->where('company_id', $this->branch->company_id)->update(['last_number' => 0]);
    [$secondPurchase, $secondItem] = grnPurchase($this->branch, $this->admin);
    $second = $service->receivePurchase(
        $secondPurchase,
        [$secondItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    expect($first->grn_number)->toEndWith('000001')
        ->and($second->grn_number)->toEndWith('000002');
});

test('PurchaseReceive rejects an existing manually supplied GRN with a friendly error', function () {
    [$firstPurchase, $firstItem] = grnPurchase($this->branch, $this->admin);
    $service = app(InventoryService::class);
    $first = $service->receivePurchase(
        $firstPurchase,
        [$firstItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );
    [$secondPurchase, $secondItem] = grnPurchase($this->branch, $this->admin);

    try {
        $service->receivePurchase(
            $secondPurchase,
            [$secondItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
            today()->toDateString(),
            $this->admin->id,
            header: ['grn_number' => $first->grn_number],
        );
        $this->fail('A duplicate manual GRN should be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['grn_number'][0])
            ->toBe('Namba hii ya GRN tayari imetumika. Tafadhali tumia namba nyingine.');
    }
});

test('PurchaseReceive database constraint is company scoped', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin);
    $receipt = app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );
    $row = $receipt->getAttributes();
    unset($row['id']);

    expect(fn () => DB::table('goods_receiving_notes')->insert($row))
        ->toThrow(QueryException::class);

    $otherCompany = Company::query()->create([
        'company_name' => 'Other GRN Company',
        'business_type' => 'Hardware Store',
        'phone' => '255700000099',
        'whatsapp_number' => '255700000099',
    ]);
    $row['company_id'] = $otherCompany->id;

    expect(DB::table('goods_receiving_notes')->insert($row))->toBeTrue();
});

test('PurchaseReceive cancelled issued GRNs are never reused', function () {
    [$firstPurchase, $firstItem] = grnPurchase($this->branch, $this->admin);
    $service = app(InventoryService::class);
    $first = $service->receivePurchase(
        $firstPurchase,
        [$firstItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );
    $first->update(['status' => 'cancelled']);

    [$secondPurchase, $secondItem] = grnPurchase($this->branch, $this->admin);
    $second = $service->receivePurchase(
        $secondPurchase,
        [$secondItem->id => ['quantity' => 1, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    expect($second->grn_number)->not->toBe($first->grn_number);
});

function grnPurchase(Branch $branch, User $user, float $quantity = 1): array
{
    $supplier = Supplier::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'name' => 'GRN Supplier '.uniqid(),
        'phone' => '2557'.random_int(10000000, 99999999),
        'status' => 'active',
    ]);
    $product = Product::query()->firstOrFail();
    $purchase = Purchase::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'purchase_date' => today(),
        'reference_number' => 'GRN-PO-'.uniqid(),
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'total_amount' => $quantity * 100,
        'paid_amount' => 0,
        'balance_amount' => $quantity * 100,
        'created_by' => $user->id,
    ]);
    $item = $purchase->items()->create([
        'company_id' => $branch->company_id,
        'product_id' => $product->id,
        'purchase_unit_id' => $product->purchase_unit_id,
        'stock_unit_id' => $product->unit_id,
        'purchase_conversion_factor' => $product->purchaseConversionFactor(),
        'ordered_quantity' => $quantity,
        'received_quantity' => 0,
        'cost_price' => 100,
        'selling_price' => 150,
        'line_total' => $quantity * 100,
    ]);

    return [$purchase, $item];
}
