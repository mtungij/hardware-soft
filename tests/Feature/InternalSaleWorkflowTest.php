<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\InternalSale;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InternalSaleService;
use App\Services\InventoryService;
use App\Services\LocationPriceService;
use App\Services\StockLedgerReadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Internal Sale Test Cement', 'sku' => 'INTERNAL-SALE-CEMENT', 'barcode' => null]);
    $this->product->save();
    $this->source = internalSaleLocation($this->admin, $this->branch, 'Source');
    $this->destination = internalSaleLocation($this->admin, $this->branch, 'Destination');
    StockMovement::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->source->id,
        'movement_type' => 'purchase_receipt', 'quantity' => 100, 'unit_cost' => 4000,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
    app(LocationPriceService::class)->savePrices($this->product, $this->source, null, ['internal_sale_price' => 5000]);
});

function internalSaleLocation(User $user, Branch $branch, string $name): StockLocation
{
    return StockLocation::create([
        'company_id' => $user->company_id, 'branch_id' => $branch->id,
        'name' => $name.' '.str()->random(5), 'code' => 'IS-'.str()->random(8),
        'type' => 'store', 'status' => 'active', 'is_active' => true,
        'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true,
        'can_sell' => true, 'is_sellable' => true,
    ]);
}

function internalSaleData(object $test, array $overrides = []): array
{
    return array_replace_recursive([
        'branch_id' => $test->branch->id, 'from_location_id' => $test->source->id,
        'to_location_id' => $test->destination->id, 'internal_sale_number' => 'IS-'.str()->random(10),
        'sale_date' => today()->toDateString(), 'notes' => null,
        'items' => [['product_id' => $test->product->id, 'product_unit_conversion_id' => null, 'quantity' => 20, 'internal_unit_price' => 5000]],
    ], $overrides);
}

test('posting preserves company cost, records internal acquisition and never creates external revenue', function () {
    $inventory = app(InventoryService::class);
    $reports = app(FinancialReportService::class);
    $service = app(InternalSaleService::class);
    $beforeValue = collect($reports->stockValuation($this->branch->id))->sum('value');
    $beforeProfit = $reports->profitLoss($this->branch->id, today()->toDateString(), today()->toDateString());
    $beforeSales = Sale::count();
    $sale = $service->saveDraft(internalSaleData($this), $this->admin);

    expect($sale->status)->toBe('draft')
        ->and(StockMovement::where('reference_type', InternalSale::class)->count())->toBe(0);
    $service->complete($sale, $this->admin);
    $rows = StockMovement::where('reference_type', InternalSale::class)->where('reference_id', $sale->id)->orderBy('id')->get();
    $ledger = app(StockLedgerReadService::class)->ledger($this->product, $this->destination, $this->admin);
    expect($ledger['history']->last()['reference'])->toContain($sale->internal_sale_number);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('movement_type')->all())->toBe(['internal_sale_out', 'internal_sale_in'])
        ->and($rows->pluck('unit_cost')->all())->toBe(['4000.000000', '4000.000000'])
        ->and($rows->pluck('location_acquisition_unit_cost')->all())->toBe(['4000.000000', '5000.000000'])
        ->and($rows->sum(fn ($row) => $row->signedQuantity()))->toEqual(0)
        ->and($inventory->getProductStock($this->product->id, $this->source->id, $this->branch->id))->toEqual(80)
        ->and($inventory->getProductStock($this->product->id, $this->destination->id, $this->branch->id))->toEqual(20)
        ->and($inventory->getAverageCost($this->product->id, $this->destination->id, $this->branch->id))->toEqual(4000)
        ->and($inventory->getLocationAcquisitionCost($this->product->id, $this->destination->id, $this->branch->id))->toEqual(5000)
        ->and(collect($reports->stockValuation($this->branch->id))->sum('value'))->toEqual($beforeValue)
        ->and(Sale::count())->toBe($beforeSales)
        ->and($reports->profitLoss($this->branch->id, today()->toDateString(), today()->toDateString()))->toBe($beforeProfit);
    $margins = collect($reports->locationMargins($this->branch->id, today()->toDateString(), today()->toDateString()))->keyBy('location_id');
    expect($margins[$this->source->id]['source_internal_margin'])->toEqual(20000)
        ->and($margins[$this->destination->id]['internal_purchases_in'])->toEqual(100000)
        ->and($margins[$this->destination->id]['company_stock_value'])->toEqual(80000)
        ->and($margins[$this->destination->id]['location_commercial_value'])->toEqual(100000);
    $service->complete($sale, $this->admin);
    expect(StockMovement::where('reference_type', InternalSale::class)->where('reference_id', $sale->id)->count())->toBe(2)
        ->and($sale->fresh()->status)->toBe('completed');
    expect(fn () => $service->cancelDraft($sale, $this->admin))->toThrow(ValidationException::class);
});

test('draft cancellation and failed posting are atomic', function () {
    $service = app(InternalSaleService::class);
    $sale = $service->saveDraft(internalSaleData($this), $this->admin);
    $service->cancelDraft($sale, $this->admin);
    $service->cancelDraft($sale, $this->admin);
    expect($sale->fresh()->status)->toBe('cancelled');
    expect(fn () => $service->complete($sale, $this->admin))->toThrow(ValidationException::class);
    $tooMuch = $service->saveDraft(internalSaleData($this, ['items' => [['product_id' => $this->product->id, 'quantity' => 101, 'internal_unit_price' => 5000]]]), $this->admin);
    expect(fn () => $service->complete($tooMuch, $this->admin))->toThrow(ValidationException::class);
    expect($tooMuch->fresh()->status)->toBe('draft')
        ->and(StockMovement::where('reference_type', InternalSale::class)->count())->toBe(0);
});

test('unit conversion posts base quantities and explicit price overrides require permission', function () {
    $unit = Unit::create([
        'company_id' => $this->admin->company_id, 'name' => 'Internal pack', 'short_name' => 'ipack',
        'measurement_type_id' => $this->product->measurement_type_id, 'status' => 'active',
    ]);
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $unit->id, 'conversion_factor' => 10, 'can_purchase' => true,
        'can_sell' => true, 'active' => true,
    ]);
    app(LocationPriceService::class)->savePrices($this->product, $this->source, $conversion, ['internal_sale_price' => 50000]);
    $data = internalSaleData($this, ['items' => [['product_id' => $this->product->id, 'product_unit_conversion_id' => $conversion->id, 'quantity' => 2, 'internal_unit_price' => 50000]]]);
    $sale = app(InternalSaleService::class)->saveDraft($data, $this->admin);
    app(InternalSaleService::class)->complete($sale, $this->admin);
    expect((float) $sale->items()->first()->base_quantity)->toBe(20.0)
        ->and((float) $sale->items()->first()->destination_acquisition_base_unit_cost)->toBe(5000.0);
    $restricted = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $role = Role::create(['name' => 'Internal no override '.str()->random(6), 'guard_name' => 'web', 'stock_scope' => 'assigned_locations']);
    $role->givePermissionTo(['internal_sales.create']);
    $restricted->assignRole($role);
    $restricted->stockLocations()->attach($this->source->id, ['company_id' => $restricted->company_id, 'branch_id' => $restricted->branch_id, 'can_transfer' => true]);
    $restricted->stockLocations()->attach($this->destination->id, ['company_id' => $restricted->company_id, 'branch_id' => $restricted->branch_id, 'can_receive' => true]);
    $data['internal_sale_number'] = 'IS-'.str()->random(10);
    $data['items'][0]['internal_unit_price'] = 55000;
    expect(fn () => app(InternalSaleService::class)->saveDraft($data, $restricted))->toThrow(ValidationException::class);
});

test('pages render and one-side visibility does not grant posting authority', function () {
    $sale = app(InternalSaleService::class)->saveDraft(internalSaleData($this), $this->admin);
    $statuses = [
        'index' => $this->get(route('internal-sales.index'))->status(),
        'create' => $this->get(route('internal-sales.create'))->status(),
        'show' => $this->get(route('internal-sales.show', $sale))->status(),
        'margins' => $this->get(route('reports.location-margins'))->status(),
    ];
    expect($statuses)->toBe(['index' => 200, 'create' => 200, 'show' => 200, 'margins' => 200]);
    $other = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $role = Role::create(['name' => 'Internal assigned '.str()->random(6), 'guard_name' => 'web', 'stock_scope' => 'assigned_locations']);
    $role->givePermissionTo(['internal_sales.view', 'internal_sales.create', 'internal_sales.complete']);
    $other->assignRole($role);
    $other->stockLocations()->attach($this->source->id, ['company_id' => $other->company_id, 'branch_id' => $other->branch_id, 'can_view' => true, 'can_transfer' => true]);
    $this->actingAs($other);
    $this->get(route('internal-sales.show', $sale))->assertOk();
    $this->get(route('internal-sales.index'))->assertOk()->assertSee($sale->internal_sale_number);
    expect(fn () => app(InternalSaleService::class)->complete($sale, $other))->toThrow(ValidationException::class);
});

test('destination external sale uses internal acquisition margin but consolidated profit uses company cost', function () {
    $service = app(InternalSaleService::class);
    $sale = $service->saveDraft(internalSaleData($this), $this->admin);
    $service->complete($sale, $this->admin);
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true, 'inventory_mode' => 'multi_location',
        'allow_sales_from_store' => true, 'default_stock_location_id' => $this->destination->id,
    ]);
    $this->product->update(['buying_price' => 4000, 'selling_price' => 7000]);
    $customerSale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $this->product->id, 'stock_location_id' => $this->destination->id,
            'sale_type' => 'retail', 'quantity' => 1, 'unit_price' => 7000,
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 7000, 'reference_number' => 'INTERNAL-DEST']],
        null, $this->destination->id, $this->branch->id, $this->admin->id,
    );
    $line = $customerSale->items->first();
    expect((float) $line->base_unit_cost)->toBe(4000.0)
        ->and((float) $line->location_base_unit_cost)->toBe(5000.0);
    $reports = app(FinancialReportService::class);
    $profit = $reports->profitLoss($this->branch->id, today()->toDateString(), today()->toDateString());
    expect($profit['revenue'])->toEqual(7000)
        ->and($profit['cogs'])->toEqual(4000)
        ->and($profit['gross_profit'])->toEqual(3000);
    $margin = collect($reports->locationMargins($this->branch->id, today()->toDateString(), today()->toDateString()))->firstWhere('location_id', $this->destination->id);
    expect($margin['external_revenue'])->toEqual(7000)
        ->and($margin['location_cogs'])->toEqual(5000)
        ->and($margin['outlet_margin'])->toEqual(2000);
});

test('another company cannot read or post an Internal Sale', function () {
    $sale = app(InternalSaleService::class)->saveDraft(internalSaleData($this), $this->admin);
    $company = Company::create([
        'company_name' => 'Foreign Internal Sale Company', 'business_type' => 'Hardware Store',
        'phone' => '+255 700 909 909', 'whatsapp_number' => '+255 700 909 909',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Foreign Main', 'code' => 'FMAIN']);
    $other = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $other->assignRole('Admin');
    $this->actingAs($other);
    $this->get(route('internal-sales.show', $sale))->assertStatus(404);
    expect(fn () => app(InternalSaleService::class)->complete($sale, $other))
        ->toThrow(ModelNotFoundException::class);
    expect(StockMovement::withoutGlobalScopes()->where('reference_type', InternalSale::class)->count())->toBe(0);
});
