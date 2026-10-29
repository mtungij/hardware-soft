<?php

use App\Models\Branch;
use App\Models\CashbookSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashbookService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use App\Services\WhatsAppMessageFactory;
use App\Support\AuthorizationScope;
use App\Support\BranchAccess;
use App\Support\InventorySettings;
use App\Support\PosSaleDate;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();
    Http::fake();
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->admin->update(['is_system_owner' => false]);
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['timezone' => 'Africa/Dar_es_Salaam']);
    $this->branch = Branch::withoutGlobalScopes()->findOrFail($this->admin->branch_id);
    StockLocation::query()->where('branch_id', $this->branch->id)->where('code', 'DISPENSING')
        ->update(['can_receive_stock' => true, 'can_sell' => true, 'is_sellable' => true, 'is_active' => true, 'status' => 'active']);
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->update(['can_sell' => true, 'is_sellable' => true, 'is_active' => true, 'status' => 'active']);
    $this->product = Product::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $this->product->update(['branch_id' => $this->branch->id, 'selling_price' => 100, 'buying_price' => 10]);
    StockMovement::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_location_id' => $this->location->id, 'movement_type' => 'adjustment_in', 'quantity' => 20, 'quantity_in' => 20, 'quantity_out' => 0, 'unit_cost' => 10, 'movement_date' => today(), 'created_by' => $this->admin->id]);
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
    $this->clock = app(PosSaleDate::class);
});

function datedPosSale(array $dateDetails = [], float $quantity = 1, ?string $key = null): Sale
{
    $t = test();

    return app(InventoryService::class)->completeSale(
        [['product_id' => $t->product->id, 'stock_location_id' => $t->location->id, 'quantity' => $quantity]],
        [['payment_method' => 'cash', 'amount' => 100 * $quantity]],
        null, $t->location->id, $t->branch->id, $t->admin->id,
        idempotencyKey: $key,
        dateDetails: $dateDetails,
    );
}

test('assigned company super admin sees null and other branch master data but never another company', function () {
    $branchB = Branch::create(['company_id' => $this->company->id, 'name' => 'Second Company Branch', 'code' => 'DATE-B', 'status' => 'active']);
    $companyProduct = $this->product->replicate();
    $companyProduct->fill(['branch_id' => null, 'sku' => 'COMPANY-NULL-DATE', 'barcode' => null])->save();
    expect(Product::whereKey($companyProduct->id)->exists())->toBeTrue();
    $null = Supplier::create(['company_id' => $this->company->id, 'branch_id' => null, 'name' => 'Legacy Company Supplier', 'phone' => '255700000001', 'status' => 'active']);
    $other = Supplier::create(['company_id' => $this->company->id, 'branch_id' => $branchB->id, 'name' => 'Second Branch Supplier', 'phone' => '255700000002', 'status' => 'active']);
    $company = $this->company->replicate();
    $company->company_name = 'Foreign Tenant';
    $company->save();
    DB::table('suppliers')->insert(['company_id' => $company->id, 'branch_id' => null, 'name' => 'Foreign Supplier', 'phone' => '255700000003', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    expect(BranchAccess::restricted($this->admin))->toBeFalse()->and($this->admin->is_system_owner)->toBeFalse();
    expect(Supplier::pluck('name'))->toContain('Legacy Company Supplier', 'Second Branch Supplier')->not->toContain('Foreign Supplier');
    expect(BranchAccess::branches($this->admin)->pluck('id'))->toContain($branchB->id);
    $null->update(['name' => 'Updated legacy supplier']);
    expect($null->fresh()->branch_id)->toBeNull();
    $other->delete();
    Volt::test('settings.customer-portal')->set('company_id', (string) $company->id)->assertForbidden();
    $this->admin->syncRoles(['Cashier']);
    $this->admin->unsetRelation('roles');
    expect(BranchAccess::restricted($this->admin))->toBeTrue()->and(Supplier::whereKey($null->id)->exists())->toBeFalse()
        ->and(Product::whereKey($companyProduct->id)->exists())->toBeFalse();
    expect(fn () => $null->update(['name' => 'Forbidden']))->toThrow(ValidationException::class);
});

test('super admin branch selectors reports and POS selling locations stay in company', function () {
    $branchB = Branch::create(['company_id' => $this->company->id, 'name' => 'Authorized Second Branch', 'code' => 'POS-DATE-B', 'status' => 'active']);
    $location = app(InventoryService::class)->getDispensingLocation($branchB->id);
    $location->update(['is_sellable' => true, 'can_sell' => true]);
    $this->admin->stockLocations()->attach($this->location->id, ['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'can_sell' => true]);
    expect($this->admin->canAccessBranch($branchB->id))->toBeTrue()
        ->and(AuthorizationScope::scopeFor($this->admin, 'report_scope', 'branch'))->toBe('company')
        ->and(collect(InventorySettings::allowedSaleLocationsForUser($this->admin, $branchB->id))->pluck('id'))->toContain($location->id)
        ->and(InventorySettings::canUserSellFromLocation($this->admin, $location))->toBeTrue();
    Volt::test('pos.index')->set('branch_id', (string) $branchB->id)->assertSee('Authorized Second Branch');
    expect(fn () => $this->admin->update(['company_id' => $this->company->id + 10000]))->toThrow(ValidationException::class);
});

test('POS date defaults to company today persists during cart edits and resets on demand', function () {
    $today = $this->clock->today($this->company->id);
    $yesterday = now($this->company->timezone)->subDay()->toDateString();
    Volt::test('pos.index')->assertSet('sale_date', $today)->set('sale_date', $yesterday)
        ->call('addProduct', $this->product->id)->assertSet('sale_date', $yesterday)
        ->set('search', 'changed search')->assertSet('sale_date', $yesterday)
        ->call('resetSaleDate')->assertSet('sale_date', $today);
});

test('historical sale preserves entry stock and payment timestamps and is idempotent', function () {
    $this->travelTo(now()->setTime(12, 0));
    $date = now($this->company->timezone)->subDay()->toDateString();
    $today = $this->clock->today($this->company->id);
    $key = (string) str()->uuid();
    $sale = datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true], key: $key);
    expect($sale->sale_date->toDateString())->toBe($date)
        ->and($sale->created_at->timestamp)->toBe(now()->timestamp)
        ->and($sale->payments->first()->payment_date->toDateString())->toBe($today);
    $movement = StockMovement::withoutGlobalScopes()->where('reference_type', Sale::class)->where('reference_id', $sale->id)->firstOrFail();
    expect($movement->movement_date->toDateString())->toBe($today)->and($movement->created_at->timestamp)->toBe(now()->timestamp);
    expect(datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true], key: $key)->id)->toBe($sale->id);
    expect(StockMovement::withoutGlobalScopes()->where('reference_type', Sale::class)->where('reference_id', $sale->id)->count())->toBe(1);
    expect(app(FinancialReportService::class)->profitLoss($this->branch->id, $date, $date)['revenue'])->toEqual(100);
    expect(app(FinancialReportService::class)->profitLoss($this->branch->id, $today, $today)['revenue'])->toEqual(0);
    expect(app(CashbookService::class)->totals($this->branch->id, $today)['cash_sales'])->toEqual(100);
    $request = Request::create('/exports', 'GET', ['date_from' => $date, 'date_to' => $date]);
    $request->setUserResolver(fn () => $this->admin);
    $report = app(ReportExportService::class)->build('tables.sales', $request);
    expect($report['headers'])->toContain('Recorded at');
    $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Sale Date')->assertSee($sale->sale_date->format('d M Y'))->assertSee('Recorded at');
    $customer = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $sale->update(['customer_id' => $customer->id]);
    $invoice = SalesInvoice::create(['company_id' => $this->company->id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'invoice_number' => 'BACKDATE-INVOICE', 'source_type' => 'direct_sale']);
    $html = view('pdf.b2b-sales-invoice', ['invoice' => $invoice->load(['sale.items.product', 'sale.payments', 'company', 'customer', 'sale.branch', 'sale.createdBy'])])->render();
    expect($html)->toContain($sale->sale_date->format('d M Y'), 'Recorded at');
    $message = app(WhatsAppMessageFactory::class)->saleCompleted($sale);
    expect($message)->toContain('Recorded at:', 'Notification prepared at:', $sale->sale_date->format('d M Y'));
});

test('future malformed overly old and unconfirmed dates are rejected before stock changes', function (string $case) {
    $today = now($this->company->timezone);
    $date = match ($case) {
        'future' => $today->addDay()->toDateString(), 'invalid' => '2026-02-30', 'old' => $today->subDays(31)->toDateString(), default => $today->subDay()->toDateString()
    };
    $before = StockMovement::count();
    expect(fn () => datedPosSale(['sale_date' => $date, 'backdate_confirmed' => $case !== 'unconfirmed']))->toThrow(ValidationException::class);
    expect(Sale::count())->toBe(0)->and(StockMovement::count())->toBe($before);
})->with(['future', 'invalid', 'old', 'unconfirmed']);

test('cashier backdating requires an explicit grant and still honors the configured limit', function () {
    $this->admin->syncRoles(['Cashier']);
    $this->admin->unsetRelation('roles');
    $date = now($this->company->timezone)->subDay()->toDateString();
    expect(fn () => datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true]))->toThrow(ValidationException::class);
    $this->admin->givePermissionTo('sales.backdate');
    expect(datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true])->sale_date->toDateString())->toBe($date);
    config(['pos.max_backdate_days' => 0]);
    expect(fn () => datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true]))->toThrow(ValidationException::class);
});

test('company timezone determines default and future dates across UTC midnight', function () {
    $this->travelTo(Carbon::parse('2026-10-15 23:30:00', 'UTC'));
    $this->company->update(['timezone' => 'Asia/Tokyo']);
    expect($this->clock->today($this->company->id))->toBe('2026-10-16');
    expect(datedPosSale()->sale_date->toDateString())->toBe('2026-10-16');
    $this->company->update(['timezone' => 'America/Los_Angeles']);
    expect($this->clock->today($this->company->id))->toBe('2026-10-15');
    $this->company->update(['timezone' => '']);
    expect($this->clock->today($this->company->id))->toBe(now(config('app.timezone'))->toDateString());
});

test('closed historical or recording cashbook dates cannot be bypassed', function (bool $historical) {
    $date = now($this->company->timezone)->subDay()->toDateString();
    CashbookSession::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'session_date' => $historical ? $date : $this->clock->today($this->company->id), 'opening_cash' => 0, 'expected_cash' => 0, 'status' => 'closed', 'opened_by' => $this->admin->id, 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    expect(fn () => datedPosSale(['sale_date' => $date, 'backdate_confirmed' => true]))->toThrow(ValidationException::class);
    expect(Sale::count())->toBe(0);
})->with([true, false]);

test('a historical sale still enforces current stock availability', function () {
    expect(fn () => datedPosSale(['sale_date' => now($this->company->timezone)->subDay()->toDateString(), 'backdate_confirmed' => true], 100000))->toThrow(ValidationException::class);
    expect(Sale::count())->toBe(0);
});
