<?php

use App\Models\Branch;
use App\Models\GoodsReceivingNoteItem;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->update([
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_sell' => true,
        'is_active' => true,
    ]);
    $this->supplier = Supplier::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Purchase Unit Supplier',
        'phone' => '255700123456',
        'status' => 'active',
    ]);
    $this->actingAs($this->admin);
});

test('existing products use their base stock unit as purchase unit', function () {
    $products = Product::query()->get();

    expect($products)->not->toBeEmpty()
        ->and($products->every(fn (Product $product) => $product->purchase_unit_id === $product->unit_id))->toBeTrue()
        ->and($products->every(fn (Product $product) => (float) $product->purchase_conversion_factor === 1.0))->toBeTrue();
});

test('paint buckets are received in purchase units and stored as litres', function () {
    $litre = Unit::where('short_name', 'L')->firstOrFail();
    $bucket = Unit::query()->create([
        'company_id' => $this->branch->company_id,
        'name' => '20L Bucket',
        'code' => 'bucket-20l',
        'measurement_type_id' => MeasurementType::where('code', MeasurementType::COUNT)->value('id'),
        'short_name' => 'bucket',
        'status' => 'active',
    ]);
    $paint = purchaseUnitProduct('Paint 20L', $litre, $bucket, 20);
    [$purchase, $item] = purchaseUnitOrder($paint, $this->supplier, $this->branch, $this->admin, 10, 50000);

    $receipt = app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => ['quantity' => 10, 'stock_location_id' => $this->location->id]],
        today()->toDateString(),
        $this->admin->id,
    );

    $receiptItem = GoodsReceivingNoteItem::where('goods_receiving_note_id', $receipt->id)->firstOrFail();
    $movement = StockMovement::where('reference_id', $receipt->id)->where('product_id', $paint->id)->firstOrFail();

    expect((float) $receiptItem->received_quantity)->toBe(10.0)
        ->and((float) $receiptItem->stock_quantity)->toBe(200.0)
        ->and($receiptItem->purchase_unit_id)->toBe($bucket->id)
        ->and($receiptItem->stock_unit_id)->toBe($litre->id)
        ->and((float) $movement->quantity_in)->toBe(200.0)
        ->and((float) $movement->unit_cost)->toBe(2500.0)
        ->and(app(InventoryService::class)->getProductStock($paint->id, $this->location->id, $this->branch->id))->toBe(200.0);
});

test('pipe purchase and stock units remain pieces while POS conversion sells metres', function () {
    $piece = Unit::where('short_name', 'pcs')->firstOrFail();
    $metre = Unit::where('short_name', 'm')->firstOrFail();
    $pipe = purchaseUnitProduct('PVC Pipe', $piece, $piece, 1, [
        'measurement_type_id' => MeasurementType::where('code', MeasurementType::LENGTH)->value('id'),
        'selling_unit_id' => $metre->id,
        'conversion_factor' => 3,
        'allow_fractional_sale' => true,
        'minimum_sale_quantity' => 0.5,
        'quantity_step' => 0.5,
    ]);

    StockMovement::query()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'product_id' => $pipe->id,
        'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 100,
        'created_by' => $this->admin->id,
        'movement_date' => today(),
    ]);

    $sale = app(InventoryService::class)->completeSale(
        [['product_id' => $pipe->id, 'quantity' => 1.5, 'discount_amount' => 0, 'tax_amount' => 0]],
        [['payment_method' => 'cash', 'amount' => 225]],
        null,
        $this->location->id,
        $this->branch->id,
        $this->admin->id,
    );
    $saleItem = $sale->items()->with(['sellingUnit', 'baseUnit'])->firstOrFail();

    expect($pipe->stockQuantityForPurchase(50))->toBe(50.0)
        ->and((float) $saleItem->quantity)->toBe(1.5)
        ->and((float) $saleItem->base_quantity)->toBe(0.5)
        ->and($saleItem->sellingUnit?->short_name)->toBe('m')
        ->and($saleItem->baseUnit?->short_name)->toBe('pcs')
        ->and(app(InventoryService::class)->getProductStock($pipe->id, $this->location->id, $this->branch->id))->toBe(9.5);
});

test('nails require no conversion when all three units are kilograms', function () {
    $kg = Unit::where('short_name', 'kg')->firstOrFail();
    $nails = purchaseUnitProduct('Wire Nails', $kg, $kg, 1, [
        'measurement_type_id' => MeasurementType::where('code', MeasurementType::WEIGHT)->value('id'),
        'selling_unit_id' => $kg->id,
        'allow_fractional_sale' => true,
    ]);

    expect($nails->purchaseConversionFactor())->toBe(1.0)
        ->and($nails->saleConversionFactor())->toBe(1.0)
        ->and($nails->stockQuantityForPurchase(100))->toBe(100.0);
});

test('purchase and receiving screens show purchase units and stock increase', function () {
    $product = Product::query()->with(['purchaseUnit', 'unit'])->firstOrFail();
    [$purchase] = purchaseUnitOrder($product, $this->supplier, $this->branch, $this->admin, 2, 100);

    Volt::test('purchases.receive', ['purchase' => $purchase])
        ->assertSee('Purchase Unit')
        ->assertSee('Received Quantity')
        ->assertSee('Stock Increase');

    Volt::test('purchases.create')
        ->assertSee('Purchase Unit')
        ->assertSee('Ordered Qty')
        ->assertSee('Unit Cost');
});

function purchaseUnitProduct(string $name, Unit $stockUnit, Unit $purchaseUnit, float $purchaseFactor, array $overrides = []): Product
{
    $template = Product::query()->firstOrFail();

    return Product::query()->create(array_merge([
        'company_id' => $template->company_id,
        'branch_id' => $template->branch_id,
        'category_id' => $template->category_id,
        'measurement_type_id' => $stockUnit->measurement_type_id,
        'purchase_unit_id' => $purchaseUnit->id,
        'purchase_conversion_factor' => $purchaseFactor,
        'unit_id' => $stockUnit->id,
        'selling_unit_id' => $stockUnit->id,
        'name' => $name,
        'sku' => 'PU-'.uniqid(),
        'buying_price' => 100,
        'selling_price' => 150,
        'conversion_factor' => 1,
        'allow_fractional_sale' => false,
        'minimum_sale_quantity' => 1,
        'quantity_step' => 1,
        'reorder_level' => 1,
        'status' => 'active',
    ], $overrides));
}

function purchaseUnitOrder(Product $product, Supplier $supplier, Branch $branch, User $user, float $quantity, float $cost): array
{
    $purchase = Purchase::query()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'purchase_date' => today(),
        'reference_number' => 'PU-PO-'.uniqid(),
        'status' => 'ordered',
        'payment_status' => 'unpaid',
        'total_amount' => $quantity * $cost,
        'paid_amount' => 0,
        'balance_amount' => $quantity * $cost,
        'created_by' => $user->id,
    ]);

    $item = $purchase->items()->create([
        'company_id' => $branch->company_id,
        'product_id' => $product->id,
        'purchase_unit_id' => $product->purchase_unit_id ?: $product->unit_id,
        'stock_unit_id' => $product->unit_id,
        'purchase_conversion_factor' => $product->purchaseConversionFactor(),
        'ordered_quantity' => $quantity,
        'received_quantity' => 0,
        'cost_price' => $cost,
        'selling_price' => $product->selling_price,
        'line_total' => $quantity * $cost,
    ]);

    return [$purchase, $item];
}
