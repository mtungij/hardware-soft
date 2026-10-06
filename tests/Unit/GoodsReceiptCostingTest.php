<?php

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\GoodsReceiptCostingService;

function costingItem(int $id, float $cost, float $factor = 1): PurchaseItem
{
    $product = new Product(['unit_id' => 1, 'selling_unit_id' => 1, 'selling_price' => 126000]);
    $item = new PurchaseItem(['purchase_unit_id' => 1, 'purchase_conversion_factor' => $factor, 'cost_price' => $cost]);
    $item->forceFill(['id' => $id]);
    $item->setRelation('product', $product);

    return $item;
}

test('receipt costs and markup profit reconcile for the quoted example', function () {
    $result = (new GoodsReceiptCostingService)->calculate(collect([costingItem(1, 95000)]), [
        1 => ['quantity' => 10, 'markup_percentage' => 20, 'preview_selling_price' => 126000],
    ], 100000 * 100);
    expect($result['quantity'])->toBe(10)
        ->and($result['cost'])->toBe(950000)
        ->and($result['landed'])->toBe(1050000)
        ->and($result['landed_per_unit'])->toBe(10000.0)
        ->and($result['rows'][1]['final_unit_cost'])->toBe(105000.0)
        ->and($result['rows'][1]['suggested_selling_price'])->toBe(126000.0)
        ->and($result['rows'][1]['profit_per_unit'])->toBe(21000.0)
        ->and($result['rows'][1]['profit_margin'])->toBe(16.67);
});

test('zero received quantity and zero selling price are safe', function () {
    $service = new GoodsReceiptCostingService;
    $result = $service->calculate(collect([costingItem(1, 95000)]), [1 => ['quantity' => 0]], 10000);
    expect($result['landed_per_unit'])->toBe(0)->and($result['rows'])->toBe([])
        ->and($service->pricePreview(100, null, 0)['profit_margin'])->toBe(0);
});

test('mixed purchase conversions allocate by actual base units rather than value or ordered units', function () {
    $result = (new GoodsReceiptCostingService)->calculate(collect([
        costingItem(1, 95000, 5), costingItem(2, 10000),
    ]), [1 => ['quantity' => 1], 2 => ['quantity' => 5]], 100000 * 100);
    expect($result['quantity'])->toBe(10)
        ->and($result['rows'][1]['allocated_cents'])->toBe(5000000)
        ->and($result['rows'][2]['allocated_cents'])->toBe(5000000)
        ->and($result['rows'][1]['final_unit_cost'])->toBe(29000.0)
        ->and($result['rows'][2]['final_unit_cost'])->toBe(20000.0)
        ->and($result['landed'])->toBe($result['cost'] + $result['additional']);
});

test('every cent of additional costs is allocated once across fractional quantities', function () {
    $result = (new GoodsReceiptCostingService)->calculate(collect([
        costingItem(1, 1), costingItem(2, 2), costingItem(3, 3),
    ]), [1 => ['quantity' => 0.3], 2 => ['quantity' => 0.3], 3 => ['quantity' => 0.3]], 1);
    expect(array_sum(array_column($result['rows'], 'allocated_cents')))->toBe(1)
        ->and(array_sum(array_column($result['rows'], 'landed_line_cost')))->toEqualWithDelta($result['landed'], 0.000001);
});

test('recommendations and profit use the products selling unit conversion', function () {
    $item = costingItem(1, 12000);
    $item->product->fill(['selling_unit_id' => 2, 'conversion_factor' => 12]);
    $result = (new GoodsReceiptCostingService)->calculate(collect([$item]), [
        1 => ['quantity' => 1, 'markup_percentage' => 20, 'preview_selling_price' => 1200],
    ], 0);
    expect($result['rows'][1]['final_selling_unit_cost'])->toBe(1000.0)
        ->and($result['rows'][1]['suggested_selling_price'])->toBe(1200.0)
        ->and($result['rows'][1]['profit_per_unit'])->toBe(200.0);
});
