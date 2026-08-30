<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportExportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Livewire\Volt\Volt;

beforeEach(function () {
    app()->setLocale('en');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->location = StockLocation::withoutGlobalScopes()->where('company_id', $this->branch->company_id)->where('branch_id', $this->branch->id)->where('type', 'dispensing')->firstOrFail();
    $this->category = Category::withoutGlobalScopes()->where('company_id', $this->branch->company_id)->firstOrFail();
    $this->otherCategory = Category::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Report Other Category',
        'code' => 'REPORT-OTHER',
        'status' => 'active',
    ]);
    $this->customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Report Main Customer',
        'phone' => '255700880001',
        'customer_type' => 'cash',
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);

    $this->products = collect([
        salesReportProduct($this, 'Report Hammer', 'REPORT-HAMMER', $this->category->id),
        salesReportProduct($this, 'Report Cement', 'REPORT-CEMENT', $this->category->id),
        salesReportProduct($this, 'Report Crated Tiles', 'REPORT-CRATE', $this->category->id),
    ]);
    $crate = Unit::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'measurement_type_id' => $this->products[2]->measurement_type_id,
        'name' => 'Report Crate',
        'short_name' => 'crate-report',
        'status' => 'active',
    ]);
    $this->mainSale = salesReportSale($this->branch, $this->location, $this->customer, $this->admin, 'REPORT-SALE-MAIN', today(), 8000, 'cash');
    salesReportItem($this->mainSale, $this->products[0], $this->location, 2, 2, 40, 100, 200, $this->products[0]->unit, 'retail');
    salesReportItem($this->mainSale, $this->products[1], $this->location, 3, 3, 50, 200, 600, $this->products[1]->unit, 'retail');
    salesReportItem($this->mainSale, $this->products[2], $this->location, 2, 48, 1440, 3600, 7200, $crate, 'retail', 24);

    $this->otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'name' => 'Report Other Branch',
        'code' => 'REPORT-OTHER-BRANCH',
        'status' => 'active',
    ]);
    $this->otherLocation = StockLocation::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->otherBranch->id,
        'name' => 'Report Other Location',
        'code' => 'REPORT-OTHER-LOCATION',
        'type' => 'dispensing',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'is_sellable' => true,
    ]);
    $this->otherCashier = User::factory()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->otherBranch->id,
        'status' => 'active',
    ]);
    $this->otherCashier->assignRole('Cashier');
    $this->otherCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->branch->company_id,
        'branch_id' => $this->otherBranch->id,
        'name' => 'Report Other Customer',
        'phone' => '255700880002',
        'customer_type' => 'cash',
        'opening_balance' => 0,
        'balance_amount' => 0,
        'status' => 'active',
    ]);
    $this->otherProduct = salesReportProduct($this, 'Report Other Product', 'REPORT-OTHER-PRODUCT', $this->otherCategory->id, $this->otherBranch->id);
    $this->otherSale = salesReportSale($this->otherBranch, $this->otherLocation, $this->otherCustomer, $this->otherCashier, 'REPORT-SALE-OTHER', today()->subDay(), 500, 'bank', 'wholesale');
    salesReportItem($this->otherSale, $this->otherProduct, $this->otherLocation, 1, 1, 250, 500, 500, $this->otherProduct->unit, 'wholesale');

    $this->products->each->update(['buying_price' => 9999, 'selling_price' => 9999, 'wholesale_price' => 9999]);
    $crate->update(['short_name' => 'renamed-current-crate']);
    $this->actingAs($this->admin);
    app()->setLocale('en');
});

test('sales report defaults to visible item rows using historical snapshots without duplicated summaries', function () {
    $component = Volt::test('sales.index')
        ->assertSet('view', 'items')
        ->set('search', $this->mainSale->sale_number);
    $html = $component->html();

    expect(substr_count($html, 'data-sale-item-row='))->toBe(3)
        ->and(substr_count($html, 'data-item-heading='))->toBe(10)
        ->and($html)->toContain('<thead')
        ->toContain('data-item-report-scroll')
        ->toContain('overflow-x-auto')
        ->toContain('min-w-[1400px]')
        ->toContain('Sale / Invoice', 'Customer', 'Product Name', 'Quantity Sold', 'Unit', 'Buying Price / Unit', 'Selling Price / Unit', 'Total Cost', 'Total Sales', 'Profit')
        ->toMatch('/data-item-heading="sale".*data-item-heading="customer".*data-item-heading="product".*data-item-heading="quantity".*data-item-heading="unit".*data-item-heading="buying-price".*data-item-heading="selling-price".*data-item-heading="total-cost".*data-item-heading="total-sales".*data-item-heading="profit"/s')
        ->and($html)->toContain('Report Hammer')->toContain('REPORT-HAMMER')
        ->toContain('Report Cement')->toContain('REPORT-CEMENT')
        ->toContain('Report Crated Tiles')->toContain('REPORT-CRATE')
        ->toContain('crate-report')->not->toContain('renamed-current-crate')
        ->toContain('TZS 40')->toContain('TZS 100')->toContain('TZS 80')->toContain('TZS 200')->toContain('TZS 120')
        ->not->toContain('TZS 9,999')->not->toContain('Expand')
        ->and($html)->toMatch('/data-sales-summary="total-sales"[^>]*>.*?TZS 8,000/s')
        ->toMatch('/data-sales-summary="total-cost"[^>]*>.*?TZS 3,110/s')
        ->toMatch('/data-sales-summary="total-profit"[^>]*>.*?TZS 4,890/s')
        ->toMatch('/data-sales-summary="total-invoices"[^>]*>.*?>1</s');

    preg_match('/<tr[^>]*data-sale-item-row=.*?<\/tr>/s', $html, $firstItemRow);
    expect($firstItemRow)->not->toBeEmpty()
        ->and(substr_count($firstItemRow[0], '<td'))->toBe(10);

    $component->set('view', 'sales')->assertSee('Expand')->assertSee($this->mainSale->sale_number);
});

test('item report filters are applied before rows and distinct summaries are built', function () {
    $cases = [
        ['customer_id' => $this->customer->id],
        ['product_id' => $this->products[0]->id],
        ['category_id' => $this->category->id],
        ['cashier_id' => $this->admin->id],
        ['stock_location_id' => $this->location->id],
        ['payment_method' => 'cash'],
        ['sale_type' => 'retail'],
        ['branch_id' => $this->branch->id],
        ['date_from' => today()->toDateString(), 'date_to' => today()->toDateString()],
        ['search' => $this->mainSale->sale_number],
    ];

    foreach ($cases as $filters) {
        $component = Volt::test('sales.index');
        foreach ($filters as $key => $value) {
            $component->set($key, (string) $value);
        }
        $html = $component->html();
        expect($html)->toContain($this->mainSale->sale_number)->not->toContain($this->otherSale->sale_number)
            ->and(substr_count($html, 'data-sale-item-row='))->toBe(isset($filters['product_id']) ? 1 : 3);
    }

    foreach ($cases as $filters) {
        $payload = app(ReportExportService::class)->build('tables.sales-items', salesReportRequest($this->admin, $filters));
        expect($payload['rows'])->not->toBeEmpty();
        foreach ($payload['rows'] as $row) {
            expect($row[0])->toBe($this->mainSale->sale_number);
        }
    }

    $productPayload = app(ReportExportService::class)->build('tables.sales-items', salesReportRequest($this->admin, ['product_id' => $this->products[0]->id]));
    expect($productPayload['rows'])->toHaveCount(1)
        ->and($productPayload['totals'][__('messages.sales_items.total_invoices')])->toBe('1')
        ->and($productPayload['totals'][__('messages.sales_items.total_sales')])->toBe('TZS 200');
});

test('sales item PDF and Excel exports use filtered item rows and preserve snapshot permissions', function () {
    $payload = app(ReportExportService::class)->build('tables.sales-items', salesReportRequest($this->admin, ['search' => $this->mainSale->sale_number]));

    expect($payload['rows'])->toHaveCount(3)
        ->and($payload['headers'])->toContain('Product Name', 'SKU', 'Quantity Sold', 'Unit', 'Buying Price / Unit', 'Selling Price / Unit', 'Total Cost', 'Total Sales', 'Profit')
        ->and(collect($payload['rows'])->pluck(0)->unique()->all())->toBe([$this->mainSale->sale_number])
        ->and(collect($payload['rows'])->flatten()->all())->toContain('crate-report', 'TZS 3,600', 'TZS 7,200');

    $params = ['export' => 'tables.sales-items', 'search' => $this->mainSale->sale_number];
    $this->get(route('exports.download', $params + ['format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('exports.download', $params + ['format' => 'excel']))->assertOk();
    $this->get(route('sales.index', ['search' => $this->mainSale->sale_number]))->assertOk()->assertSee('window.print()', false)->assertSee('Report Hammer');

    $cashier = User::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $cashier->givePermissionTo(['reports.export', 'reports.sales']);
    $restrictedPayload = app(ReportExportService::class)->build('tables.sales-items', salesReportRequest($cashier, ['search' => $this->mainSale->sale_number]));
    expect($restrictedPayload['headers'])->not->toContain('Buying Price / Unit', 'Total Cost', 'Profit');
});

test('branch and assigned stock-location scopes remain enforced for item rows', function () {
    $cashier = User::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $cashier->stockLocations()->sync([
        $this->location->id => [
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'can_view' => true,
            'can_sell' => true,
            'can_transfer' => false,
            'can_receive' => false,
            'can_adjust' => false,
            'is_default' => true,
        ],
        $this->otherLocation->id => [
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->otherBranch->id,
            'can_view' => false,
            'can_sell' => false,
            'can_transfer' => false,
            'can_receive' => false,
            'can_adjust' => false,
            'is_default' => false,
        ],
    ]);
    $ownAssigned = salesReportSale($this->branch, $this->location, null, $cashier, 'REPORT-ASSIGNED', today(), 100, 'cash');
    salesReportItem($ownAssigned, $this->products[0], $this->location, 1, 1, 40, 100, 100, $this->products[0]->unit, 'retail');
    $ownUnassigned = salesReportSale($this->otherBranch, $this->otherLocation, null, $cashier, 'REPORT-UNASSIGNED', today(), 500, 'cash');
    salesReportItem($ownUnassigned, $this->otherProduct, $this->otherLocation, 1, 1, 250, 500, 500, $this->otherProduct->unit, 'retail');

    $this->actingAs($cashier);
    $html = Volt::test('sales.index')->html();

    expect($html)->toContain('REPORT-ASSIGNED')->not->toContain('REPORT-UNASSIGNED')
        ->and(substr_count($html, 'data-item-heading='))->toBe(7)
        ->and($html)->toContain('min-w-[980px]')
        ->toContain('data-item-heading="sale"', 'data-item-heading="customer"', 'data-item-heading="product"', 'data-item-heading="quantity"', 'data-item-heading="unit"', 'data-item-heading="selling-price"', 'data-item-heading="total-sales"')
        ->not->toContain('data-item-heading="buying-price"', 'data-item-heading="total-cost"', 'data-item-heading="profit"')
        ->not->toContain('Buying Price / Unit')->not->toContain('Total Cost')->not->toContain('Profit');

    preg_match('/<tr[^>]*data-sale-item-row=.*?<\/tr>/s', $html, $firstItemRow);
    expect($firstItemRow)->not->toBeEmpty()
        ->and(substr_count($firstItemRow[0], '<td'))->toBe(7);
});

function salesReportProduct(object $test, string $name, string $sku, int $categoryId, ?int $branchId = null): Product
{
    $product = Product::withoutGlobalScopes()->where('company_id', $test->branch->company_id)->firstOrFail()->replicate();
    $product->company_id = $test->branch->company_id;
    $product->branch_id = $branchId ?: $test->branch->id;
    $product->category_id = $categoryId;
    $product->name = $name;
    $product->sku = $sku;
    $product->barcode = null;
    $product->buying_price = 1;
    $product->selling_price = 1;
    $product->wholesale_price = 1;
    $product->status = 'active';
    $product->save();

    return $product->refresh();
}

function salesReportSale(Branch $branch, StockLocation $location, ?Customer $customer, User $cashier, string $number, $date, float $total, string $method, string $type = 'retail'): Sale
{
    $sale = Sale::withoutGlobalScopes()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'stock_location_id' => $location->id,
        'customer_id' => $customer?->id,
        'sale_number' => $number,
        'sale_date' => $date,
        'sale_type' => $type,
        'subtotal' => $total,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => $total,
        'balance_amount' => 0,
        'change_amount' => 0,
        'payment_status' => 'paid',
        'status' => 'completed',
        'sold_by' => $cashier->id,
        'created_by' => $cashier->id,
    ]);
    SalePayment::withoutGlobalScopes()->create([
        'company_id' => $branch->company_id,
        'sale_id' => $sale->id,
        'payment_method' => $method,
        'amount' => $total,
        'received_by' => $cashier->id,
        'payment_date' => $date,
    ]);

    return $sale;
}

function salesReportItem(Sale $sale, Product $product, StockLocation $location, float $quantity, float $baseQuantity, float $unitCost, float $unitPrice, float $lineTotal, Unit $sellingUnit, string $type, float $factor = 1): SaleItem
{
    return SaleItem::withoutGlobalScopes()->create([
        'company_id' => $sale->company_id,
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'selling_unit_id' => $sellingUnit->id,
        'base_unit_id' => $product->unit_id,
        'conversion_factor' => $factor,
        'selling_unit_name_snapshot' => $sellingUnit->name,
        'selling_unit_code_snapshot' => $sellingUnit->short_name,
        'base_unit_name_snapshot' => $product->unit?->name,
        'base_unit_code_snapshot' => $product->unit?->short_name,
        'sold_from_label' => $location->name,
        'sale_type' => $type,
        'quantity' => $quantity,
        'base_quantity' => $baseQuantity,
        'unit_cost' => $unitCost,
        'base_unit_cost' => $factor > 1 ? $unitCost / $factor : $unitCost,
        'unit_price' => $unitPrice,
        'discount_per_unit' => 0,
        'discount_amount' => 0,
        'discount_total' => 0,
        'gross_total' => $lineTotal,
        'net_unit_price' => $unitPrice,
        'net_total' => $lineTotal,
        'tax_amount' => 0,
        'line_total' => $lineTotal,
    ]);
}

function salesReportRequest(User $user, array $query = []): Request
{
    $request = Request::create('/exports/tables.sales-items/excel', 'GET', $query);
    $request->setUserResolver(fn () => $user);

    return $request;
}
