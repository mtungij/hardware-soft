<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Observers\SaleWhatsAppObserver;
use App\Services\Gowa;
use App\Services\InventoryService;
use App\Services\WhatsAppNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->company = Company::query()->firstOrFail();
    $this->branch = Branch::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $this->admin = User::withoutGlobalScopes()->where('company_id', $this->company->id)->whereHas('roles')->firstOrFail();
    Cache::flush();
    config()->set('gowa.url', 'https://notify.buildcore.site');
    config()->set('gowa.username', 'gowa-user');
    config()->set('gowa.password', 'gowa-secret');
});

function salesWaSetting(Company $company, array $overrides = []): CompanyWhatsAppSetting
{
    return CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(
        ['company_id' => $company->id],
        array_merge([
            'enabled' => true,
            'sending_paused' => false,
            'device_id' => 'hardex',
            'timezone' => 'Africa/Dar_es_Salaam',
            'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
            'last_device_state' => 'logged_in',
            'last_checked_at' => now(),
            'minimum_send_interval_seconds' => 0,
            'maximum_messages_per_minute' => 30,
            'maximum_messages_per_hour' => 300,
        ], $overrides),
    );
}

function salesWaRecipient(Company $company, array $overrides = []): WhatsAppRecipient
{
    return WhatsAppRecipient::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name' => 'Sales Operations',
        'phone' => '255764555001',
        'scope' => 'company',
        'active' => true,
        'categories' => ['sales'],
    ], $overrides));
}

function salesWaSale(Company $company, Branch $branch, User $seller, array $overrides = []): Sale
{
    return Sale::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'sale_number' => 'INV-WA-001',
        'sale_date' => today(),
        'subtotal' => 125000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 125000,
        'paid_amount' => 125000,
        'balance_amount' => 0,
        'change_amount' => 0,
        'payment_status' => 'paid',
        'status' => 'completed',
        'created_by' => $seller->id,
        'sold_by' => $seller->id,
    ], $overrides));
}

function salesWaNotify(Sale $sale): void
{
    app(SaleWhatsAppObserver::class)->created($sale);
}

function salesWaPosSale(Company $company, Branch $branch, User $seller, ?Customer $customer = null): Sale
{
    $location = StockLocation::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'WhatsApp POS Location',
        'code' => 'WA-POS-'.str()->random(8),
        'type' => 'dispensing',
        'status' => 'active',
        'is_active' => true,
        'can_sell' => true,
        'is_sellable' => true,
        'can_issue_stock' => true,
    ]);
    $product = Product::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
    $product->update(['buying_price' => 50, 'selling_price' => 125000, 'status' => 'active']);
    StockMovement::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $location->id,
        'movement_type' => 'direct_stock_in',
        'quantity' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
        'unit_cost' => 50,
        'unit_price' => 125000,
        'created_by' => $seller->id,
        'movement_date' => today(),
    ]);

    return app(InventoryService::class)->completeSale(
        [[
            'product_id' => $product->id,
            'stock_location_id' => $location->id,
            'sale_type' => 'retail',
            'quantity' => 1,
            'unit_price' => 125000,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 125000, 'reference_number' => null]],
        $customer?->id,
        $location->id,
        $branch->id,
        $seller->id,
        idempotencyKey: (string) str()->uuid(),
    );
}

test('completing a normal POS sale creates a sales outbox row visible in the notification log', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);

    $sale = salesWaPosSale($this->company, $this->branch, $this->admin);
    app('db.transactions')->getCommittedTransactions()->each->executeCallbacks();

    $notification = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail();
    expect($sale->status)->toBe('completed')
        ->and($notification->category)->toBe('sales')
        ->and($notification->metadata['sale_id'])->toBe($sale->id);

    $this->actingAs($this->admin)->get(route('settings.whatsapp.logs'))
        ->assertOk()
        ->assertSee('Sale Completed')
        ->assertSee("sale:{$sale->id}:completed:recipient:");
});

test('phone only company recipient receives completed sales', function () {
    Queue::fake();
    salesWaSetting($this->company);
    $recipient = salesWaRecipient($this->company, ['user_id' => null]);
    $sale = salesWaSale($this->company, $this->branch, $this->admin);

    salesWaNotify($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $recipient->id)->where('category', 'sales')->count())->toBe(1);
});

test('staff linked sales recipient follows authorization scope', function () {
    Queue::fake();
    salesWaSetting($this->company);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $otherCashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $otherCashier->assignRole('Cashier');
    $allowed = salesWaRecipient($this->company, ['phone' => '255764555002', 'user_id' => $cashier->id]);
    $excluded = salesWaRecipient($this->company, ['phone' => '255764555003', 'user_id' => $otherCashier->id]);
    $sale = salesWaSale($this->company, $this->branch, $cashier);

    salesWaNotify($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $allowed->id)->exists())->toBeTrue()
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $excluded->id)->exists())->toBeFalse();
});

test('recipient without sales category gets no completed sale outbox row', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company, ['categories' => ['security']]);
    $sale = salesWaSale($this->company, $this->branch, $this->admin);

    salesWaNotify($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->exists())->toBeFalse();
});

test('disabled company whatsapp creates no delivery job for a completed sale', function () {
    Queue::fake();
    salesWaSetting($this->company, ['enabled' => false]);
    salesWaRecipient($this->company);

    salesWaNotify(salesWaSale($this->company, $this->branch, $this->admin));

    expect(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->exists())->toBeFalse();
    Queue::assertNotPushed(SendWhatsAppNotification::class);
});

test('rolled back completed sale creates no whatsapp notification', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);

    try {
        DB::transaction(function (): void {
            salesWaSale($this->company, $this->branch, $this->admin, ['sale_number' => 'INV-ROLLBACK']);
            throw new RuntimeException('Force rollback');
        });
    } catch (RuntimeException) {
        // Expected transaction rollback.
    }

    expect(Sale::withoutGlobalScopes()->where('sale_number', 'INV-ROLLBACK')->exists())->toBeFalse()
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->exists())->toBeFalse();
});

test('draft and incomplete sales create no notification', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $sale = salesWaSale($this->company, $this->branch, $this->admin, ['status' => 'draft']);

    salesWaNotify($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->exists())->toBeFalse();
});

test('a genuine status transition to completed creates one notification', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $sale = salesWaSale($this->company, $this->branch, $this->admin, ['status' => 'pending']);

    $sale->update(['status' => 'completed']);
    app(SaleWhatsAppObserver::class)->updated($sale);
    $sale->save();
    app(SaleWhatsAppObserver::class)->updated($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->count())->toBe(1);
});

test('repeated completion hooks create at most one notification per recipient', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $sale = salesWaSale($this->company, $this->branch, $this->admin);

    salesWaNotify($sale);
    salesWaNotify($sale);

    expect(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->count())->toBe(1);
});

test('queue retry does not duplicate a completed sale outbox row', function () {
    Queue::fake();
    $setting = salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $sale = salesWaSale($this->company, $this->branch, $this->admin);
    salesWaNotify($sale);
    $notification = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail();
    $notification->update(['status' => 'failed']);

    app(WhatsAppNotificationService::class)->retry($notification);
    app(WhatsAppNotificationService::class)->retry($notification->refresh());

    expect($setting->company_id)->toBe($sale->company_id)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->count())->toBe(1);
});

test('different companies cannot receive each others completed sales', function () {
    Queue::fake();
    salesWaSetting($this->company);
    $ownRecipient = salesWaRecipient($this->company);
    $other = Company::query()->create(['company_name' => 'Other Co', 'business_type' => 'hardware', 'phone' => '255700000901', 'whatsapp_number' => '255700000901']);
    salesWaSetting($other);
    $otherRecipient = salesWaRecipient($other, ['phone' => '255764555004']);

    salesWaNotify(salesWaSale($this->company, $this->branch, $this->admin));

    expect(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $ownRecipient->id)->exists())->toBeTrue()
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $otherRecipient->id)->exists())->toBeFalse();
});

test('branch scoped recipient cannot receive another branch completed sale', function () {
    Queue::fake();
    salesWaSetting($this->company);
    $otherBranch = Branch::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Other Branch', 'code' => 'WA-OTHER', 'status' => 'active']);
    $recipient = salesWaRecipient($this->company, ['scope' => 'branch', 'branch_id' => $otherBranch->id]);

    salesWaNotify(salesWaSale($this->company, $this->branch, $this->admin));

    expect(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $recipient->id)->exists())->toBeFalse();
});

test('sales message contains invoice customer and actual amounts', function () {
    Queue::fake();
    $customer = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $sale = salesWaSale($this->company, $this->branch, $this->admin, ['customer_id' => $customer->id]);
    $sale->payments()->create(['payment_method' => 'cash', 'amount' => 125000, 'received_by' => $this->admin->id, 'payment_date' => today()]);
    salesWaSetting($this->company);
    salesWaRecipient($this->company);

    salesWaNotify($sale);

    $message = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail()->message;
    expect($message)->toContain('INV-WA-001')->toContain($customer->name)
        ->toContain('Total: TZS 125,000')->toContain('Paid: TZS 125,000')->toContain('Balance: TZS 0')->toContain('Payment: Cash');
});

test('phone only sales message excludes sensitive cost and profit information', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company, ['user_id' => null]);

    salesWaNotify(salesWaSale($this->company, $this->branch, $this->admin));

    $message = strtolower(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail()->message);
    expect($message)->not->toContain('buying price')->not->toContain('cogs')->not->toContain('profit')
        ->not->toContain('margin')->not->toContain('stock valuation');
});

test('gowa failure cannot roll back or invalidate a completed sale', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $sale = salesWaSale($this->company, $this->branch, $this->admin);
    salesWaNotify($sale);
    $notification = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail();
    Http::fake([
        '*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['message' => 'Unavailable'], 503),
    ]);

    try {
        (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));
    } catch (Throwable) {
        // The queue will retry independently; the business transaction is already durable.
    }

    expect(Sale::withoutGlobalScopes()->whereKey($sale->id)->where('status', 'completed')->exists())->toBeTrue()
        ->and($notification->refresh()->status)->toBe('queued');
});

test('real pos completion flows through outbox job and gowa request to sent log status', function () {
    Queue::fake();
    salesWaSetting($this->company);
    salesWaRecipient($this->company);
    $customer = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    $sale = salesWaPosSale($this->company, $this->branch, $this->admin, $customer);
    app('db.transactions')->getCommittedTransactions()->each->executeCallbacks();
    $notification = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_completed')->firstOrFail();
    Http::fake([
        '*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'SALE-GOWA-1']]),
    ]);

    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));

    expect($sale->status)->toBe('completed')
        ->and($notification->refresh()->status)->toBe('sent')
        ->and($notification->message_id)->toBe('SALE-GOWA-1');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/send/message')
        && $request->header('X-Device-Id')[0] === 'hardex'
        && $request['phone'] === '255764555001'
        && str_contains($request['message'], $sale->sale_number));
});
