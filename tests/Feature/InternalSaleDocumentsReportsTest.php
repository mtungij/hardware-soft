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
use App\Services\InternalSaleReportService;
use App\Services\InternalSaleService;
use App\Services\InventoryService;
use App\Services\LocationPriceService;
use App\Services\ReportExportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill([
        'name' => 'Mombasa Report Cement', 'sku' => 'REPORT-CEMENT-'.str()->random(5),
        'barcode' => null, 'buying_price' => 1500, 'selling_price' => 3000,
    ]);
    $this->product->save();
    $this->source = reportLocation($this->admin, $this->branch, 'Mombasa Godown');
    $this->destination = reportLocation($this->admin, $this->branch, 'Dispensing Area');
    StockMovement::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->source->id,
        'movement_type' => 'purchase_receipt', 'quantity' => 100, 'unit_cost' => 1500,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
    app(LocationPriceService::class)->savePrices($this->product, $this->source, null, ['internal_sale_price' => 2500]);
});

function reportLocation(User $user, Branch $branch, string $name): StockLocation
{
    return StockLocation::create([
        'company_id' => $user->company_id, 'branch_id' => $branch->id,
        'name' => $name, 'code' => 'REP-'.str()->random(8), 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
        'can_issue_stock' => true, 'can_transfer' => true, 'can_sell' => true, 'is_sellable' => true,
    ]);
}

function reportSale(object $test, bool $complete = true, ?ProductUnitConversion $conversion = null, float $quantity = 10, float $price = 2500): InternalSale
{
    $sale = app(InternalSaleService::class)->saveDraft([
        'branch_id' => $test->branch->id,
        'from_location_id' => $test->source->id,
        'to_location_id' => $test->destination->id,
        'internal_sale_number' => 'REPORT-'.str()->random(8),
        'sale_date' => today()->toDateString(),
        'notes' => 'Stock checked on dispatch',
        'items' => [[
            'product_id' => $test->product->id,
            'product_unit_conversion_id' => $conversion?->id,
            'quantity' => $quantity,
            'internal_unit_price' => $price,
        ]],
    ], $test->admin);
    if ($complete) {
        app(InternalSaleService::class)->complete($sale, $test->admin);
    }

    return $sale->refresh();
}

test('completed delivery note is quantity-only and value note uses saved internal values', function () {
    $sale = reportSale($this);
    $delivery = $this->get(route('internal-sales.delivery-note.print', $sale))->assertOk();
    $delivery->assertSee('INTERNAL DELIVERY NOTE')
        ->assertSee($sale->internal_sale_number)
        ->assertSee($this->source->name)
        ->assertSee($this->destination->name)
        ->assertSee($this->product->sku)
        ->assertSee('10')
        ->assertSee('PREPARED BY')
        ->assertSee('DISPATCHED BY')
        ->assertSee('RECEIVED BY')
        ->assertDontSee('25,000')
        ->assertDontSee('15,000')
        ->assertDontSee('Internal Gross Profit')
        ->assertDontSee('Company Cost');
    $value = $this->get(route('internal-sales.value-note.print', $sale))->assertOk();
    $value->assertSee('INTERNAL SALE VALUE NOTE')->assertSee('2,500')->assertSee('25,000')
        ->assertDontSee('15,000')->assertDontSee('Internal Gross Profit');
    $this->get(route('internal-sales.delivery-note.pdf', $sale))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('internal-sales.value-note.pdf', $sale))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('source margin and destination acquisition use saved costs without creating external revenue', function () {
    $sale = reportSale($this);
    $report = app(InternalSaleReportService::class);
    $out = $report->rows($this->admin, 'outgoing');
    $in = $report->rows($this->admin, 'incoming');
    expect($out)->toHaveCount(1)
        ->and($out[0]['internal_value'])->toBe(25000.0)
        ->and($out[0]['source_cost'])->toBe(15000.0)
        ->and($out[0]['internal_margin'])->toBe(10000.0)
        ->and($report->productSummary($out)[0]['margin_percent'])->toBe(40.0)
        ->and($in[0]['acquisition_unit_cost'])->toBe(2500.0)
        ->and($in[0]['acquisition_value'])->toBe(25000.0)
        ->and($report->totals($out)['sales'])->toBe(1)
        ->and(Sale::count())->toBe(0);
    $this->get(route('reports.internal-sales'))->assertOk()->assertSee($sale->internal_sale_number)
        ->assertSee('25,000')->assertSee('10,000');
    $this->get(route('internal-sales.show', $sale))->assertOk()
        ->assertSee('Internal Location Margin')->assertSee('10,000');
});

test('destination outlet margin and company consolidated profit remain distinct', function () {
    reportSale($this);
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true, 'inventory_mode' => 'multi_location',
        'allow_sales_from_store' => true, 'default_stock_location_id' => $this->destination->id,
    ]);
    app(InventoryService::class)->completeSale(
        [[
            'product_id' => $this->product->id, 'stock_location_id' => $this->destination->id,
            'sale_type' => 'retail', 'quantity' => 1, 'unit_price' => 3000,
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 3000, 'reference_number' => 'OUTLET-MARGIN']],
        null, $this->destination->id, $this->branch->id, $this->admin->id,
    );
    $financial = app(FinancialReportService::class);
    $rows = collect($financial->locationMargins($this->branch->id, today()->toDateString(), today()->toDateString()))->keyBy('location_id');
    $profit = $financial->profitLoss($this->branch->id, today()->toDateString(), today()->toDateString());
    expect($rows[$this->source->id]['source_internal_margin'])->toEqual(10000)
        ->and($rows[$this->destination->id]['outlet_margin'])->toEqual(500)
        ->and($profit['revenue'])->toEqual(3000)
        ->and($profit['cogs'])->toEqual(1500)
        ->and($profit['gross_profit'])->toEqual(1500);
    $this->get(route('reports.location-margins'))->assertOk()->assertSee('Consolidated');
});

test('historical reports and value note do not recalculate after location pricing changes', function () {
    $sale = reportSale($this);
    app(LocationPriceService::class)->savePrices($this->product, $this->source, null, ['internal_sale_price' => 2900]);
    $this->product->update(['selling_price' => 3500, 'buying_price' => 1800]);
    $row = app(InternalSaleReportService::class)->rows($this->admin, 'outgoing')->first();
    expect($row['internal_unit_price'])->toBe(2500.0)
        ->and($row['internal_value'])->toBe(25000.0)
        ->and($row['source_cost'])->toBe(15000.0)
        ->and($row['internal_margin'])->toBe(10000.0);
    $this->get(route('internal-sales.value-note.print', $sale))->assertOk()->assertSee('25,000')->assertDontSee('29,000');
});

test('draft and cancelled transactions are excluded from completed report totals and documents', function () {
    $draft = reportSale($this, false);
    $cancelled = reportSale($this, false);
    app(InternalSaleService::class)->cancelDraft($cancelled, $this->admin);
    $report = app(InternalSaleReportService::class);
    expect($report->rows($this->admin, 'outgoing'))->toHaveCount(0)
        ->and($report->totals($report->rows($this->admin, 'outgoing', ['status' => 'all']))['internal_value'])->toBe(0);
    $this->get(route('internal-sales.delivery-note.print', $draft))->assertStatus(409);
    $this->get(route('internal-sales.delivery-note.print', $cancelled))->assertStatus(409);
});

test('source-only and destination-only users see their side without posting or margin leakage', function () {
    $sale = reportSale($this);
    $sourceUser = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $sourceRole = Role::create(['name' => 'Source report '.str()->random(5), 'guard_name' => 'web', 'stock_scope' => 'assigned_locations', 'report_scope' => 'branch']);
    $sourceRole->givePermissionTo(['internal_sales.view', 'internal_sales.print_delivery_note', 'reports.internal_sales', 'reports.export']);
    $sourceUser->assignRole($sourceRole);
    $sourceUser->stockLocations()->attach($this->source->id, ['company_id' => $sourceUser->company_id, 'branch_id' => $sourceUser->branch_id, 'can_view' => true]);
    $destinationUser = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $destinationRole = Role::create(['name' => 'Destination report '.str()->random(5), 'guard_name' => 'web', 'stock_scope' => 'assigned_locations', 'report_scope' => 'branch']);
    $destinationRole->givePermissionTo(['internal_sales.view', 'internal_sales.print_delivery_note', 'reports.internal_acquisitions']);
    $destinationUser->assignRole($destinationRole);
    $destinationUser->stockLocations()->attach($this->destination->id, ['company_id' => $destinationUser->company_id, 'branch_id' => $destinationUser->branch_id, 'can_view' => true]);

    $this->actingAs($sourceUser);
    expect(app(InternalSaleReportService::class)->rows($sourceUser, 'outgoing'))->toHaveCount(1);
    $this->get(route('internal-sales.index'))->assertOk()->assertSee($sale->internal_sale_number);
    $this->get(route('internal-sales.delivery-note.print', $sale))->assertOk();
    $this->get(route('internal-sales.value-note.print', $sale))->assertForbidden();
    $this->get(route('reports.internal-sales'))->assertOk()
        ->assertDontSee('Total Internal Cost')->assertDontSee('Total Internal Profit');
    $exportRequest = Request::create('/exports/reports.internal-sales/excel', 'GET', ['tab' => 'outgoing']);
    $exportRequest->setUserResolver(fn () => $sourceUser);
    $export = app(ReportExportService::class)->build('reports.internal-sales', $exportRequest);
    expect($export['headers'])->not->toContain('Internal Cost', 'Internal Profit');

    $this->actingAs($destinationUser);
    expect(app(InternalSaleReportService::class)->rows($destinationUser, 'incoming'))->toHaveCount(1);
    $this->get(route('internal-sales.index'))->assertOk()->assertSee($sale->internal_sale_number);
    $this->get(route('internal-sales.delivery-note.print', $sale))->assertOk();
    $this->get(route('reports.internal-sales'))->assertOk()->assertSee('Incoming Internal Acquisitions');
    $this->get(route('internal-sales.show', $sale))->assertOk()->assertDontSee('Source Location Performance');
});

test('another company cannot read documents or internal report rows', function () {
    $sale = reportSale($this);
    $company = Company::create([
        'company_name' => 'Other Internal Reporting Company', 'business_type' => 'Hardware Store',
        'phone' => '+255 700 888 888', 'whatsapp_number' => '+255 700 888 888',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Other Branch', 'code' => 'OTHER']);
    $user = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $user->assignRole('Admin');
    $this->actingAs($user);
    $this->get(route('internal-sales.delivery-note.print', $sale))->assertNotFound();
    $this->get(route('internal-sales.value-note.print', $sale))->assertNotFound();
    expect(app(InternalSaleReportService::class)->rows($user, 'outgoing'))->toHaveCount(0);
});

test('unit conversion snapshots remain visible in delivery documents and reports', function () {
    $unit = Unit::create([
        'company_id' => $this->admin->company_id, 'name' => 'Report Box',
        'short_name' => 'rbox', 'measurement_type_id' => $this->product->measurement_type_id,
        'status' => 'active',
    ]);
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'unit_id' => $unit->id, 'conversion_factor' => 12, 'retail_price' => 36000,
        'can_purchase' => true, 'can_sell' => true, 'active' => true,
    ]);
    app(LocationPriceService::class)->savePrices($this->product, $this->source, $conversion, ['internal_sale_price' => 30000]);
    $sale = reportSale($this, true, $conversion, 1, 30000);
    $this->get(route('internal-sales.delivery-note.print', $sale))->assertOk()
        ->assertSee('rbox')->assertSee('Report Box')->assertDontSee('30,000');
    $row = app(InternalSaleReportService::class)->rows($this->admin, 'outgoing')->first();
    expect($row['transaction_unit'])->toBe('rbox')
        ->and($row['transaction_quantity'])->toBe(1.0)
        ->and($row['base_quantity'])->toBe(12.0)
        ->and($row['internal_value'])->toBe(30000.0);
});

test('outgoing and incoming report tabs show product-level prices and transaction links', function () {
    $sale = reportSale($this);
    $url = route('internal-sales.show', $sale);

    Volt::test('reports.internal-sales')
        ->assertSee($sale->internal_sale_number)
        ->assertSee($url)
        ->assertSee($this->product->sku)
        ->assertSee('Destination Location')
        ->assertSee('Internal Unit Price')
        ->assertSee('Total Internal Sales')
        ->assertSee('Total Internal Cost')
        ->assertSee('Total Internal Profit')
        ->assertSee('25,000')
        ->assertSee('15,000')
        ->assertSee('10,000')
        ->set('tab', 'incoming')
        ->assertSee('Source Location')
        ->assertSee('Acquisition Unit Price')
        ->assertSee('Stock bought internally')
        ->assertSee('Source Locations')
        ->assertSee($url)
        ->assertSee('2,500');
});

test('location cards show store and dispensing product details without a wide zero table', function () {
    $this->destination->update(['type' => 'dispensing']);
    reportSale($this);
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true, 'inventory_mode' => 'multi_location',
        'allow_sales_from_store' => true, 'default_stock_location_id' => $this->destination->id,
    ]);
    app(InventoryService::class)->completeSale(
        [[
            'product_id' => $this->product->id, 'stock_location_id' => $this->destination->id,
            'sale_type' => 'retail', 'quantity' => 1, 'unit_price' => 3000,
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 3000, 'reference_number' => 'UI-OUTLET']],
        null, $this->destination->id, $this->branch->id, $this->admin->id,
    );

    $html = Volt::test('reports.internal-sales')->set('tab', 'location')->html();
    expect($html)->toContain('Mombasa Godown', 'Dispensing Area', 'Internal Profit',
        'Stock Bought Internally', 'Customer Sales', 'Cost of Sold Stock', 'Outlet Profit',
        'Products sold internally', 'Products bought internally', 'Products sold to customers',
        'Consolidated Gross Profit', '10,000', '500');
    expect($html)->not->toContain('Source Internal Margin', 'Destination/Outlet COGS');
});

test('report exports follow tab filters and hide costs without their permissions', function () {
    $sale = reportSale($this);
    $exports = app(ReportExportService::class);
    $request = Request::create('/exports/reports.internal-sales/excel', 'GET', [
        'tab' => 'outgoing', 'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
    ]);
    $request->setUserResolver(fn () => $this->admin);
    $payload = $exports->build('reports.internal-sales', $request);
    expect($payload['headers'])->toContain('Internal Cost', 'Internal Profit')
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0])->toContain($sale->internal_sale_number, 25000.0, 15000.0, 10000.0);

    $request->query->set('tab', 'incoming');
    $incoming = $exports->build('reports.internal-sales', $request);
    expect($incoming['headers'])->toContain('Acquisition Price', 'Acquisition Value')
        ->and($incoming['rows'][0])->toContain($this->source->name, 2500.0, 25000.0);

    $request->query->set('tab', 'location');
    $location = $exports->build('reports.internal-sales', $request);
    expect($location['headers'])->toContain('Internal Profit', 'Stock Bought Internally')
        ->and(collect($location['rows'])->pluck(0))->toContain($this->source->name, $this->destination->name);

    $this->get(route('exports.download', ['export' => 'reports.internal-sales', 'format' => 'pdf', 'tab' => 'outgoing']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('exports.download', ['export' => 'reports.internal-sales', 'format' => 'excel', 'tab' => 'incoming']))
        ->assertOk()->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
});

test('location-only report access remains scoped to assigned stock locations', function () {
    reportSale($this);
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $role = Role::create(['name' => 'Location card '.str()->random(5), 'guard_name' => 'web',
        'stock_scope' => 'assigned_locations', 'report_scope' => 'branch']);
    $role->givePermissionTo(['reports.location_margins', 'stock.view_value', 'internal_sales.view_margin']);
    $user->assignRole($role);
    $user->stockLocations()->attach($this->destination->id, [
        'company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'can_view' => true,
    ]);
    $this->actingAs($user);

    Volt::test('reports.internal-sales')
        ->assertDontSee('Outgoing Internal Sales')
        ->assertDontSee('Incoming Internal Acquisitions')
        ->assertSee($this->destination->name)
        ->assertDontSee('Internal Sales from this location');
    expect(app(InternalSaleReportService::class)->locationRows($user, 'incoming'))->toHaveCount(1)
        ->and(app(InternalSaleReportService::class)->locationRows($user, 'outgoing'))->toHaveCount(0);
});
