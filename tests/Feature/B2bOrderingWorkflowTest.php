<?php

use App\Models\AdditionalChargeType;
use App\Models\B2bDocumentEvent;
use App\Models\Branch;
use App\Models\CommercialConfigurationEvent;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPurchaseRequest;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalesInvoice;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Services\B2bQuotationService;
use App\Services\CustomerPurchaseRequestService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();
    Storage::fake('local');
    Http::fake();
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->company = $this->branch->company;
    $this->location = app(InventoryService::class)->getDispensingLocation($this->branch->id);
    $this->location->update(['status' => 'active', 'is_active' => true, 'can_sell' => true, 'is_sellable' => true]);
    $this->product = Product::query()->where('status', 'active')->firstOrFail();
    $this->product->update(['buying_price' => 10, 'selling_price' => 20, 'allow_fractional_sale' => true, 'minimum_sale_quantity' => 0.01, 'quantity_step' => 0.01]);
    $trip = Unit::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'measurement_type_id' => $this->product->unit?->measurement_type_id,
        'name' => 'B2B Trip', 'short_name' => 'trip-b2b', 'status' => 'active',
    ]);
    $this->conversion = ProductUnitConversion::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'product_id' => $this->product->id, 'unit_id' => $trip->id,
        'conversion_factor' => 100, 'retail_price' => 2000, 'can_sell' => true, 'can_purchase' => false, 'active' => true,
    ]);
    StockMovement::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->location->id,
        'movement_type' => 'direct_stock_in', 'quantity' => 1000, 'quantity_in' => 1000, 'quantity_out' => 0,
        'unit_cost' => 10, 'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
    $this->customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'ABC Contractors Ltd',
        'phone' => '255764123490', 'email' => 'abc@example.test', 'address' => 'Dar es Salaam',
        'customer_type' => 'credit', 'credit_limit' => 10000000, 'opening_balance' => 0, 'balance_amount' => 0, 'status' => 'active',
    ]);
    $this->account = CustomerAccount::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
        'name' => $this->customer->name, 'phone' => $this->customer->phone, 'email' => 'portal-abc@example.test',
        'password' => 'password', 'status' => 'active',
    ]);
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $this->company->id], [
        'enabled' => true, 'sending_paused' => false, 'device_id' => 'b2b-device', 'last_device_state' => 'logged_in',
        'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);
    WhatsAppRecipient::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'B2B Manager',
        'phone' => '255764123491', 'scope' => 'branch', 'active' => true,
        'categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);
    $this->actingAs($this->admin);
});

function submitB2bRequest(object $test, ?string $key = null): CustomerPurchaseRequest
{
    return app(CustomerPurchaseRequestService::class)->submit(
        $test->account, $test->branch->id, 'Deliver to project site',
        [
            ['product_id' => $test->product->id, 'product_unit_conversion_id' => $test->conversion->id, 'quantity' => 2, 'notes' => 'Trip load'],
            ['product_id' => $test->product->id, 'product_unit_conversion_id' => null, 'quantity' => 5, 'notes' => 'Loose units'],
        ],
        $key ?: (string) str()->uuid(),
    );
}

function quoteB2bRequest(object $test, CustomerPurchaseRequest $request, array $overrides = []): Quotation
{
    $lines = $request->items->map(fn ($item) => [
        'request_item_id' => $item->id, 'quantity' => (float) $item->transaction_quantity,
        'unit_price' => (float) $item->display_unit_price_snapshot, 'discount_per_unit' => 0, 'tax_amount' => 0,
    ])->all();

    return app(B2bQuotationService::class)->createFromRequest(
        $request, $test->admin, $overrides['lines'] ?? $lines, $overrides['document_type'] ?? 'quotation',
        $overrides['valid_until'] ?? today()->addDays(7), 'Customer-facing note', 'Valid subject to stock availability.',
    );
}

function acceptedStaffQuotationForStockUx(object $test): Quotation
{
    $service = app(B2bQuotationService::class);
    $quotation = $service->createDirect($test->customer, $test->admin, $test->branch->id, [[
        'product_id' => $test->product->id,
        'product_unit_conversion_id' => $test->conversion->id,
        'quantity' => 2,
    ]], 'quotation', today()->addWeek(), (string) str()->uuid());

    return $service->markAcceptedOffline(
        $service->send($quotation, $test->admin),
        $test->admin,
        'Customer confirmed for stock UX testing.',
    );
}

function stockUxLocation(object $test, string $name, float $quantity): StockLocation
{
    $location = StockLocation::withoutGlobalScopes()->create([
        'company_id' => $test->company->id,
        'branch_id' => $test->branch->id,
        'name' => $name,
        'code' => 'UX-'.str()->upper(str()->random(8)),
        'type' => 'showroom',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'is_sellable' => true,
        'can_issue_stock' => true,
    ]);
    if ($quantity > 0) {
        StockMovement::withoutGlobalScopes()->create([
            'company_id' => $test->company->id,
            'branch_id' => $test->branch->id,
            'product_id' => $test->product->id,
            'stock_location_id' => $location->id,
            'movement_type' => 'direct_stock_in',
            'quantity' => $quantity,
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'unit_cost' => 10,
            'created_by' => $test->admin->id,
            'movement_date' => today(),
        ]);
    }

    return $location;
}

function conversionButtonTag(string $html): string
{
    preg_match('/<button[^>]*data-testid="convert-final-sale"[^>]*>/s', $html, $matches);

    return $matches[0] ?? '';
}

test('customer request snapshots base and conversion units without moving stock or creating a sale', function () {
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $key = (string) str()->uuid();
    $request = submitB2bRequest($this, $key);
    $duplicate = submitB2bRequest($this, $key);

    expect($request->id)->toBe($duplicate->id)
        ->and($request->items)->toHaveCount(2)
        ->and((float) $request->items[0]->transaction_quantity)->toBe(2.0)
        ->and((float) $request->items[0]->conversion_factor_snapshot)->toBe(100.0)
        ->and((float) $request->items[0]->base_quantity)->toBe(200.0)
        ->and((float) $request->items[1]->base_quantity)->toBe(5.0)
        ->and(Sale::withoutGlobalScopes()->where('company_id', $this->company->id)->where('notes', 'like', 'Converted from%')->count())->toBe(0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_purchase_request_submitted')->count())->toBe(1);
    Http::assertNothingSent();
});

test('request rejects cross-company products and inactive conversions', function () {
    $otherCompany = Company::query()->create(['company_name' => 'Other Secret Hardware', 'business_type' => 'hardware', 'phone' => '255700999001', 'whatsapp_number' => '255700999001']);
    $otherProduct = $this->product->replicate();
    $otherProduct->company_id = $otherCompany->id;
    $otherProduct->sku = 'OTHER-B2B';
    $otherProduct->save();
    app(CustomerPurchaseRequestService::class)->submit($this->account, $this->branch->id, null, [['product_id' => $otherProduct->id, 'quantity' => 1]], (string) str()->uuid());
})->throws(ValidationException::class, 'active product belonging');

test('request reloads and rejects an inactive sell conversion', function () {
    $this->conversion->update(['active' => false]);
    app(CustomerPurchaseRequestService::class)->submit(
        $this->account, $this->branch->id, null,
        [['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 1]],
        (string) str()->uuid(),
    );
})->throws(ValidationException::class, 'inactive or not allowed');

test('quotation price overrides require explicit selling price permission', function () {
    $request = submitB2bRequest($this);
    $reviewer = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $reviewer->givePermissionTo('customer_requests.create_quotation');
    $lines = $request->items->map(fn ($item) => [
        'request_item_id' => $item->id, 'quantity' => (float) $item->transaction_quantity,
        'unit_price' => (float) $item->display_unit_price_snapshot + 1, 'discount_per_unit' => 0, 'tax_amount' => 0,
    ])->all();
    app(B2bQuotationService::class)->createFromRequest($request, $reviewer, $lines, 'quotation', today()->addWeek());
})->throws(AuthorizationException::class, 'override selling prices');

test('quotation is adjustable immutable and queues its branded pdf without moving stock', function () {
    $request = submitB2bRequest($this);
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $lines = $request->items->map(fn ($item) => [
        'request_item_id' => $item->id, 'quantity' => 1, 'unit_price' => (float) $item->display_unit_price_snapshot,
        'discount_per_unit' => 0, 'tax_amount' => 0,
    ])->all();
    $quotation = quoteB2bRequest($this, $request, ['lines' => $lines]);
    $snapshotPrice = (float) $quotation->items[0]->unit_price;
    $this->conversion->update(['retail_price' => 9999, 'conversion_factor' => 150]);
    $this->product->update(['name' => 'Renamed After Quote', 'selling_price' => 999]);
    $quotation->refresh()->load('items');

    expect((float) $quotation->items[0]->unit_price)->toBe($snapshotPrice)
        ->and($quotation->items[0]->product_name_snapshot)->not->toBe('Renamed After Quote')
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before);

    $quotation = app(B2bQuotationService::class)->send($quotation, $this->admin);
    Storage::disk('local')->assertExists($quotation->pdf_path);
    $html = view('pdf.b2b-quotation', ['quotation' => $quotation->load(['company', 'customer', 'creator', 'items'])])->render();
    expect($html)->toContain($quotation->quotation_number)->toContain('ABC Contractors Ltd')
        ->not->toContain('Buying Price')->not->toContain('COGS')->not->toContain('Profit')
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'quotation_sent')->value('attachment_type'))->toBe('file');
    Http::assertNothingSent();
});

test('customer decisions are scoped idempotent and expired quotations cannot be accepted', function () {
    $quotation = app(B2bQuotationService::class)->send(quoteB2bRequest($this, submitB2bRequest($this)), $this->admin);
    $otherCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Other Customer',
        'phone' => '255700999002', 'customer_type' => 'credit', 'opening_balance' => 0, 'balance_amount' => 0, 'status' => 'active',
    ]);
    $otherAccount = CustomerAccount::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'customer_id' => $otherCustomer->id, 'name' => 'Other',
        'phone' => '255700999002', 'email' => 'other-b2b@example.test', 'password' => 'password', 'status' => 'active',
    ]);
    expect(fn () => app(B2bQuotationService::class)->accept($quotation, $otherAccount))->toThrow(AuthorizationException::class);
    $accepted = app(B2bQuotationService::class)->accept($quotation, $this->account);
    $again = app(B2bQuotationService::class)->accept($accepted, $this->account);
    expect($accepted->status)->toBe('accepted')->and($again->accepted_at->toISOString())->toBe($accepted->accepted_at->toISOString())
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'quotation_accepted')->count())->toBe(1);

    $expired = quoteB2bRequest($this, submitB2bRequest($this));
    $expired->update(['status' => 'sent', 'valid_until' => today()->subDay()]);
    app(B2bQuotationService::class)->accept($expired->refresh(), $this->account);
})->throws(ValidationException::class, 'expired');

test('customer can reject a quotation idempotently with an audit reason', function () {
    $quotation = app(B2bQuotationService::class)->send(quoteB2bRequest($this, submitB2bRequest($this)), $this->admin);
    $rejected = app(B2bQuotationService::class)->reject($quotation, $this->account, 'Delivery date is too late.');
    $again = app(B2bQuotationService::class)->reject($rejected, $this->account, 'Ignored duplicate reason');
    expect($again->status)->toBe('rejected')
        ->and($again->rejection_reason)->toBe('Delivery date is too late.')
        ->and($again->rejected_at->toISOString())->toBe($rejected->rejected_at->toISOString());
});

test('accepted quotation converts once using snapshot price and quantity then queues final invoice', function () {
    $request = submitB2bRequest($this);
    $quotation = app(B2bQuotationService::class)->send(quoteB2bRequest($this, $request), $this->admin);
    $quotation = app(B2bQuotationService::class)->accept($quotation, $this->account);
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $this->conversion->update(['retail_price' => 9000]);
    $sale = app(B2bQuotationService::class)->convertToSale($quotation, $this->admin, $this->location->id, [['payment_method' => 'cash', 'amount' => $quotation->total_amount]]);
    $again = app(B2bQuotationService::class)->convertToSale($quotation->refresh(), $this->admin, $this->location->id, [['payment_method' => 'cash', 'amount' => $quotation->total_amount]]);
    $expectedBase = $quotation->items->sum(fn ($item) => (float) $item->base_quantity);
    $invoice = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();

    expect($again->id)->toBe($sale->id)
        ->and(Sale::withoutGlobalScopes()->where('idempotency_key', 'quotation:'.$quotation->id.':converted')->count())->toBe(1)
        ->and((float) $sale->items->first()->unit_price)->toBe((float) $quotation->items->first()->unit_price)
        ->and((float) $sale->items->first()->base_quantity)->toBe((float) $quotation->items->first()->base_quantity)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - $expectedBase)
        ->and($invoice->invoice_number)->toStartWith('INV-')
        ->and($invoice->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($invoice->pdf_path);
    $invoiceHtml = view('pdf.b2b-sales-invoice', ['invoice' => $invoice->load(['company', 'customer', 'sale.branch', 'sale.createdBy', 'sale.payments', 'sale.items.product'])])->render();
    expect($invoiceHtml)->toContain($invoice->invoice_number)->toContain('Paid')->toContain('Balance')
        ->not->toContain('Buying Price')->not->toContain('COGS')->not->toContain('Profit')
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count())->toBe(1);

    $this->actingAs($this->account, 'customer')
        ->get(route('customer.invoices.pdf', $invoice))
        ->assertOk();
});

test('insufficient stock blocks conversion without sale invoice or negative stock', function () {
    $quotation = app(B2bQuotationService::class)->send(quoteB2bRequest($this, submitB2bRequest($this)), $this->admin);
    $quotation = app(B2bQuotationService::class)->accept($quotation, $this->account);
    StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->where('product_id', $this->product->id)->update(['quantity' => 0, 'quantity_in' => 0, 'quantity_out' => 0]);
    $movementCount = StockMovement::withoutGlobalScopes()->count();
    $stockBefore = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    try {
        app(B2bQuotationService::class)->convertToSale($quotation, $this->admin, $this->location->id, [['payment_method' => 'credit', 'amount' => $quotation->total_amount]]);
        $this->fail('Expected insufficient stock to prevent conversion.');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->toContain('Shortage');
    }

    expect(Sale::withoutGlobalScopes()->where('idempotency_key', 'quotation:'.$quotation->id.':converted')->count())->toBe(0)
        ->and(SalesInvoice::withoutGlobalScopes()->count())->toBe(0)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($movementCount)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count())->toBe(0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($stockBefore)
        ->and($quotation->refresh()->status)->toBe('accepted');
});

test('portal screens and downloads remain customer scoped', function () {
    $request = submitB2bRequest($this);
    $quotation = app(B2bQuotationService::class)->send(quoteB2bRequest($this, $request), $this->admin);
    $this->actingAs($this->account, 'customer')->get(route('customer.purchase-requests.index'))->assertOk()->assertSee($request->request_number);
    $this->actingAs($this->account, 'customer')->get(route('customer.quotations.show', $quotation))->assertOk()->assertSee($quotation->quotation_number);

    $otherCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Portal Not Owner',
        'phone' => '255700999003', 'customer_type' => 'credit', 'opening_balance' => 0, 'balance_amount' => 0, 'status' => 'active',
    ]);
    $other = CustomerAccount::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'customer_id' => $otherCustomer->id,
        'name' => 'Not Owner', 'phone' => '255700999003', 'email' => 'not-owner@example.test', 'password' => 'password', 'status' => 'active',
    ]);
    $this->actingAs($other, 'customer')->get(route('customer.quotations.show', $quotation))->assertForbidden();
    $this->actingAs($other, 'customer')->get(route('customer.quotations.pdf', $quotation))->assertForbidden();

    $accepted = app(B2bQuotationService::class)->accept($quotation, $this->account);
    $invoiceSale = app(B2bQuotationService::class)->convertToSale($accepted, $this->admin, $this->location->id, [['payment_method' => 'cash', 'amount' => $accepted->total_amount]]);
    $invoice = SalesInvoice::withoutGlobalScopes()->where('sale_id', $invoiceSale->id)->firstOrFail();
    $this->actingAs($other, 'customer')->get(route('customer.invoices.pdf', $invoice))->assertForbidden();
});

test('staff creates an idempotent direct quotation or proforma without reserving stock', function () {
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $key = (string) str()->uuid();
    $lines = [['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 2]];
    $service = app(B2bQuotationService::class);
    $quotation = $service->createDirect($this->customer, $this->admin, $this->branch->id, $lines, 'proforma', today()->addWeek(), $key);
    $duplicate = $service->createDirect($this->customer, $this->admin, $this->branch->id, $lines, 'proforma', today()->addWeek(), $key);

    expect($duplicate->id)->toBe($quotation->id)
        ->and($quotation->customer_purchase_request_id)->toBeNull()
        ->and($quotation->source_type)->toBe('staff_created')
        ->and($quotation->document_type)->toBe('proforma')
        ->and((float) $quotation->items->first()->base_quantity)->toBe(200.0)
        ->and((float) $quotation->total_amount)->toBe(4000.0)
        ->and(Quotation::withoutGlobalScopes()->where('creation_key', $key)->count())->toBe(1)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before)
        ->and(B2bDocumentEvent::withoutGlobalScopes()->where('document_id', $quotation->id)->where('event', 'created_by_staff')->exists())->toBeTrue();

    $service->send($quotation, $this->admin);
    $this->actingAs($this->account, 'customer')->get(route('customer.quotations.index'))->assertOk()->assertSee($quotation->quotation_number)->assertSee('Prepared by Staff');
});

test('staff records offline acceptance and converts a direct quotation exactly once from snapshots', function () {
    $service = app(B2bQuotationService::class);
    $quotation = $service->createDirect($this->customer, $this->admin, $this->branch->id, [
        ['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 2],
    ], 'quotation', today()->addWeek(), (string) str()->uuid());
    $quotation = $service->send($quotation, $this->admin);
    $quotation = $service->markAcceptedOffline($quotation, $this->admin, 'Customer confirmed by telephone.');
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $this->conversion->update(['conversion_factor' => 250, 'retail_price' => 9000]);
    $sale = $service->convertToSale($quotation, $this->admin, $this->location->id, [['payment_method' => 'credit', 'amount' => $quotation->total_amount]]);
    $again = $service->convertToSale($quotation->refresh(), $this->admin, $this->location->id, [['payment_method' => 'credit', 'amount' => $quotation->total_amount]]);

    expect($again->id)->toBe($sale->id)
        ->and((float) $sale->items->first()->base_quantity)->toBe(200.0)
        ->and((float) $sale->items->first()->unit_price)->toBe(2000.0)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - 200)
        ->and(SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->where('source_type', 'quotation')->count())->toBe(1)
        ->and(B2bDocumentEvent::withoutGlobalScopes()->where('document_id', $quotation->id)->where('event', 'accepted_offline')->exists())->toBeTrue();
});

test('direct documents enforce price permission and active sellable unit conversions', function () {
    $staff = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $staff->givePermissionTo('quotations.create');
    app(B2bQuotationService::class)->createDirect($this->customer, $staff, $this->branch->id, [[
        'product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id,
        'quantity' => 1, 'unit_price' => 1999,
    ]], 'quotation', today()->addWeek(), (string) str()->uuid());
})->throws(AuthorizationException::class, 'override selling prices');

test('authorized staff price override and portal acceptance work for a direct quotation', function () {
    $quotation = app(B2bQuotationService::class)->createDirect($this->customer, $this->admin, $this->branch->id, [[
        'product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id,
        'quantity' => 1, 'unit_price' => 2100,
    ]], 'quotation', today()->addWeek(), (string) str()->uuid());
    $quotation = app(B2bQuotationService::class)->send($quotation, $this->admin);
    $accepted = app(B2bQuotationService::class)->accept($quotation, $this->account);

    expect((float) $accepted->items->first()->unit_price)->toBe(2100.0)
        ->and($accepted->status)->toBe('accepted')
        ->and($accepted->customer_purchase_request_id)->toBeNull();
});

test('direct document creation blocks a customer and product outside the staff company', function () {
    $otherCompany = Company::query()->create(['company_name' => 'Outside Company', 'business_type' => 'hardware', 'phone' => '255700111222', 'whatsapp_number' => '255700111222']);
    $otherCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id, 'name' => 'Outside Customer', 'phone' => '255700111223',
        'customer_type' => 'cash', 'opening_balance' => 0, 'balance_amount' => 0, 'status' => 'active',
    ]);

    app(B2bQuotationService::class)->createDirect($otherCustomer, $this->admin, $this->branch->id, [[
        'product_id' => $this->product->id, 'quantity' => 1,
    ]], 'quotation', today()->addWeek(), (string) str()->uuid());
})->throws(AuthorizationException::class);

test('direct final sale is idempotent and failure creates no invoice or notification', function () {
    $service = app(B2bQuotationService::class);
    $key = (string) str()->uuid();
    $lines = [['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 2]];
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $sale = $service->createDirectSale($this->customer, $this->admin, $this->branch->id, $this->location->id, $lines, [['payment_method' => 'cash', 'amount' => 4000]], $key);
    $again = $service->createDirectSale($this->customer, $this->admin, $this->branch->id, $this->location->id, $lines, [['payment_method' => 'cash', 'amount' => 4000]], $key);
    $invoice = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();

    expect($again->id)->toBe($sale->id)
        ->and(Sale::withoutGlobalScopes()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->count())->toBe(1)
        ->and($invoice->source_type)->toBe('direct_sale')
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - 200)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count())->toBe(1);

    StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->where('product_id', $this->product->id)->update(['quantity' => 0, 'quantity_in' => 0, 'quantity_out' => 0]);
    $invoiceCount = SalesInvoice::withoutGlobalScopes()->count();
    $messageCount = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count();
    try {
        $service->createDirectSale($this->customer, $this->admin, $this->branch->id, $this->location->id, [['product_id' => $this->product->id, 'quantity' => 1]], [['payment_method' => 'cash', 'amount' => 20]], (string) str()->uuid());
        $this->fail('Expected insufficient stock.');
    } catch (ValidationException) {
    }
    expect(SalesInvoice::withoutGlobalScopes()->count())->toBe($invoiceCount)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count())->toBe($messageCount);
});

test('staff commercial entry points render and remain company scoped', function () {
    $quotation = app(B2bQuotationService::class)->createDirect($this->customer, $this->admin, $this->branch->id, [[
        'product_id' => $this->product->id, 'quantity' => 1,
    ]], 'quotation', today()->addWeek(), (string) str()->uuid());

    $this->get(route('quotations.index'))->assertOk()->assertSee($quotation->quotation_number);
    $this->get(route('quotations.create', ['customer' => $this->customer->id]))->assertOk()->assertSee('New Quotation');
    $this->get(route('quotations.show', $quotation))->assertOk()->assertSee($quotation->quotation_number)->assertSee('Created by Staff');
    $this->get(route('direct-sales.create', ['customer' => $this->customer->id]))->assertOk()->assertSee('New Customer Sale');
    $this->get(route('invoices.index'))->assertOk()->assertSee('Customer Invoices');
    $this->get(route('customers.edit', $this->customer))->assertOk()->assertSee('Commercial Actions');
});

test('accepted quotation disables conversion and explains a selected location shortage', function () {
    $quotation = acceptedStaffQuotationForStockUx($this);
    $insufficient = stockUxLocation($this, 'Empty UX Location', 0);
    $component = Volt::test('quotations.show', ['quotation' => $quotation])
        ->set('stock_location_id', (string) $insufficient->id)
        ->assertSee('Cannot convert this quotation to a sale because the selected stock location has insufficient stock.')
        ->assertSeeHtml('data-testid="conversion-stock-warning"');

    expect($component->get('availability')[0])
        ->toMatchArray(['required' => 200.0, 'available' => 0.0, 'shortage' => 200.0])
        ->and(conversionButtonTag($component->html()))->toContain('disabled');
});

test('accepted quotation enables conversion when every item has sufficient stock', function () {
    $quotation = acceptedStaffQuotationForStockUx($this);
    $component = Volt::test('quotations.show', ['quotation' => $quotation])
        ->set('stock_location_id', (string) $this->location->id)
        ->assertDontSee('Cannot convert this quotation to a sale because the selected stock location has insufficient stock.');

    expect((float) $component->get('availability')[0]['shortage'])->toBe(0.0)
        ->and(conversionButtonTag($component->html()))->not->toMatch('/\sdisabled(?:=|\s|>)/');
});

test('changing selling location reactively toggles availability and conversion eligibility', function () {
    $quotation = acceptedStaffQuotationForStockUx($this);
    $insufficient = stockUxLocation($this, 'Reactive Empty Location', 0);
    $sufficient = stockUxLocation($this, 'Reactive Full Location', 250);
    $component = Volt::test('quotations.show', ['quotation' => $quotation]);

    $component->set('stock_location_id', (string) $insufficient->id);
    expect($component->get('availability')[0])->toMatchArray(['available' => 0.0, 'shortage' => 200.0])
        ->and(conversionButtonTag($component->html()))->toContain('disabled');

    $component->set('stock_location_id', (string) $sufficient->id);
    expect($component->get('availability')[0])->toMatchArray(['available' => 250.0, 'shortage' => 0.0])
        ->and(conversionButtonTag($component->html()))->not->toMatch('/\sdisabled(?:=|\s|>)/');

    $component->set('stock_location_id', (string) $insufficient->id);
    expect($component->get('availability')[0]['shortage'])->toBe(200.0)
        ->and(conversionButtonTag($component->html()))->toContain('disabled');
});

test('company can configure document-specific payment instructions without recording payments', function () {
    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 20,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 5, 'reference_number' => 'REAL-PAYMENT']],
        $this->customer->id,
        $this->location->id,
        $this->branch->id,
        $this->admin->id,
    );
    $saleState = $sale->only(['paid_amount', 'balance_amount', 'payment_status']);
    $beforePayments = SalePayment::withoutGlobalScopes()->count();
    Volt::test('settings.commercial-documents')
        ->set('payment_type', 'bank')
        ->set('display_name', 'HARDEX CRDB Account')
        ->set('bank_name', 'CRDB Bank')
        ->set('account_name', 'HARDEX Limited')
        ->set('account_number', '0150123456789')
        ->set('show_on_quotation', true)
        ->set('show_on_proforma', false)
        ->set('show_on_invoice', true)
        ->call('savePayment')
        ->assertHasNoErrors();

    $method = CompanyPaymentMethod::withoutGlobalScopes()->where('display_name', 'HARDEX CRDB Account')->firstOrFail();
    $mobile = CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'type' => 'mobile_money', 'display_name' => 'HARDEX M-Pesa',
        'phone_or_business_number' => '123456', 'is_active' => true, 'sort_order' => 2,
        'show_on_quotation' => true, 'show_on_proforma' => true, 'show_on_invoice' => false,
    ]);
    $inactive = CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'type' => 'other', 'display_name' => 'Inactive Internal Method',
        'instructions' => 'NEVER-EXPOSE-INACTIVE', 'is_active' => false,
        'show_on_quotation' => true, 'show_on_proforma' => true, 'show_on_invoice' => true,
    ]);
    $otherCompany = Company::query()->create([
        'company_name' => 'Other Payment Company', 'business_type' => 'hardware',
        'phone' => '255700999222', 'whatsapp_number' => '255700999222',
    ]);
    $outside = CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id, 'type' => 'bank', 'display_name' => 'Other Company Secret Bank',
        'account_number' => 'NEVER-EXPOSE-OTHER-COMPANY', 'is_active' => true,
        'show_on_quotation' => true, 'show_on_proforma' => true, 'show_on_invoice' => true,
    ]);
    $quotationMethods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->forDocument('quotation')->get();
    $proformaMethods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->forDocument('proforma')->get();
    $invoiceMethods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->forDocument('invoice')->get();
    $invoice = SalesInvoice::withoutGlobalScopes()->create([
        'sale_id' => $sale->id, 'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
        'source_type' => 'direct_sale', 'invoice_number' => 'INV-PAYMENT-INSTRUCTIONS',
    ]);
    $invoiceHtml = view('pdf.b2b-sales-invoice', [
        'invoice' => $invoice->load(['company', 'customer', 'sale.branch', 'sale.createdBy', 'sale.payments', 'sale.items.product', 'sale.additionalCharges']),
        'paymentMethods' => $invoiceMethods,
    ])->render();

    expect($quotationMethods->pluck('id'))->toContain($method->id, $mobile->id)
        ->not->toContain($inactive->id, $outside->id)
        ->and($proformaMethods->pluck('id'))->toContain($mobile->id)
        ->not->toContain($method->id, $inactive->id, $outside->id)
        ->and($invoiceMethods->pluck('id'))->toContain($method->id)
        ->not->toContain($mobile->id, $inactive->id, $outside->id)
        ->and(SalePayment::withoutGlobalScopes()->count())->toBe($beforePayments)
        ->and($sale->refresh()->only(['paid_amount', 'balance_amount', 'payment_status']))->toBe($saleState)
        ->and($invoiceHtml)->toContain('HARDEX CRDB Account')->toContain('INV-PAYMENT-INSTRUCTIONS')->toContain('Paid')->toContain('Balance')
        ->not->toContain('HARDEX M-Pesa')->not->toContain('Inactive Internal Method')->not->toContain('Other Company Secret Bank')
        ->not->toContain('Buying Price')->not->toContain('COGS')->not->toContain('Average Cost')->not->toContain('Profit')
        ->and(CommercialConfigurationEvent::withoutGlobalScopes()->where('subject_id', $method->id)->where('event', 'created')->exists())->toBeTrue();
});

test('quotation additional charges are non-inventory snapshots included in totals and customer documents', function () {
    $transport = AdditionalChargeType::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Transport to Site', 'description' => 'Delivery charge', 'is_active' => true, 'sort_order' => 1,
    ]);
    $delivery = AdditionalChargeType::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Delivery Coordination', 'description' => 'Site contact coordination', 'is_active' => true, 'sort_order' => 2,
    ]);
    CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'type' => 'mobile_money', 'display_name' => 'M-Pesa Business',
        'provider' => 'Vodacom', 'phone_or_business_number' => '123456', 'is_active' => true,
        'show_on_quotation' => true, 'show_on_proforma' => false, 'show_on_invoice' => true,
    ]);
    $beforeStock = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $beforeMovements = StockMovement::withoutGlobalScopes()->count();
    $beforeSaleItems = SaleItem::withoutGlobalScopes()->count();
    $quotation = app(B2bQuotationService::class)->createDirect(
        $this->customer, $this->admin, $this->branch->id,
        [['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 2]],
        'quotation', today()->addWeek(), (string) str()->uuid(), additionalCharges: [
            ['additional_charge_type_id' => $transport->id, 'amount' => 350, 'description' => 'Project delivery'],
            ['additional_charge_type_id' => $delivery->id, 'amount' => 75, 'description' => 'Call site foreman'],
        ],
    );

    expect((float) $quotation->subtotal)->toBe(4000.0)
        ->and((float) $quotation->additional_charge_amount)->toBe(425.0)
        ->and((float) $quotation->total_amount)->toBe(4425.0)
        ->and((float) $quotation->total_amount)->toBe((float) $quotation->subtotal - (float) $quotation->discount_amount + (float) $quotation->tax_amount + (float) $quotation->additionalCharges->sum('amount'))
        ->and($quotation->additionalCharges)->toHaveCount(2)
        ->and($quotation->additionalCharges->first()->charge_name_snapshot)->toBe('Transport to Site')
        ->and($quotation->additionalCharges->pluck('description_snapshot')->all())->toBe(['Project delivery', 'Call site foreman'])
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($beforeStock)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($beforeMovements)
        ->and(SaleItem::withoutGlobalScopes()->count())->toBe($beforeSaleItems)
        ->and(Sale::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0);

    $html = view('pdf.b2b-quotation', [
        'quotation' => $quotation->load(['company', 'customer', 'creator', 'items', 'additionalCharges']),
        'paymentMethods' => CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->forDocument('quotation')->get(),
    ])->render();
    expect($html)->toContain('Transport to Site')->toContain('Project delivery')->toContain('Delivery Coordination')->toContain('Call site foreman')
        ->toContain('M-Pesa Business')->toContain($quotation->quotation_number)->toContain('Grand Total')->toContain('4,425')
        ->not->toContain('Buying Price')->not->toContain('COGS');

    $sent = app(B2bQuotationService::class)->send($quotation, $this->admin);
    $this->actingAs($this->account, 'customer')->get(route('customer.quotations.show', $sent))
        ->assertOk()->assertSee('Transport to Site')->assertSee('Delivery Coordination')->assertSee('M-Pesa Business')->assertSee($sent->quotation_number)
        ->assertDontSee('Buying Price')->assertDontSee('COGS')->assertDontSee('Average Cost')->assertDontSee('Profit');
});

test('proforma renders only allowed instructions with charges and its document number as reference', function () {
    $chargeType = AdditionalChargeType::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Delivery', 'description' => 'Proforma delivery', 'is_active' => true,
    ]);
    CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'type' => 'bank', 'display_name' => 'Proforma Bank',
        'account_number' => 'PROFORMA-ACCOUNT', 'is_active' => true,
        'show_on_quotation' => false, 'show_on_proforma' => true, 'show_on_invoice' => false,
    ]);
    CompanyPaymentMethod::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'type' => 'bank', 'display_name' => 'Quotation Only Bank',
        'account_number' => 'QUOTATION-ONLY', 'is_active' => true,
        'show_on_quotation' => true, 'show_on_proforma' => false, 'show_on_invoice' => false,
    ]);
    $proforma = app(B2bQuotationService::class)->createDirect(
        $this->customer, $this->admin, $this->branch->id,
        [['product_id' => $this->product->id, 'quantity' => 1]],
        'proforma', today()->addWeek(), (string) str()->uuid(), additionalCharges: [
            ['additional_charge_type_id' => $chargeType->id, 'amount' => 80, 'description' => 'Deliver after confirmation'],
        ],
    );
    $paymentMethods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->forDocument('proforma')->get();
    $html = view('pdf.b2b-quotation', [
        'quotation' => $proforma->load(['company', 'customer', 'creator', 'items', 'additionalCharges']),
        'paymentMethods' => $paymentMethods,
    ])->render();

    expect($html)->toContain('PROFORMA INVOICE')->toContain('Delivery')->toContain('Deliver after confirmation')
        ->toContain('Grand Total')->toContain('100')->toContain('Proforma Bank')->toContain('PROFORMA-ACCOUNT')
        ->toContain($proforma->quotation_number)->not->toContain('Quotation Only Bank')->not->toContain('QUOTATION-ONLY')
        ->not->toContain('Buying Price')->not->toContain('COGS')->not->toContain('Average Cost')->not->toContain('Profit');
});

test('conversion preserves charge snapshots and idempotently deducts only product stock', function () {
    $type = AdditionalChargeType::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Offloading', 'description' => 'Original terms', 'is_active' => true,
    ]);
    $service = app(B2bQuotationService::class);
    $quotation = $service->createDirect(
        $this->customer, $this->admin, $this->branch->id,
        [['product_id' => $this->product->id, 'product_unit_conversion_id' => $this->conversion->id, 'quantity' => 2]],
        'quotation', today()->addWeek(), (string) str()->uuid(), additionalCharges: [
            ['additional_charge_type_id' => $type->id, 'amount' => 125],
        ],
    );
    $quotation = $service->markAcceptedOffline($service->send($quotation, $this->admin), $this->admin, 'Confirmed by customer.');
    $type->update(['name' => 'Renamed and Disabled', 'is_active' => false]);
    $before = app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id);
    $sale = $service->convertToSale($quotation, $this->admin, $this->location->id, [['payment_method' => 'credit', 'amount' => 4125]]);
    $again = $service->convertToSale($quotation->refresh(), $this->admin, $this->location->id, [['payment_method' => 'credit', 'amount' => 4125]]);

    expect($again->id)->toBe($sale->id)
        ->and((float) $sale->additional_charge_amount)->toBe(125.0)
        ->and((float) $sale->total_amount)->toBe(4125.0)
        ->and($sale->additionalCharges)->toHaveCount(1)
        ->and($sale->additionalCharges->first()->charge_name_snapshot)->toBe('Offloading')
        ->and($sale->items)->toHaveCount(1)
        ->and(app(InventoryService::class)->getProductStock($this->product->id, $this->location->id, $this->branch->id))->toBe($before - 200)
        ->and(SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->count())->toBe(1)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_sales_invoice')->count())->toBe(1);
});

test('direct sale supports flexible charges while rejecting inactive or cross-company charge types', function () {
    $installation = AdditionalChargeType::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Installation', 'is_active' => true,
    ]);
    $key = (string) str()->uuid();
    $service = app(B2bQuotationService::class);
    $sale = $service->createDirectSale(
        $this->customer, $this->admin, $this->branch->id, $this->location->id,
        [['product_id' => $this->product->id, 'quantity' => 1]],
        [['payment_method' => 'cash', 'amount' => 70]], $key, additionalCharges: [
            ['additional_charge_type_id' => $installation->id, 'amount' => 50],
        ],
    );
    $again = $service->createDirectSale(
        $this->customer, $this->admin, $this->branch->id, $this->location->id,
        [['product_id' => $this->product->id, 'quantity' => 1]],
        [['payment_method' => 'cash', 'amount' => 70]], $key, additionalCharges: [
            ['additional_charge_type_id' => $installation->id, 'amount' => 50],
        ],
    );
    expect($again->id)->toBe($sale->id)
        ->and((float) $sale->total_amount)->toBe(70.0)
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->additionalCharges)->toHaveCount(1);

    $other = Company::query()->create(['company_name' => 'Other Charge Company', 'business_type' => 'hardware', 'phone' => '255700999123', 'whatsapp_number' => '255700999123']);
    $outsideType = AdditionalChargeType::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Secret Fee', 'is_active' => true]);
    expect(fn () => $service->createDirect(
        $this->customer, $this->admin, $this->branch->id, [['product_id' => $this->product->id, 'quantity' => 1]],
        'quotation', today()->addWeek(), (string) str()->uuid(), additionalCharges: [['additional_charge_type_id' => $outsideType->id, 'amount' => 10]],
    ))->toThrow(ValidationException::class);

    $installation->update(['is_active' => false]);
    expect(fn () => $service->createDirect(
        $this->customer, $this->admin, $this->branch->id, [['product_id' => $this->product->id, 'quantity' => 1]],
        'quotation', today()->addWeek(), (string) str()->uuid(), additionalCharges: [['additional_charge_type_id' => $installation->id, 'amount' => 10]],
    ))->toThrow(ValidationException::class);
});
