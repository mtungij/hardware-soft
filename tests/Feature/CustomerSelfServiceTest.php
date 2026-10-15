<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPurchaseRequest;
use App\Models\CustomerReceipt;
use App\Models\LocationPrice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Services\CustomerPortalCredentialService;
use App\Services\CustomerPurchaseRequestService;
use App\Services\Gowa;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    Cache::flush();
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->admin->update(['is_system_owner' => false]);
    $this->admin->syncRoles(['Admin']);
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->branch = Branch::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', 'active')->firstOrFail();
    $this->product = Product::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', 'active')->firstOrFail();
    $this->product->update(['branch_id' => $this->branch->id, 'selling_price' => 777]);
    $this->customer = Customer::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Portal Customer', 'phone' => '255711000111', 'status' => 'active', 'customer_type' => 'credit']);
    $this->account = CustomerAccount::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Portal Customer', 'phone' => '255711000111', 'login_phone' => '255711000111', 'password' => 'Permanent123!', 'status' => 'active', 'preferred_locale' => 'sw']);
});

function issueSelfServicePin(): array
{
    test()->actingAs(test()->admin, 'web');
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => test()->company->id], ['enabled' => true, 'device_id' => 'pin-device', 'last_device_state' => 'logged_in', 'whatsapp_notification_language' => 'sw', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES]);
    $account = app(CustomerPortalCredentialService::class)->enableOrReset(test()->customer, test()->admin, (string) str()->uuid(), true)->refresh();
    $notice = WhatsAppNotification::withoutGlobalScopes()->findOrFail($account->last_credentials_notification_id);
    preg_match('/(?:PIN.*)\n(\d{4})\n/', $notice->resolvedDeliveryMessage(), $matches);
    expect($matches)->toHaveKey(1);

    return [$account, $matches[1], $notice];
}

test('catalogue removes all prices by default and isolates company and branch products', function () {
    $other = Company::create(['business_type' => 'hardware', 'phone' => '255700000999', 'whatsapp_number' => '255700000999', 'company_name' => 'Other Hardware']);
    $foreign = $this->product->replicate();
    $foreign->company_id = $other->id;
    $foreign->sku = 'OTHER-PORTAL-SKU';
    $foreign->name = 'Foreign Secret Product';
    $foreign->save();
    $branch = Branch::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Other Branch', 'code' => 'OTHER-PORTAL', 'status' => 'active']);
    $branchProduct = $this->product->replicate();
    $branchProduct->branch_id = $branch->id;
    $branchProduct->sku = 'OTHER-BRANCH-PORTAL';
    $branchProduct->save();
    Sanctum::actingAs($this->account);
    $response = $this->getJson('/api/customer/products?search='.urlencode($this->product->sku))->assertOk();
    expect($response->json('data.0.id'))->toBe($this->product->id);
    expect($response->getContent())->not->toContain('selling_price')->not->toContain('buying_price')->not->toContain('retail_price')->not->toContain('stock_movements');
    $this->getJson('/api/customer/products?search=Foreign')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/customer/products?search=OTHER-BRANCH-PORTAL')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/customer/products?branch_id=999999')->assertNotFound();
});

test('enabled catalogue uses the branch selling location override without exposing costs', function () {
    $this->company->update(['customer_portal_show_prices' => true]);
    $location = StockLocation::withoutGlobalScopes()->where('company_id', $this->company->id)->where('branch_id', $this->branch->id)->where('type', 'dispensing')->firstOrFail();
    $location->update(['status' => 'active', 'is_active' => true, 'can_sell' => true, 'is_sellable' => true, 'is_default' => true]);
    LocationPrice::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'stock_location_id' => $location->id, 'unit_key' => 0, 'retail_price' => 888, 'is_active' => true]);
    Sanctum::actingAs($this->account);
    $this->getJson('/api/customer/products?search='.urlencode($this->product->sku))->assertOk()->assertJsonPath('data.0.selling_price', 888)->assertJsonMissingPath('data.0.buying_price');
    $this->company->update(['customer_portal_show_prices' => false]);
    $this->getJson('/api/customer/products')->assertOk()->assertJsonMissingPath('data.0.selling_price');
});

test('catalogue rejects anonymous and staff api callers', function () {
    $this->getJson('/api/customer/products')->assertUnauthorized();
    $this->actingAs($this->admin, 'sanctum');
    $this->getJson('/api/customer/products')->assertUnauthorized();
});

test('rfq and order delivery reuse requests without reserving stock or leaking snapshots', function (string $type) {
    Sanctum::actingAs($this->account);
    $before = StockMovement::withoutGlobalScopes()->count();
    $key = (string) str()->uuid();
    $payload = ['submission_key' => $key, 'request_type' => $type, 'branch_id' => $this->branch->id, 'delivery_requested' => true, 'delivery_address' => 'Kariakoo, Dar es Salaam', 'delivery_instructions' => 'Call before arrival', 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'notes' => 'Original packaging']]];
    $response = $this->postJson('/api/customer/requests', $payload)->assertCreated()->assertJsonPath('request_type', $type)->assertJsonPath('delivery_address', 'Kariakoo, Dar es Salaam');
    expect($response->getContent())->not->toContain('price');
    $this->postJson('/api/customer/requests', $payload)->assertCreated()->assertJsonPath('id', $response->json('id'));
    expect(StockMovement::withoutGlobalScopes()->count())->toBe($before);
    expect(CustomerPurchaseRequest::withoutGlobalScopes()->where('submission_key', $key)->count())->toBe(1);
    $payload['submission_key'] = (string) str()->uuid();
    $payload['delivery_address'] = '';
    $this->postJson('/api/customer/requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('delivery_address');
})->with(['rfq', 'order']);

test('request cannot submit another company product or decide another customer quotation', function () {
    $foreignCompany = Company::create(['business_type' => 'hardware', 'phone' => '255700000999', 'whatsapp_number' => '255700000999', 'company_name' => 'Hidden Company']);
    $foreign = $this->product->replicate();
    $foreign->company_id = $foreignCompany->id;
    $foreign->sku = 'FOREIGN-RFQ';
    $foreign->save();
    $quotation = Quotation::withoutGlobalScopes()->create(['company_id' => $foreignCompany->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'created_by' => $this->admin->id, 'quotation_number' => 'FOREIGN-Q', 'document_type' => 'quotation', 'status' => 'sent', 'quotation_date' => today(), 'valid_until' => today()->addDay(), 'subtotal' => 777, 'total_amount' => 777]);
    Sanctum::actingAs($this->account);
    $this->postJson('/api/customer/requests', ['submission_key' => (string) str()->uuid(), 'request_type' => 'rfq', 'items' => [['product_id' => $foreign->id, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/customer/quotations/'.$quotation->id.'/decision', ['decision' => 'accept'])->assertNotFound();
    $this->getJson('/api/customer/quotations')->assertJsonCount(0, 'data');
});

test('onboarding pin is hashed encrypted short lived and friendly Swahili', function () {
    [$account, $pin, $notice] = issueSelfServicePin();
    expect($pin)->toMatch('/^\d{4}$/')->and(Hash::check($pin, $account->password))->toBeTrue();
    expect($account->password)->not->toBe($pin);
    expect($account->temporary_pin_expires_at->timestamp)->toBe(now()->addMinutes(15)->timestamp);
    expect($notice->resolvedDeliveryMessage())->toContain('Portal Customer', $this->company->company_name, 'https://customer.buildcore.site/customer/login', 'dakika 15', 'HARDEX');
    expect($notice->message)->toBe(WhatsAppNotification::SENSITIVE_MESSAGE_PLACEHOLDER);
    expect(json_encode($notice->metadata))->not->toContain($pin);
    Http::assertNothingSent();
});

test('pin works once allows dashboard access and optional password change', function () {
    [$account, $pin] = issueSelfServicePin();
    $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => $pin])->assertOk()->assertJsonPath('password_change_required', false)->assertJsonPath('account.must_change_password', false);
    expect($account->fresh()->temporary_pin_used_at)->not->toBeNull();
    $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => $pin])->assertUnprocessable();
    Sanctum::actingAs($account->fresh());
    $this->getJson('/api/customer/products')->assertOk();
    $this->postJson('/api/customer/change-password', ['current_password' => $pin, 'password' => '1234', 'password_confirmation' => '1234'])->assertUnprocessable();
    $this->postJson('/api/customer/change-password', ['current_password' => $pin, 'password' => 'SafePassword123!', 'password_confirmation' => 'SafePassword123!'])->assertOk();
    expect($account->fresh()->must_change_password)->toBeFalse()->and($account->fresh()->temporary_pin_expires_at)->toBeNull();
    $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => 'SafePassword123!'])->assertOk();
});

test('pin expires and locks after five failures even across throttle windows', function () {
    [$account, $pin] = issueSelfServicePin();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => 'not-the-pin'])->assertUnprocessable();
        $this->travel(61)->seconds();
    }
    expect($account->fresh()->temporary_pin_attempts)->toBe(5);
    $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => $pin])->assertUnprocessable();
    [$account, $pin] = issueSelfServicePin();
    $this->travel(16)->minutes();
    $this->postJson('/api/customer/login', ['login' => $account->login_phone, 'password' => $pin])->assertUnprocessable();
});

test('expired replaced and consumed pin queues cannot send', function (string $state) {
    [$account, $pin, $notice] = issueSelfServicePin();
    if ($state === 'expired') {
        $this->travel(16)->minutes();
    }
    if ($state === 'replaced') {
        issueSelfServicePin();
    }
    if ($state === 'used') {
        app(CustomerPortalCredentialService::class)->authenticate($account->login_phone, $pin);
    }
    $gowa = Mockery::mock(Gowa::class);
    $gowa->shouldNotReceive('sendText');
    $gowa->shouldNotReceive('isOnWhatsApp');
    (new SendWhatsAppNotification($notice->id))->handle($gowa);
    expect($notice->fresh()->status)->toBe('suppressed')->and($notice->fresh()->encrypted_message)->toBeNull();
})->with(['expired', 'replaced', 'used']);

test('company admin price setting cannot select another tenant', function () {
    $other = Company::create(['business_type' => 'hardware', 'phone' => '255700000999', 'whatsapp_number' => '255700000999', 'company_name' => 'Private Tenant']);
    $this->actingAs($this->admin, 'web');
    Volt::test('settings.customer-portal')->set('show_prices', true)->call('save')->assertHasNoErrors();
    expect($this->company->fresh()->customer_portal_show_prices)->toBeTrue();
    Volt::test('settings.customer-portal')->set('company_id', (string) $other->id)->assertForbidden();
    expect($other->fresh()->customer_portal_show_prices)->toBeFalse();
});

test('customer debts statements and files require both company and customer ownership', function () {
    $other = Customer::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Other Customer', 'phone' => '255711000222', 'status' => 'active', 'customer_type' => 'credit']);
    $foreignCompany = Company::create(['company_name' => 'Foreign Finance', 'business_type' => 'hardware', 'phone' => '255700999888', 'whatsapp_number' => '255700999888']);
    $makeSale = fn (int $companyId, int $customerId, string $number) => Sale::withoutGlobalScopes()->create(['company_id' => $companyId, 'branch_id' => $this->branch->id, 'customer_id' => $customerId, 'created_by' => $this->admin->id, 'sale_number' => $number, 'sale_date' => today(), 'status' => 'completed', 'payment_status' => 'unpaid', 'subtotal' => 100, 'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100]);
    $own = $makeSale($this->company->id, $this->customer->id, 'SELF-INV');
    $otherSale = $makeSale($this->company->id, $other->id, 'PRIVATE-INV');
    $wrongCompany = $makeSale($foreignCompany->id, $this->customer->id, 'FOREIGN-INV');
    Sanctum::actingAs($this->account);
    $this->getJson('/api/customer/debts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.invoice_number', 'SELF-INV');
    $this->getJson('/api/customer/debts/'.$otherSale->id)->assertForbidden();
    $response = $this->getJson('/api/customer/debts/'.$wrongCompany->id);
    expect($response->status())->toBeIn([403, 404]);
    $this->getJson('/api/customer/statements')->assertOk()->assertJsonPath('outstanding_balance', 100)->assertJsonCount(1, 'sales');
    $this->getJson('/api/customer/dashboard')->assertOk()->assertJsonPath('total_outstanding_debt', 100);
    Storage::fake('local');
    Storage::disk('local')->put('customer-receipts/private.pdf', 'private');
    $otherAccount = CustomerAccount::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'customer_id' => $other->id, 'name' => 'Other Customer', 'login_phone' => '255711000222', 'password' => 'SafePassword123!', 'status' => 'active']);
    $receipt = CustomerReceipt::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'customer_id' => $other->id, 'customer_account_id' => $otherAccount->id, 'branch_id' => $this->branch->id, 'amount' => 10, 'payment_method' => 'bank', 'receipt_file' => 'customer-receipts/private.pdf', 'status' => 'pending']);
    // A second staff session must not bypass the portal file ownership check.
    $this->actingAs($this->admin, 'web')->actingAs($this->account, 'customer');
    $this->get(route('customer.receipts.download', $receipt))->assertForbidden();
    $this->get(route('customer.debts.show', $otherSale))->assertForbidden();
});

test('catalogue and simplified portal render in either language', function (string $locale) {
    $this->account->update(['preferred_locale' => $locale]);
    $this->actingAs($this->account, 'customer');
    $this->get(route('customer.products'))->assertOk()->assertSee($locale === 'sw' ? 'Tafuta Bidhaa' : 'Product Search')->assertSee($this->company->company_name)->assertDontSee('buying_price');
    $this->get(route('customer.purchase-requests.create'))->assertOk()->assertSee($locale === 'sw' ? 'Omba Bei (Quotation)' : 'Request a Quote');
    $this->get(route('customer.dashboard'))->assertOk();
})->with(['sw', 'en']);

test('enabling an existing account preserves its permanent password', function () {
    $this->actingAs($this->admin, 'web');
    $before = $this->account->password;
    app(CustomerPortalCredentialService::class)->enableOrReset($this->customer, $this->admin, 'enable-permanent');
    expect($this->account->fresh()->password)->toBe($before)->and($this->account->fresh()->must_change_password)->toBeFalse();
});

test('system super admin can configure catalogue prices for any company', function () {
    $owner = $this->admin;
    $owner->update(['is_system_owner' => true]);
    $owner->syncRoles(['Super Admin']);
    $other = Company::create(['company_name' => 'Another Hardware', 'business_type' => 'hardware', 'phone' => '255700111333', 'whatsapp_number' => '255700111333']);
    $this->actingAs($owner, 'web');
    Volt::test('settings.customer-portal')->set('company_id', (string) $other->id)->set('show_prices', true)->call('save')->assertHasNoErrors();
    expect($other->fresh()->customer_portal_show_prices)->toBeTrue();
});

test('cashiers receive and review company requests through existing permissions', function () {
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'is_system_owner' => false, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $request = app(CustomerPurchaseRequestService::class)->submit($this->account, $this->branch->id, 'Please quote', [['product_id' => $this->product->id, 'quantity' => 1]], (string) str()->uuid());
    $this->actingAs($cashier, 'web')->withSession(['staff_locale' => 'en']);
    $this->get(route('dashboard'))->assertOk()->assertSee('Customer requests awaiting review');
    $this->get(route('customer-requests.index'))->assertOk()->assertSee($request->request_number);
    $service = app(CustomerPurchaseRequestService::class);
    expect($service->beginReview($request, $cashier)->status)->toBe('under_review');
    expect($service->reject($request->fresh(), $cashier, 'Unavailable')->status)->toBe('rejected');
});
