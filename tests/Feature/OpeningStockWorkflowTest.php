<?php

use App\Models\Branch;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\GoodsReceivingNote;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\OpeningStockService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Opening Cement', 'sku' => 'OPEN-CEM', 'barcode' => null, 'buying_price' => 1000]);
    $this->product->save();
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->forceFill(['status' => 'active', 'is_active' => true, 'can_receive_stock' => true])->save();
    Setting::query()->firstOrFail()->update(['enable_warehouse' => true]);
});

function openingPayload(object $test, array $overrides = []): array
{
    return array_replace_recursive([
        'branch_id' => $test->branch->id,
        'stock_location_id' => $test->location->id,
        'opening_date' => '2026-09-23',
        'notes' => 'Owned before HARDEX',
        'lines' => [[
            'product_id' => $test->product->id,
            'product_unit_conversion_id' => null,
            'quantity' => 100,
            'unit_cost' => 15000,
            'batch_number' => null,
            'expiry_date' => null,
            'notes' => null,
        ]],
    ], $overrides);
}

test('opening stock posts multiple costed products to the selected location without purchase or sale records', function () {
    $other = $this->product->replicate();
    $other->fill(['name' => 'Opening Nondo', 'sku' => 'OPEN-NONDO']);
    $other->save();
    $counts = [Purchase::count(), GoodsReceivingNote::count(), Sale::count(), SupplierPayment::count(), CustomerPayment::count(), SalePayment::count(), Expense::count()];
    $payload = openingPayload($this);
    $payload['lines'][] = ['product_id' => $other->id, 'quantity' => 7, 'unit_cost' => 2000];
    $opening = app(OpeningStockService::class)->create($payload, $this->admin);
    $movements = StockMovement::where('reference_type', OpeningStock::class)->where('reference_id', $opening->id)->get();
    expect($opening->reference_number)->toBe('OPEN-20260923-0001')
        ->and($opening->lines)->toHaveCount(2)
        ->and((float) $opening->total_value)->toBe(1514000.0)
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->unique()->all())->toBe(['opening_stock'])
        ->and($movements->pluck('stock_location_id')->unique()->all())->toBe([$this->location->id])
        ->and((float) $movements->firstWhere('product_id', $this->product->id)->unit_cost)->toBe(15000.0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toEqual(100)
        ->and(app(InventoryService::class)->getAverageCost($this->product->id, $this->location->id, $this->branch->id))->toEqual(15000)
        ->and(collect(app(FinancialReportService::class)->stockValuation($this->branch->id, $this->location->id))->sum('value'))->toEqual(1514000)
        ->and([Purchase::count(), GoodsReceivingNote::count(), Sale::count(), SupplierPayment::count(), CustomerPayment::count(), SalePayment::count(), Expense::count()])->toBe($counts);
    $next = app(OpeningStockService::class)->create(openingPayload($this), $this->admin);
    expect($next->reference_number)->toBe('OPEN-20260923-0002');
});

test('opening stock normalizes purchase units and preserves transaction snapshots', function () {
    $box = Unit::where('company_id', $this->admin->company_id)->where('short_name', 'box')->firstOrFail();
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $box->id, 'conversion_factor' => 20, 'purchase_price' => 30000,
        'retail_price' => 40000, 'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
    $opening = app(OpeningStockService::class)->create(openingPayload($this, ['lines' => [[
        'product_id' => $this->product->id, 'product_unit_conversion_id' => $conversion->id,
        'quantity' => 10, 'unit_cost' => 30000,
    ]]]), $this->admin);
    $line = $opening->lines->first();
    $movement = StockMovement::where('reference_id', $opening->id)->where('reference_type', OpeningStock::class)->firstOrFail();
    expect((float) $line->transaction_quantity)->toBe(10.0)
        ->and((float) $line->conversion_factor_snapshot)->toBe(20.0)
        ->and((float) $line->base_quantity)->toBe(200.0)
        ->and((float) $line->unit_cost)->toBe(30000.0)
        ->and((float) $movement->unit_cost)->toBe(1500.0)
        ->and((float) $opening->total_value)->toBe(300000.0);
    $conversion->update(['conversion_factor' => 50]);
    expect((float) $line->fresh()->base_quantity)->toBe(200.0);
});

test('invalid second line rolls back the entire document and unauthorized locations are rejected', function () {
    $service = app(OpeningStockService::class);
    $payload = openingPayload($this);
    $payload['lines'][] = ['product_id' => 999999, 'quantity' => 1, 'unit_cost' => 100];
    expect(fn () => $service->create($payload, $this->admin))->toThrow(ValidationException::class);
    expect(OpeningStock::count())->toBe(0)
        ->and(StockMovement::where('movement_type', 'opening_stock')->count())->toBe(0);
    $this->location->forceFill(['is_active' => false])->save();
    expect(fn () => $service->create(openingPayload($this), $this->admin))->toThrow(ValidationException::class);
    $this->location->forceFill(['is_active' => true])->save();
    $otherBranch = Branch::create(['company_id' => $this->admin->company_id, 'name' => 'Other opening branch', 'code' => 'OPEN-OTHER', 'status' => 'active']);
    $otherLocation = StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $otherBranch->id,
        'name' => 'Other branch store', 'code' => 'OPEN-OTHER-STORE', 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
    ]);
    expect(fn () => $service->create(openingPayload($this, ['stock_location_id' => $otherLocation->id]), $this->admin))->toThrow(ValidationException::class);
    expect(OpeningStock::count())->toBe(0);
});

test('opening stock is read only and warehouse permission gates the pages', function () {
    $opening = app(OpeningStockService::class)->create(openingPayload($this), $this->admin);
    $this->get(route('opening-stock.index'))->assertOk()->assertSee($opening->reference_number);
    $this->get(route('opening-stock.show', $opening))->assertOk()->assertSee('Posted document');
    $this->get(route('opening-stock.create'))->assertOk();
    $this->get(route('stock-movements.index', ['type' => 'opening_stock']))->assertOk()->assertSee('Opening Stock')->assertSee($opening->reference_number);
    expect(fn () => $opening->update(['notes' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $opening->lines->first()->delete())->toThrow(LogicException::class);
    Setting::query()->firstOrFail()->update(['enable_warehouse' => false]);
    $this->get(route('opening-stock.create'))->assertForbidden();
    expect(fn () => app(OpeningStockService::class)->create(openingPayload($this), $this->admin))->toThrow(ValidationException::class);
});

test('a later sale uses opening cost without changing the existing sale costing method', function () {
    $this->location->forceFill(['can_sell' => true, 'is_sellable' => true, 'can_issue_stock' => true])->save();
    app(OpeningStockService::class)->create(openingPayload($this), $this->admin);
    $sale = app(InventoryService::class)->completeSale([[
        'product_id' => $this->product->id, 'stock_location_id' => $this->location->id,
        'sale_type' => 'retail', 'quantity' => 1, 'unit_price' => 18000,
        'discount_amount' => 0, 'tax_amount' => 0,
    ]], [['payment_method' => 'cash', 'amount' => 18000]], null, $this->location->id, $this->branch->id, $this->admin->id);
    expect((float) $sale->items->first()->base_unit_cost)->toBe(15000.0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toEqual(99);
});

test('batch and expiry requirements apply and posted movements cannot be changed', function () {
    $this->product->forceFill(['tracks_batch' => true, 'tracks_expiry' => true])->save();
    $service = app(OpeningStockService::class);
    expect(fn () => $service->create(openingPayload($this), $this->admin))->toThrow(ValidationException::class);
    $opening = $service->create(openingPayload($this, ['lines' => [[
        'product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 15000,
        'batch_number' => 'OLD-LOT', 'expiry_date' => '2027-09-23',
    ]]]), $this->admin);
    $movement = StockMovement::where('reference_type', OpeningStock::class)->where('reference_id', $opening->id)->firstOrFail();
    expect($opening->lines->first()->batch_number)->toBe('OLD-LOT')
        ->and($opening->lines->first()->expiry_date->toDateString())->toBe('2027-09-23')
        ->and(fn () => $movement->update(['unit_cost' => 1]))->toThrow(LogicException::class);
});

test('assigned-location users cannot post into an unassigned receiving location', function () {
    $otherLocation = StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Restricted opening store', 'code' => 'OPEN-RESTRICTED', 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
    ]);
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole('Store Keeper');
    $user->stockLocations()->attach($this->location->id, [
        'company_id' => $user->company_id, 'branch_id' => $user->branch_id,
        'can_view' => true, 'can_receive' => true,
    ]);
    expect(fn () => app(OpeningStockService::class)->create(openingPayload($this, ['stock_location_id' => $otherLocation->id]), $user))->toThrow(ValidationException::class);
    expect(OpeningStock::count())->toBe(0);
});

test('create page shows Kiswahili guidance and preserves a manually edited opening cost', function () {
    $other = $this->product->replicate();
    $other->fill(['name' => 'Opening Nondo', 'sku' => 'OPEN-UI-NONDO', 'buying_price' => 2000]);
    $other->save();

    $component = Volt::test('opening-stock.create')
        ->assertSee('Bidhaa Ulizokuwa Nazo Tayari')
        ->assertSee('Hii haitengenezi Purchase, GRN wala deni la Supplier.')
        ->assertSee('Chagua bidhaa kwanza');
    expect(preg_match('/<select[^>]*wire:model.live="lines\\.0\\.product_unit_conversion_id"[^>]*disabled/', $component->html()))->toBe(1);

    $component->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('lines.0.product_unit_conversion_id', '')
        ->assertSet('lines.0.unit_cost', (string) $this->product->buying_price)
        ->assertSee($this->product->unit->name)
        ->call('selectProduct', 0, (string) $other->id)
        ->assertSet('lines.0.unit_cost', (string) $other->buying_price)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('lines.0.unit_cost', (string) $this->product->buying_price)
        ->set('lines.0.quantity', '2')
        ->set('lines.0.unit_cost', '12345')
        ->assertSet('lines.0.cost_edited', true)
        ->assertSee('24,690')
        ->call('selectProduct', 0, (string) $other->id)
        ->assertSet('lines.0.unit_cost', '12345')
        ->call('addLine')
        ->assertCount('lines', 2)
        ->call('selectProduct', 1, (string) $other->id)
        ->assertSet('lines.1.unit_cost', (string) $other->buying_price);
    expect(preg_match('/<select[^>]*wire:model.live="lines\.0\.product_unit_conversion_id"[^>]*disabled/', $component->html()))->toBe(0);
});
