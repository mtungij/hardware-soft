<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PurchaseCostBreakdownService;
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

test('receipt landed costs are snapshotted and valued separately from supplier payable', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 100);
    $item->update(['cost_price' => 15000, 'line_total' => 1500000]);
    $purchase->update(['total_amount' => 1500000, 'balance_amount' => 1500000]);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($purchase->company_id);
    $transport = PurchaseCostType::where('name', 'Transportation')->firstOrFail();
    $duty = PurchaseCostType::where('name', 'Import Duty')->firstOrFail();

    $receipt = app(InventoryService::class)->receivePurchase(
        $purchase, [$item->id => ['quantity' => 100, 'stock_location_id' => $this->location->id]],
        today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [
            ['type_id' => $transport->id, 'amount' => '120000', 'payee' => 'Haulier', 'payment_method' => 'bank', 'payment_reference' => 'TR-1'],
            ['type_id' => $duty->id, 'amount' => '80000', 'payee' => 'Government'],
        ]],
    );
    $line = $receipt->items()->firstOrFail();
    $movement = StockMovement::where('reference_type', GoodsReceivingNote::class)
        ->where('reference_id', $receipt->id)->firstOrFail();

    expect((float) $receipt->goods_value)->toBe(1500000.0)
        ->and((float) $receipt->additional_cost_total)->toBe(200000.0)
        ->and((float) $receipt->landed_total)->toBe(1700000.0)
        ->and((float) $line->allocated_additional_cost)->toBe(200000.0)
        ->and((float) $line->landed_unit_cost)->toBe(17000.0)
        ->and((float) $movement->quantity)->toBe((float) $line->stock_quantity)
        ->and((float) $movement->unit_cost)->toBe((float) $line->landed_base_unit_cost)
        ->and((float) $purchase->fresh()->balance_amount)->toBe(1500000.0)
        ->and($receipt->additionalCosts()->count())->toBe(2)
        ->and($receipt->additionalCosts()->first()->payee)->toBe('Haulier');

    $this->get(route('goods-receipts.show', $receipt))
        ->assertOk()
        ->assertSee('Total Landed Cost')
        ->assertSee('Haulier');

    $item->product->update(['buying_price' => 99999]);
    expect((float) $receipt->items()->first()->cost_price)->toBe(15000.0)
        ->and((float) $receipt->items()->first()->landed_unit_cost)->toBe(17000.0);
});

test('additional cost allocation follows received goods value and each partial receipt has its own cost', function () {
    [$purchase, $first] = grnPurchase($this->branch, $this->admin, 100);
    $first->update(['cost_price' => 15000, 'line_total' => 1500000]);
    $secondProduct = Product::whereKeyNot($first->product_id)->firstOrFail();
    $second = $purchase->items()->create([
        'company_id' => $purchase->company_id, 'product_id' => $secondProduct->id,
        'purchase_unit_id' => $secondProduct->unit_id, 'stock_unit_id' => $secondProduct->unit_id,
        'purchase_conversion_factor' => 1, 'ordered_quantity' => 100, 'received_quantity' => 0,
        'cost_price' => 35000, 'selling_price' => 40000, 'line_total' => 3500000,
    ]);
    $purchase->update(['total_amount' => 5000000, 'balance_amount' => 5000000]);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($purchase->company_id);
    $transport = PurchaseCostType::where('name', 'Transportation')->firstOrFail();
    $service = app(InventoryService::class);

    $receipt = $service->receivePurchase($purchase, [
        $first->id => ['quantity' => 100, 'stock_location_id' => $this->location->id],
        $second->id => ['quantity' => 100, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [['type_id' => $transport->id, 'amount' => '500000']]]);
    expect((float) $receipt->items()->where('purchase_item_id', $first->id)->first()->allocated_additional_cost)->toBe(150000.0)
        ->and((float) $receipt->items()->where('purchase_item_id', $second->id)->first()->allocated_additional_cost)->toBe(350000.0);

    [$partialPurchase, $partialItem] = grnPurchase($this->branch, $this->admin, 100);
    $partialItem->update(['cost_price' => 15000]);
    $one = $service->receivePurchase($partialPurchase, [
        $partialItem->id => ['quantity' => 40, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [['type_id' => $transport->id, 'amount' => '120000']]]);
    $two = $service->receivePurchase($partialPurchase->fresh(), [
        $partialItem->id => ['quantity' => 60, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id);

    expect((float) $one->landed_total)->toBe(720000.0)
        ->and((float) $one->items()->first()->landed_unit_cost)->toBe(18000.0)
        ->and((float) $two->landed_total)->toBe(900000.0)
        ->and((float) $two->items()->first()->landed_unit_cost)->toBe(15000.0);
});

test('draft receipt posts its saved converted base unit cost once', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 10);
    $item->update(['purchase_conversion_factor' => 12, 'cost_price' => 180000, 'line_total' => 1800000]);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($purchase->company_id);
    $transport = PurchaseCostType::where('name', 'Transportation')->firstOrFail();
    $service = app(InventoryService::class);

    $draft = $service->receivePurchase($purchase, [
        $item->id => ['quantity' => 10, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id,
        header: ['status' => 'draft', 'additional_costs' => [['type_id' => $transport->id, 'amount' => '120000']]]);
    expect((float) $draft->items()->first()->stock_quantity)->toBe(120.0)
        ->and((float) $draft->items()->first()->landed_base_unit_cost)->toBe(16000.0)
        ->and((float) $item->fresh()->received_quantity)->toBe(0.0);

    $service->postGoodsReceipt($draft, $this->admin->id);
    $movement = StockMovement::where('reference_type', GoodsReceivingNote::class)
        ->where('reference_id', $draft->id)->firstOrFail();
    expect((float) $movement->quantity)->toBe(120.0)
        ->and((float) $movement->unit_cost)->toBe(16000.0)
        ->and((float) $item->fresh()->received_quantity)->toBe(10.0)
        ->and((float) $item->fresh()->base_received_quantity)->toBe(120.0)
        ->and(fn () => $service->postGoodsReceipt($draft->fresh(), $this->admin->id))->toThrow(ValidationException::class);
});

test('sale COGS and remaining stock valuation use received landed unit cost', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 10);
    $item->update(['cost_price' => 100, 'line_total' => 1000]);
    $item->product->update(['buying_price' => 100, 'selling_price' => 200]);
    $this->location->update(['can_sell' => true, 'is_sellable' => true]);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($purchase->company_id);
    $transport = PurchaseCostType::where('name', 'Transportation')->firstOrFail();
    $service = app(InventoryService::class);
    $service->receivePurchase($purchase, [
        $item->id => ['quantity' => 10, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [['type_id' => $transport->id, 'amount' => '200']]]);

    expect($service->getAverageCost($item->product_id, $this->location->id, $this->branch->id))->toBe(120.0)
        ->and($service->getProductStock($item->product_id, $this->location->id, $this->branch->id))->toBe(10.0);

    $sale = $service->completeSale(
        [['product_id' => $item->product_id, 'quantity' => 1, 'sale_type' => 'retail', 'unit_price' => 200, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 200]],
        null, $this->location->id, $this->branch->id, $this->admin->id,
    );
    expect((float) $sale->items()->first()->base_unit_cost)->toBe(120.0)
        ->and((float) StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->first()->unit_cost)->toBe(120.0)
        ->and($service->getProductStock($item->product_id, $this->location->id, $this->branch->id) * $service->getAverageCost($item->product_id, $this->location->id, $this->branch->id))->toBe(1080.0);
});

test('receiver can add a configured cost type and post additional costs from the receiving form', function () {
    [$purchase, $item] = grnPurchase($this->branch, $this->admin, 2);
    $component = Volt::test('purchases.receive', ['purchase' => $purchase])
        ->set('new_cost_type', 'Custom Handling')
        ->call('addCostType')
        ->assertHasNoErrors()
        ->call('addAdditionalCost')
        ->set("lines.{$item->id}.quantity", '2');
    $type = PurchaseCostType::where('name', 'Custom Handling')->firstOrFail();
    $component->set('additional_costs.0.type_id', (string) $type->id)
        ->set('additional_costs.0.amount', '25.50')
        ->set('additional_costs.0.payee', 'Port agent')
        ->call('postReceipt')
        ->assertHasNoErrors();

    $receipt = GoodsReceivingNote::where('purchase_id', $purchase->id)->firstOrFail();
    expect((float) $receipt->goods_value)->toBe(200.0)
        ->and((float) $receipt->landed_total)->toBe(225.5)
        ->and($receipt->additionalCosts()->first()->payee)->toBe('Port agent');
});
