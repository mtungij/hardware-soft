<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use App\Support\AuthorizationScope;
use App\Support\NumberFormatter;
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
    $this->product->fill(['name' => 'Valuation Test Product', 'sku' => 'VALUATION-TEST', 'barcode' => null, 'buying_price' => 4000, 'selling_price' => 5000]);
    $this->product->save();
    $this->valuation = app(FinancialReportService::class);
    $this->inventory = app(InventoryService::class);
});

function valuationLocation(User $user, string $name, ?int $branchId, array $attributes = []): StockLocation
{
    return StockLocation::create(array_merge([
        'company_id' => $user->company_id, 'branch_id' => $branchId,
        'name' => $name, 'code' => 'VAL-'.str()->random(8), 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'is_warehouse' => false,
        'is_dispensing_location' => false, 'can_receive_stock' => true,
        'can_sell' => true, 'is_sellable' => true, 'can_issue_stock' => true, 'can_transfer' => true,
    ], $attributes));
}

function valuationMovement(User $user, Product $product, StockLocation $location, float $quantity, string $type = 'adjustment_in', ?int $branchId = null): StockMovement
{
    return StockMovement::create([
        'company_id' => $user->company_id, 'branch_id' => $branchId ?? $location->branch_id ?? $user->branch_id,
        'product_id' => $product->id, 'stock_location_id' => $location->id,
        'movement_type' => $type, 'quantity' => $quantity, 'unit_cost' => 4000,
        'created_by' => $user->id, 'movement_date' => today(),
    ]);
}

test('every stocked location uses canonical valuation and ID click through including new ordinary stores', function () {
    $locations = collect([
        valuationLocation($this->admin, 'Main Store Valuation', $this->branch->id, ['type' => 'store', 'is_warehouse' => true]),
        valuationLocation($this->admin, 'Dispensing Area Valuation', $this->branch->id, ['type' => 'dispensing', 'is_dispensing_location' => true]),
        valuationLocation($this->admin, 'Zanzibar store', $this->branch->id),
        valuationLocation($this->admin, 'Warehouse 2 Valuation', $this->branch->id, ['type' => 'other']),
    ]);
    expect($locations[0]->type)->toBe('store')->and($locations[2]->type)->toBe('store');
    foreach ($locations as $index => $location) {
        valuationMovement($this->admin, $this->product, $location, 120 + $index);
    }
    $component = Volt::test('dashboard')->set('branchFilter', (string) $this->branch->id);
    $summaries = $component->get('stockLocationValues')->keyBy('stock_location_id');
    foreach ($locations as $location) {
        $canonical = $this->inventory->getProductStock($this->product->id, $location->id, $this->branch->id)
            * $this->inventory->getAverageCost($this->product->id, $location->id, $this->branch->id);
        $rows = $this->valuation->stockValuation($this->branch->id, $location->id);
        expect($summaries[$location->id]['value'])->toEqual($canonical)
            ->and(collect($rows)->sum('value'))->toEqual($canonical)
            ->and(collect($rows)->pluck('stock_location_id')->unique()->all())->toBe([$location->id]);
        $url = route('reports.stock-valuation', ['stock_location_id' => $location->id, 'branch_id' => $this->branch->id]);
        $component->assertSee($url)->assertSee($location->name);
        $this->get($url)->assertOk()->assertSee('TZS '.NumberFormatter::money($canonical));
    }
    expect($summaries->sum('value'))->toEqual(collect($this->valuation->stockValuation($this->branch->id))->sum('value'));
    $component->assertDontSee('search=Main')->assertDontSee('search=Dispensing');

    $new = valuationLocation($this->admin, 'New location after dashboard creation', $this->branch->id);
    valuationMovement($this->admin, $this->product, $new, 3);
    $component->call('$refresh')->assertSee($new->name);
    expect($component->get('stockLocationValues')->pluck('stock_location_id'))->toContain($new->id);
});

test('480000 store-type total cannot be mistaken for the Main Store name filter', function () {
    $location = valuationLocation($this->admin, 'Zanzibar store', $this->branch->id);
    valuationMovement($this->admin, $this->product, $location, 120);
    $role = Role::create(['name' => 'Valuation assigned', 'guard_name' => 'web', 'stock_scope' => 'assigned_locations', 'report_scope' => 'branch']);
    $role->givePermissionTo(['dashboard.view', 'dashboard.stock_value', 'reports.stock', 'stock.view_value']);
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $user->assignRole($role);
    $user->stockLocations()->attach($location->id, ['company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'can_view' => true]);
    $this->actingAs($user);
    $rows = collect($this->valuation->stockValuation());
    expect($rows->sum('value'))->toEqual(480000)
        ->and($rows->filter(fn ($row) => str_contains($row['location'], 'Main Store'))->sum('value'))->toEqual(0);
    $this->get(route('reports.stock-valuation', ['stock_location_id' => $location->id]))->assertOk()->assertSee('TZS 480,000');
    Volt::test('dashboard')->assertSee('Zanzibar store')->assertSee('TZS 480,000')->assertDontSee('Main Store Stock Value');
});

test('location and branch filters reject unauthorized locations and respect shared locations', function () {
    $otherBranch = Branch::create(['company_id' => $this->admin->company_id, 'name' => 'Other valuation branch', 'code' => 'VAL-OTHER', 'status' => 'active']);
    $local = valuationLocation($this->admin, 'Local valuation', $this->branch->id);
    $other = valuationLocation($this->admin, 'Other branch valuation', $otherBranch->id);
    $shared = valuationLocation($this->admin, 'Shared valuation', null);
    $inactive = valuationLocation($this->admin, 'Inactive valuation', $this->branch->id, ['is_active' => false]);
    foreach ([$local, $other, $inactive] as $location) {
        valuationMovement($this->admin, $this->product, $location, 10);
    }
    valuationMovement($this->admin, $this->product, $shared, 3, branchId: $this->branch->id);
    valuationMovement($this->admin, $this->product, $shared, 7, branchId: $otherBranch->id);
    expect(collect($this->valuation->stockValuation(null, $shared->id))->sum('quantity'))->toEqual(10)
        ->and(collect($this->valuation->stockValuation($this->branch->id, $shared->id))->sum('quantity'))->toEqual(3)
        ->and($this->valuation->stockValuation($this->branch->id, $other->id))->toBe([])
        ->and($this->valuation->stockValuation(null, $inactive->id))->toBe([]);
    $component = Volt::test('reports.stock-valuation')->set('branch_id', $this->branch->id)->set('stock_location_id', $shared->id);
    $component->assertSee('TZS 12,000')->assertDontSee('Other branch valuation');
    $component->set('branch_id', $otherBranch->id)->assertSet('stock_location_id', '');

    $role = Role::create(['name' => 'Valuation branch', 'guard_name' => 'web']);
    $role->forceFill(['stock_scope' => 'branch', 'report_scope' => 'branch'])->save();
    $role->givePermissionTo(['dashboard.view', 'dashboard.stock_value', 'reports.stock', 'stock.view_value']);
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id]);
    $user->assignRole($role);
    $this->actingAs($user);
    expect(AuthorizationScope::scopeFor($user, 'stock_scope', 'assigned_locations'))->toBe('branch');
    expect($this->valuation->valuationLocations()->pluck('id'))->toContain($shared->id);
    expect($this->inventory->getProductStock($this->product->id, $shared->id, $user->branch_id))->toEqual(3);
    expect($this->valuation->stockValuation($otherBranch->id))->toBe([])
        ->and(collect($this->valuation->stockValuation(null, $shared->id))->sum('quantity'))->toEqual(3);
    Volt::test('dashboard')->assertDontSee('Other branch valuation')->assertDontSee('Inactive valuation');
    $this->get(route('reports.stock-valuation', ['stock_location_id' => $other->id]))->assertOk()->assertDontSee('Other branch valuation')->assertSee('TZS 0');
});

test('valuation and receipt queries enforce company isolation even for a company-scope admin', function () {
    $company = Company::create(['company_name' => 'Other valuation company', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $foreign = StockLocation::withoutGlobalScopes()->make(['company_id' => $company->id, 'name' => 'Foreign warehouse', 'code' => 'FOREIGN', 'type' => 'store', 'status' => 'active', 'is_active' => true]);
    // Bypass tenant assignment only when preparing another company's test fixture.
    StockLocation::withoutEvents(fn () => $foreign->save());
    expect($this->valuation->valuationLocations()->pluck('id'))->not->toContain($foreign->id)
        ->and($this->valuation->stockValuation(null, $foreign->id))->toBe([]);
});

test('goods receipt and POS posting change Zanzibar valuation and receipt drill down shows its location', function () {
    $location = valuationLocation($this->admin, 'Zanzibar store', $this->branch->id);
    Setting::query()->firstOrFail()->update(['enable_warehouse' => true, 'inventory_mode' => 'multi_location', 'allow_sales_from_store' => true]);
    $purchase = Purchase::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'supplier_id' => Supplier::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'name' => 'Valuation supplier', 'phone' => '255700000001', 'status' => 'active'])->id, 'reference_number' => 'VAL-PO',
        'purchase_date' => today(), 'status' => 'ordered', 'payment_status' => 'unpaid',
        'total_amount' => 480000, 'balance_amount' => 480000, 'created_by' => $this->admin->id,
    ]);
    $item = $purchase->items()->create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'purchase_unit_id' => $this->product->unit_id, 'stock_unit_id' => $this->product->unit_id,
        'purchase_conversion_factor' => 1, 'ordered_quantity' => 120, 'received_quantity' => 0,
        'cost_price' => 4000, 'selling_price' => 5000, 'line_total' => 480000,
    ]);
    $before = $this->valuation->stockReceivedToday($this->branch->id);
    $this->inventory->receivePurchase($purchase, [$item->id => ['quantity' => 120, 'stock_location_id' => $location->id]], today()->toDateString(), $this->admin->id);
    expect(collect($this->valuation->stockValuation($this->branch->id, $location->id))->sum('value'))->toEqual(480000)
        ->and($this->valuation->stockReceivedToday($this->branch->id))->toEqual($before + 120);
    $this->get(route('stock-movements.index', ['receipts_today' => 1, 'branch_id' => $this->branch->id]))->assertOk()->assertSee('Zanzibar store')->assertSee('purchase_receipt');
    $this->inventory->completeSale([[
        'product_id' => $this->product->id, 'stock_location_id' => $location->id,
        'sale_type' => 'retail', 'quantity' => 1, 'unit_price' => 5000, 'discount_amount' => 0, 'tax_amount' => 0,
    ]], [['payment_method' => 'cash', 'amount' => 5000]], null, $location->id, $this->branch->id, $this->admin->id);
    $rows = collect($this->valuation->stockValuation($this->branch->id, $location->id));
    expect($rows->sum('quantity'))->toEqual(119)->and($rows->sum('value'))->toEqual(476000);
    Volt::test('dashboard')->assertSee('TZS 476,000')->assertDontSee('Received into Main Store today');
});

test('posted transfer redistributes location value without inflating the total', function () {
    $source = valuationLocation($this->admin, 'Main Store Valuation', $this->branch->id);
    $destination = valuationLocation($this->admin, 'Zanzibar store', $this->branch->id);
    valuationMovement($this->admin, $this->product, $source, 120);
    valuationMovement($this->admin, $this->product, $destination, 1);
    $before = $this->valuation->stockValueByLocation($this->branch->id)->sum('value');
    $transfer = StockTransfer::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'transfer_number' => 'VAL-TRANSFER', 'from_location_id' => $source->id,
        'to_location_id' => $destination->id, 'transfer_date' => today(),
        'status' => 'draft', 'created_by' => $this->admin->id,
    ]);
    $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 20]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    $values = $this->valuation->stockValueByLocation($this->branch->id)->keyBy('stock_location_id');
    expect($values[$source->id]['value'])->toEqual(400000)
        ->and($values[$destination->id]['value'])->toEqual(84000)
        ->and($values->sum('value'))->toEqual($before);
});

test('valuation exports use the same explicit location and product filters as the report', function () {
    $location = valuationLocation($this->admin, 'Zanzibar store', $this->branch->id);
    valuationMovement($this->admin, $this->product, $location, 120);
    $request = Request::create('/', 'GET', ['branch_id' => $this->branch->id, 'stock_location_id' => $location->id, 'search' => 'Valuation Test']);
    $request->setUserResolver(fn () => $this->admin);
    $data = app(ReportExportService::class)->build('reports.stock-valuation', $request);
    expect($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0][1])->toBe('Zanzibar store')
        ->and($data['totals']['Total Value'])->toBe('TZS 480,000');
    $filters = ['branch_id' => $this->branch->id, 'stock_location_id' => $location->id, 'search' => 'Valuation Test'];
    $page = $this->get(route('reports.stock-valuation', $filters))->assertOk()->assertSee('TZS 480,000')->assertSee('window.print()', escape: false);
    foreach (['pdf', 'excel'] as $format) {
        $page->assertSee(route('exports.download', ['export' => 'reports.stock-valuation', 'format' => $format] + $filters));
    }
    $excel = $this->get(route('exports.download', ['export' => 'reports.stock-valuation', 'format' => 'excel'] + $filters))->assertOk();
    expect($excel->streamedContent())->toContain('Zanzibar store')->toContain('480,000');
    $pdf = $this->get(route('exports.download', ['export' => 'reports.stock-valuation', 'format' => 'pdf'] + $filters))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF');
});

test('receipt summary and drill down exclude unassigned locations and non-receipt movements', function () {
    $allowed = valuationLocation($this->admin, 'Allowed receiving store', $this->branch->id);
    $denied = valuationLocation($this->admin, 'Private receiving store', $this->branch->id);
    valuationMovement($this->admin, $this->product, $allowed, 12, 'purchase_receipt');
    valuationMovement($this->admin, $this->product, $allowed, 3, 'purchase_in');
    valuationMovement($this->admin, $this->product, $allowed, 50, 'transfer_in');
    valuationMovement($this->admin, $this->product, $denied, 100, 'purchase_receipt');
    valuationMovement($this->admin, $this->product, $allowed, 9, 'purchase_receipt')->update(['movement_date' => today()->subDay()]);
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole('Store Keeper');
    $user->stockLocations()->sync([
        $allowed->id => ['company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'can_view' => true],
        $denied->id => ['company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'can_view' => false],
    ]);
    $this->actingAs($user);
    expect($this->valuation->stockReceivedToday())->toEqual(15)
        ->and($this->valuation->stockValuation(null, $denied->id))->toBe([]);
    $this->get(route('stock-movements.index', ['receipts_today' => 1]))->assertOk()->assertSee('Allowed receiving store')->assertDontSee('Private receiving store');
    $request = Request::create('/', 'GET', ['receipts_today' => 1]);
    $request->setUserResolver(fn () => $user);
    $user->givePermissionTo('reports.export');
    $data = app(ReportExportService::class)->build('tables.stock-movements', $request);
    expect($data['rows'])->toHaveCount(2);
    foreach ($data['rows'] as $row) {
        expect($row[2])->toBe('Allowed receiving store');
    }
});

test('transfer into a new location preserves cost and canonical valuation agreement', function () {
    $source = valuationLocation($this->admin, 'Costed source', $this->branch->id);
    $destination = valuationLocation($this->admin, 'New destination', $this->branch->id);
    valuationMovement($this->admin, $this->product, $source, 120);
    $transfer = StockTransfer::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'transfer_number' => 'VAL-NO-COST', 'from_location_id' => $source->id,
        'to_location_id' => $destination->id, 'transfer_date' => today(),
        'status' => 'draft', 'created_by' => $this->admin->id,
    ]);
    $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 20]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    expect($this->inventory->getAverageCost($this->product->id, $destination->id, $this->branch->id))->toEqual(4000);
    $values = $this->valuation->stockValueByLocation($this->branch->id)->whereIn('stock_location_id', [$source->id, $destination->id]);
    expect($values->sum('quantity'))->toEqual(120)
        ->and($values->sum('value'))->toEqual(480000)
        ->and(collect($this->valuation->stockValuation($this->branch->id, $destination->id))->sum('value'))->toEqual(80000);
    Volt::test('dashboard')->assertSee('New destination');
});
