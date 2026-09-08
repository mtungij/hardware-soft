<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->inventory = app(InventoryService::class);
    $this->valuation = app(FinancialReportService::class);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Transfer Cost Cement', 'sku' => 'TRANSFER-COST-CEMENT', 'barcode' => null, 'buying_price' => 9999]);
    $this->product->save();
    $locations = collect(['Main Store Cost Test', 'Zanzibar store'])->map(fn ($name) => StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => $name, 'code' => str()->random(12), 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'is_warehouse' => false, 'is_dispensing_location' => false,
        'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true,
    ]));
    [$this->source, $this->destination] = $locations->all();
});

function costTransferMovement(object $test, Product $product, StockLocation $location, float $quantity, ?float $cost, string $type = 'purchase_receipt'): StockMovement
{
    return StockMovement::create([
        'company_id' => $test->admin->company_id, 'branch_id' => $test->branch->id,
        'product_id' => $product->id, 'stock_location_id' => $location->id,
        'movement_type' => $type, 'quantity' => $quantity,
        'unit_cost' => $cost, 'created_by' => $test->admin->id, 'movement_date' => today(),
    ]);
}

function costTransferDraft(object $test, array $lines): StockTransfer
{
    $transfer = StockTransfer::create([
        'company_id' => $test->admin->company_id, 'branch_id' => $test->branch->id,
        'transfer_number' => 'COST-'.str()->random(12), 'from_location_id' => $test->source->id,
        'to_location_id' => $test->destination->id, 'transfer_date' => today(),
        'status' => 'draft', 'created_by' => $test->admin->id,
    ]);
    foreach ($lines as [$product, $quantity]) {
        $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
    }

    return $transfer;
}

function costTransferPostedRows(StockTransfer $transfer)
{
    return StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $transfer->id)->orderBy('id')->get();
}

test('costed transfer to a new ordinary store preserves both snapshots quantity value and financial records', function () {
    costTransferMovement($this, $this->product, $this->source, 100, 4000);
    $transfer = costTransferDraft($this, [[$this->product, 20]]);
    $totalBefore = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    $tables = ['purchases', 'purchase_items', 'sales', 'sale_items', 'sale_payments', 'expenses', 'cashbook_sessions', 'supplier_payments', 'customer_payments', 'goods_receiving_notes'];
    $financialBefore = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);

    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $posted = costTransferPostedRows($transfer);
    expect($posted)->toHaveCount(2)
        ->and($posted->pluck('movement_type')->all())->toBe(['transfer_out', 'transfer_in'])
        ->and($posted->pluck('unit_cost')->all())->toBe(['4000.00', '4000.00'])
        ->and($posted->sum(fn ($row) => $row->signedQuantity()))->toEqual(0)
        ->and($posted->sum(fn ($row) => $row->signedQuantity() * (float) $row->unit_cost))->toEqual(0)
        ->and($posted[0]->quantity_out)->toBe('20.0000')
        ->and($posted[1]->quantity_in)->toBe('20.0000');
    $source = collect($this->valuation->stockValuation($this->branch->id, $this->source->id));
    $destination = collect($this->valuation->stockValuation($this->branch->id, $this->destination->id));
    expect($source->sum('quantity'))->toEqual(80)->and($source->sum('value'))->toEqual(320000)
        ->and($destination->sum('quantity'))->toEqual(20)->and($destination->sum('value'))->toEqual(80000)
        ->and($destination->first()['average_cost'])->toEqual(4000)
        ->and(collect($this->valuation->stockValuation($this->branch->id))->sum('value'))->toEqual($totalBefore)
        ->and($transfer->fresh()->status)->toBe('completed');
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($financialBefore[$table]);
    }
    $snapshot = $posted->toJson();
    expect(fn () => $this->inventory->completeStockTransfer($transfer->id, $this->admin->id))->toThrow(ValidationException::class);
    expect(costTransferPostedRows($transfer)->toJson())->toBe($snapshot);
});

test('destination existing cost history includes the transferred source snapshot using canonical averaging', function () {
    costTransferMovement($this, $this->product, $this->source, 100, 4000);
    costTransferMovement($this, $this->product, $this->destination, 10, 5000);
    $before = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    $transfer = costTransferDraft($this, [[$this->product, 20]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $expectedAverage = round((10 * 5000 + 20 * 4000) / 30, 2);
    expect($this->inventory->getAverageCost($this->product->id, $this->destination->id, $this->branch->id))->toBe($expectedAverage)
        ->and(costTransferPostedRows($transfer)->pluck('unit_cost')->all())->toBe(['4000.00', '4000.00']);
    $after = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    expect(abs($after - $before))->toBeLessThanOrEqual(30 * 0.005);
});

test('source cost is captured at completion independently for multiple products', function () {
    $second = $this->product->replicate()->fill(['name' => 'Transfer Cost Nondo', 'sku' => 'TRANSFER-COST-NONDO']);
    $second->save();
    costTransferMovement($this, $this->product, $this->source, 50, 10000);
    costTransferMovement($this, $second, $this->source, 100, 22000);
    $transfer = costTransferDraft($this, [[$this->product, 20], [$second, 2.5]]);
    // A receipt after drafting changes the source average before posting.
    costTransferMovement($this, $this->product, $this->source, 50, 20000);
    $before = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $posted = costTransferPostedRows($transfer);
    expect($posted)->toHaveCount(4)
        ->and($posted->where('product_id', $this->product->id)->pluck('unit_cost')->all())->toBe(['15000.00', '15000.00'])
        ->and($posted->where('product_id', $second->id)->pluck('unit_cost')->all())->toBe(['22000.00', '22000.00'])
        ->and(collect($this->valuation->stockValuation($this->branch->id))->sum('value'))->toEqual($before);
});

test('fractional transfer uses base cost after a converted stock receipt', function () {
    Setting::firstOrFail()->update(['enable_warehouse' => false, 'allow_direct_stock_in' => true]);
    $pack = Unit::create(['name' => 'Transfer pack', 'code' => 'COST-PACK', 'short_name' => 'cpack', 'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active']);
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $pack->id, 'conversion_factor' => 20, 'purchase_price' => 80000,
        'retail_price' => 100000, 'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
    $receipt = $this->inventory->directStockIn([
        'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'stock_location_id' => $this->source->id, 'product_unit_conversion_id' => $conversion->id,
        'quantity' => 1, 'cost_price' => 80000, 'reason' => 'Direct Purchase', 'movement_date' => today()->toDateString(),
    ], $this->admin->id);
    expect((float) $receipt->quantity)->toBe(20.0)->and((float) $receipt->unit_cost)->toBe(4000.0);
    $transfer = costTransferDraft($this, [[$this->product, 2.5]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $posted = costTransferPostedRows($transfer);
    expect($posted->pluck('quantity')->all())->toBe(['2.5000', '2.5000'])
        ->and($posted->pluck('unit_cost')->all())->toBe(['4000.00', '4000.00'])
        ->and($this->inventory->getProductStock($this->product->id, $this->source->id, $this->branch->id))->toEqual(17.5)
        ->and($this->inventory->getProductStock($this->product->id, $this->destination->id, $this->branch->id))->toEqual(2.5)
        ->and(collect($this->valuation->stockValuation($this->branch->id))->sum('value'))->toEqual(80000);
});

test('unresolved source cost rejects the whole transfer without partial posting or price fallback', function () {
    $second = $this->product->replicate()->fill(['sku' => 'TRANSFER-NO-HISTORY', 'buying_price' => 99000]);
    $second->save();
    costTransferMovement($this, $this->product, $this->source, 100, 4000);
    costTransferMovement($this, $second, $this->source, 10, null, 'transfer_in');
    $transfer = costTransferDraft($this, [[$this->product, 20], [$second, 2]]);
    $before = StockMovement::orderBy('id')->get()->toJson();
    try {
        $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
        $this->fail('Missing source cost must reject completion.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['items'][0])->toContain('source stock cost could not be resolved');
    }
    expect($transfer->fresh()->status)->toBe('draft')
        ->and(costTransferPostedRows($transfer))->toHaveCount(0)
        ->and(StockMovement::orderBy('id')->get()->toJson())->toBe($before);
});

test('explicit zero-cost source history remains zero without fabrication', function () {
    costTransferMovement($this, $this->product, $this->source, 100, 0);
    $transfer = costTransferDraft($this, [[$this->product, 20]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    expect(costTransferPostedRows($transfer)->pluck('unit_cost')->all())->toBe(['0.00', '0.00'])
        ->and($this->inventory->getProductStock($this->product->id, $this->destination->id, $this->branch->id))->toEqual(20);
});

test('historical completed transfers and movements are never rewritten', function () {
    costTransferMovement($this, $this->product, $this->source, 100, 4000);
    $historical = costTransferDraft($this, [[$this->product, 5]]);
    foreach ([[$this->source, 'transfer_out'], [$this->destination, 'transfer_in']] as [$location, $type]) {
        costTransferMovement($this, $this->product, $location, 5, null, $type)
            ->update(['reference_type' => StockTransfer::class, 'reference_id' => $historical->id]);
    }
    $historical->update(['status' => 'completed', 'completed_by' => $this->admin->id, 'completed_at' => now()]);
    $historyBefore = costTransferPostedRows($historical)->toJson();
    $headerBefore = $historical->fresh()->toJson();
    $next = costTransferDraft($this, [[$this->product, 20]]);
    $this->inventory->completeStockTransfer($next->id, $this->admin->id);
    expect(fn () => $this->inventory->completeStockTransfer($historical->id, $this->admin->id))->toThrow(ValidationException::class);
    expect(costTransferPostedRows($historical)->toJson())->toBe($historyBefore)
        ->and($historical->fresh()->toJson())->toBe($headerBefore)
        ->and(costTransferPostedRows($next)->pluck('unit_cost')->all())->toBe(['4000.00', '4000.00']);
});

test('depleted destination history retains the existing historical incoming average methodology', function () {
    costTransferMovement($this, $this->product, $this->source, 100, 4000);
    costTransferMovement($this, $this->product, $this->destination, 100, 5000);
    costTransferMovement($this, $this->product, $this->destination, 90, 5000, 'sale_out');
    $before = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    $transfer = costTransferDraft($this, [[$this->product, 20]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $expectedAverage = round((100 * 5000 + 20 * 4000) / 120, 2);
    expect($this->inventory->getAverageCost($this->product->id, $this->destination->id, $this->branch->id))->toBe($expectedAverage)
        ->and(costTransferPostedRows($transfer)->sum(fn ($row) => $row->signedQuantity() * (float) $row->unit_cost))->toEqual(0);
    // This pre-existing averaging rule does not reweight only the 10 remaining units.
    $after = collect($this->valuation->stockValuation($this->branch->id))->sum('value');
    expect($before)->toEqual(450000)->and($after)->toEqual(320000 + 30 * $expectedAverage);
});
