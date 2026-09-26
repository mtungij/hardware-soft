<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use App\Services\StockLedgerReadService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->actingAs($this->admin);
    Setting::firstOrFail()->update(['enable_warehouse' => true, 'inventory_mode' => 'multi_location']);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Ledger Cement', 'sku' => 'LEDGER-CEMENT', 'barcode' => null, 'status' => 'active']);
    $this->product->save();
    $this->location = ledgerLocation($this->admin, $this->branch, 'Ledger Main Shop', 'LEDGER-SHOP');
});

function ledgerLocation(User $user, Branch $branch, string $name, string $code, string $type = 'dispensing'): StockLocation
{
    return StockLocation::create([
        'company_id' => $user->company_id, 'branch_id' => $branch->id,
        'name' => $name, 'code' => $code, 'type' => $type,
        'status' => 'active', 'is_active' => true,
        'can_receive_stock' => true, 'can_issue_stock' => true,
        'can_sell' => true, 'is_sellable' => true,
    ]);
}

function ledgerMovement(object $test, StockLocation $location, string $type, float $quantity, array $extra = []): StockMovement
{
    return StockMovement::create(array_merge([
        'company_id' => $test->admin->company_id,
        'branch_id' => $location->branch_id,
        'product_id' => $test->product->id,
        'stock_location_id' => $location->id,
        'movement_type' => $type,
        'quantity' => $quantity,
        'unit_cost' => 4000,
        'created_by' => $test->admin->id,
        'movement_date' => '2026-09-19',
    ], $extra));
}

function ledgerLatest(object $test, StockLocation $location): array
{
    $current = app(InventoryService::class)->getProductStock($test->product->id, $location->id, $location->branch_id);
    $rows = collect([(object) ['product_id' => $test->product->id, 'stock_location_id' => $location->id, 'quantity' => $current]]);
    $service = app(StockLedgerReadService::class);

    return $service->latestForRows($rows, $test->admin)[$service->key($test->product->id, $location->id)];
}

test('latest action arithmetic follows signed movements and chronological ledger order', function (string $type, float $starting, float $movement, float $change, string $label) {
    if ($starting > 0) {
        ledgerMovement($this, $this->location, 'purchase_receipt', $starting, ['movement_date' => '2026-09-18']);
    }
    ledgerMovement($this, $this->location, $type, $movement);

    $latest = ledgerLatest($this, $this->location);
    $report = app(StockLedgerReadService::class)->ledger($this->product, $this->location, $this->admin);
    expect($latest['action'])->toBe($label)
        ->and($latest['before'])->toBe($starting)
        ->and($latest['change'])->toBe($change)
        ->and($latest['after'])->toBe($starting + $change)
        ->and($report['current'])->toBe($starting + $change)
        ->and($report['history']->last()['before'])->toBe($starting)
        ->and($report['history']->last()['after'])->toBe($starting + $change);
})->with([
    ['purchase_receipt', 0, 150, 150, 'Purchase Receipt'],
    ['sale_out', 150, 13, -13, 'Sale Out'],
    ['transfer_out', 180, 30, -30, 'Transfer Out'],
    ['transfer_in', 120, 30, 30, 'Transfer In'],
    ['opening_stock', 0, 200, 200, 'Opening Stock'],
    ['adjustment_in', 98, 2, 2, 'Stock Adjustment'],
    ['adjustment_out', 100, 4, -4, 'Stock Adjustment'],
]);

test('four locations keep independent latest actions and use a batched lookup', function () {
    $locations = collect([
        $this->location,
        ledgerLocation($this->admin, $this->branch, 'Main Warehouse', 'LEDGER-WH', 'warehouse'),
        ledgerLocation($this->admin, $this->branch, 'Cement Counter', 'LEDGER-CEMENT'),
        ledgerLocation($this->admin, $this->branch, 'Wholesale Counter', 'LEDGER-WHOLESALE'),
    ]);
    foreach ($locations as $index => $location) {
        ledgerMovement($this, $location, 'purchase_receipt', 100 + $index * 10, ['movement_date' => '2026-09-18']);
        ledgerMovement($this, $location, ['sale_out', 'transfer_out', 'transfer_in', 'adjustment_in'][$index], $index + 1);
    }
    $rows = $locations->map(fn ($location) => (object) [
        'product_id' => $this->product->id,
        'stock_location_id' => $location->id,
        'quantity' => app(InventoryService::class)->getProductStock($this->product->id, $location->id, $this->branch->id),
    ]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service = app(StockLedgerReadService::class);
    $latest = $service->latestForRows($rows, $this->admin);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(4);
    foreach ($locations as $index => $location) {
        $summary = $latest[$service->key($this->product->id, $location->id)];
        $starting = 100 + $index * 10;
        $change = in_array($index, [0, 1], true) ? -($index + 1) : $index + 1;
        expect($summary['before'])->toBe((float) $starting)
            ->and($summary['change'])->toBe((float) $change)
            ->and($summary['after'])->toBe((float) ($starting + $change));
    }
    expect($latest[$service->key($this->product->id, $locations[0]->id)]['action'])->toBe('Sale Out')
        ->and($latest[$service->key($this->product->id, $locations[1]->id)]['action'])->toBe('Transfer Out')
        ->and($latest[$service->key($this->product->id, $locations[2]->id)]['action'])->toBe('Transfer In')
        ->and($latest[$service->key($this->product->id, $locations[3]->id)]['action'])->toBe('Stock Adjustment');
});

test('empty location history has a safe zero balance and no latest action', function () {
    $latest = ledgerLatest($this, $this->location);
    $report = app(StockLedgerReadService::class)->ledger($this->product, $this->location, $this->admin);
    expect($latest['action'])->toBe('No movement')
        ->and($latest['before'])->toBeNull()
        ->and($latest['change'])->toBeNull()
        ->and($latest['after'])->toBe(0.0)
        ->and($latest['reference'])->toBe('—')
        ->and($latest['date'])->toBeNull()
        ->and($report['history'])->toBeEmpty();

    Volt::test('store-stock.index')->set('search', 'LEDGER-CEMENT')->assertSee('No movement');
});

test('page and ledger show the latest local sale with before change after and reference', function () {
    ledgerMovement($this, $this->location, 'purchase_receipt', 150, ['movement_date' => '2026-09-18']);
    ledgerMovement($this, $this->location, 'sale_out', 13, ['posting_reference' => 'SALE-LEDGER-17']);

    Volt::test('store-stock.index')
        ->set('search', 'LEDGER-CEMENT')
        ->set('locationFilter', (string) $this->location->id)
        ->assertSee('Ledger Cement')->assertSee('Ledger Main Shop')
        ->assertSee('Sale Out')->assertSee('150 → 137')->assertSee('(-13)')
        ->assertSee('SALE-LEDGER-17')
        ->call('openLedger', $this->product->id, $this->location->id)
        ->assertSee('Quantity Before')->assertSee('Quantity After')
        ->assertSee('Stock Before')->assertSee('Stock After')
        ->assertSee('Download PDF');
});

test('grouped view shows location actions below an aggregate with no aggregate action', function () {
    ledgerMovement($this, $this->location, 'purchase_receipt', 150);
    $other = ledgerLocation($this->admin, $this->branch, 'Ledger Counter', 'LEDGER-COUNTER');
    ledgerMovement($this, $other, 'sale_out', 2);

    Volt::test('store-stock.index')->set('search', 'LEDGER-CEMENT')->set('groupedView', true)
        ->assertSee('Total across shown locations')
        ->assertSee('latest actions are shown per location above')
        ->assertSee('Purchase Receipt')->assertSee('Sale Out');
});

test('ledger PDF contains one A4 document for the authorized product and location', function () {
    ledgerMovement($this, $this->location, 'purchase_receipt', 150, ['movement_date' => '2026-09-18']);
    ledgerMovement($this, $this->location, 'sale_out', 13, ['posting_reference' => 'SALE-LEDGER-17']);
    $response = $this->get(route('store-stock.ledger.pdf', [$this->product, $this->location]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdf = $response->getContent();
    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(10000)
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdf))->toBe(1);
    $report = app(StockLedgerReadService::class)->ledger($this->product, $this->location, $this->admin);
    $html = view('pdf.stock-ledger', [
        'product' => $this->product->load('unit', 'size'), 'location' => $this->location,
        'report' => $report, 'company' => $this->admin->company, 'logo' => null, 'canViewValue' => true,
    ])->render();
    expect($html)->toContain('Stock Ledger', 'Ledger Cement', 'Ledger Main Shop', 'SALE-LEDGER-17', '150', '137', '13')
        ->not->toContain('@page');
});

test('main PDF includes latest action fields and respects selected filters', function () {
    ledgerMovement($this, $this->location, 'purchase_receipt', 150);
    $other = ledgerLocation($this->admin, $this->branch, 'Ledger Other Counter', 'LEDGER-OTHER');
    ledgerMovement($this, $other, 'sale_out', 7);
    $query = ['branchFilter' => $this->branch->id, 'stock_location_id' => $this->location->id,
        'categoryFilter' => $this->product->category_id, 'brand' => $this->product->brand,
        'search' => 'LEDGER-CEMENT', 'grouped' => 1];
    $request = Request::create('/exports/tables.store-stock/pdf', 'GET', $query);
    $request->setUserResolver(fn () => $this->admin);
    $payload = app(ReportExportService::class)->build('tables.store-stock', $request);
    expect($payload['headers'])->toContain('Latest Action', 'Before', 'Change', 'Reference', 'Action Date');
    expect($payload['rows'])->toHaveCount(1);
    expect(implode(' ', $payload['rows'][0]))->toContain('Ledger Main Shop', 'Purchase Receipt', '150')
        ->not->toContain('Ledger Other Counter');

    $response = $this->get(route('exports.download', ['export' => 'tables.store-stock', 'format' => 'pdf', ...$query]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('stock export applies each selected filter to product-location rows', function () {
    $this->product->update(['brand' => 'Ledger Brand', 'reorder_level' => 200]);
    ledgerMovement($this, $this->location, 'purchase_receipt', 150);
    $otherLocation = ledgerLocation($this->admin, $this->branch, 'Other Ledger Shop', 'LEDGER-OTHER-SHOP');
    $otherBranch = Branch::create(['company_id' => $this->admin->company_id, 'name' => 'Other Ledger Branch', 'code' => 'LEDGER-OTHER-BRANCH', 'status' => 'active']);
    ledgerLocation($this->admin, $otherBranch, 'Other Branch Shop', 'LEDGER-OTHER-BRANCH-SHOP');
    $otherCategory = Category::query()->whereKeyNot($this->product->category_id)->firstOrFail();
    $supplier = Supplier::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'name' => 'Ledger Supplier', 'phone' => '+255700111001', 'status' => 'active']);
    $otherSupplier = Supplier::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'name' => 'Other Ledger Supplier', 'phone' => '+255700111002', 'status' => 'active']);
    $purchase = Purchase::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id, 'purchase_date' => today(),
        'reference_number' => 'LEDGER-PO-1', 'status' => 'ordered',
        'payment_status' => 'unpaid', 'total_amount' => 100, 'paid_amount' => 0,
        'balance_amount' => 100, 'created_by' => $this->admin->id,
    ]);
    $purchase->items()->create([
        'company_id' => $this->admin->company_id, 'product_id' => $this->product->id,
        'purchase_unit_id' => $this->product->purchase_unit_id,
        'stock_unit_id' => $this->product->unit_id,
        'purchase_conversion_factor' => $this->product->purchaseConversionFactor(),
        'ordered_quantity' => 1, 'received_quantity' => 0,
        'cost_price' => 100, 'selling_price' => 150, 'line_total' => 100,
    ]);
    $service = app(ReportExportService::class);
    $rowsFor = function (array $filters) use ($service): array {
        $request = Request::create('/exports/tables.store-stock/pdf', 'GET', $filters);
        $request->setUserResolver(fn () => $this->admin);

        return $service->build('tables.store-stock', $request)['rows'];
    };
    $base = ['search' => 'LEDGER-CEMENT', 'stock_location_id' => $this->location->id];
    expect($rowsFor($base))->toHaveCount(1);
    expect($rowsFor($base + ['branchFilter' => $this->branch->id]))->toHaveCount(1);
    expect($rowsFor($base + ['branchFilter' => $otherBranch->id]))->toBeEmpty();
    expect($rowsFor(['search' => 'LEDGER-CEMENT', 'stock_location_id' => $otherLocation->id]))->toHaveCount(1);
    expect($rowsFor($base + ['categoryFilter' => $this->product->category_id]))->toHaveCount(1);
    expect($rowsFor($base + ['categoryFilter' => $otherCategory->id]))->toBeEmpty();
    expect($rowsFor($base + ['brand' => 'Ledger Brand']))->toHaveCount(1);
    expect($rowsFor($base + ['brand' => 'Other Brand']))->toBeEmpty();
    expect($rowsFor($base + ['supplier_id' => $supplier->id]))->toHaveCount(1);
    expect($rowsFor($base + ['supplier_id' => $otherSupplier->id]))->toBeEmpty();
    expect($rowsFor(['search' => 'NO-MATCH-STOCK', 'stock_location_id' => $this->location->id]))->toBeEmpty();
    expect($rowsFor($base + ['low_stock_only' => 1]))->toHaveCount(1);
    expect($rowsFor($base + ['out_of_stock_only' => 1]))->toBeEmpty();
    expect($rowsFor(['search' => 'LEDGER-CEMENT', 'stock_location_id' => $otherLocation->id, 'out_of_stock_only' => 1]))->toHaveCount(1);
});

test('assigned, branch and company scopes block guessed ledger PDF locations', function () {
    ledgerMovement($this, $this->location, 'purchase_receipt', 5);
    $other = ledgerLocation($this->admin, $this->branch, 'Hidden Ledger Counter', 'LEDGER-HIDDEN');
    $role = Role::create(['name' => 'Ledger assigned reader', 'guard_name' => 'web', 'stock_scope' => 'assigned_locations']);
    $role->givePermissionTo('stock.view');
    $reader = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $reader->assignRole($role);
    $reader->stockLocations()->attach($this->location->id, ['company_id' => $reader->company_id, 'branch_id' => $reader->branch_id, 'can_view' => true]);
    $this->actingAs($reader);
    $this->get(route('store-stock.ledger.pdf', [$this->product, $this->location]))->assertOk();
    $this->get(route('store-stock.ledger.pdf', [$this->product, $other]))->assertForbidden();
    Volt::test('store-stock.index')->call('openLedger', $this->product->id, $other->id)->assertForbidden();

    $otherBranch = Branch::create(['company_id' => $this->admin->company_id, 'name' => 'Other Ledger Branch', 'code' => 'LEDGER-BRANCH', 'status' => 'active']);
    $branchLocation = ledgerLocation($this->admin, $otherBranch, 'Other Branch Counter', 'LEDGER-BRANCH-LOC');
    $branchRole = Role::create(['name' => 'Ledger branch reader', 'guard_name' => 'web', 'stock_scope' => 'branch']);
    $branchRole->givePermissionTo('stock.view');
    $reader->syncRoles([$branchRole]);
    $reader->unsetRelation('roles');
    $this->get(route('store-stock.ledger.pdf', [$this->product, $branchLocation]))->assertForbidden();

    $company = Company::create(['company_name' => 'Other Ledger Company', 'business_type' => 'hardware', 'phone' => '123', 'whatsapp_number' => '123']);
    $foreignId = DB::table('stock_locations')->insertGetId([
        'company_id' => $company->id, 'branch_id' => null, 'name' => 'Foreign Ledger',
        'code' => 'FOREIGN-LEDGER', 'type' => 'store', 'status' => 'active',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->get(route('store-stock.ledger.pdf', [$this->product->id, $foreignId]))->assertNotFound();
});
