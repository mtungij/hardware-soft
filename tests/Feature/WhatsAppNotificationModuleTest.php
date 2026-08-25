<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Observers\CustomerPaymentWhatsAppObserver;
use App\Observers\ProductionOrderWhatsAppObserver;
use App\Observers\SaleWhatsAppObserver;
use App\Services\Gowa;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use App\Support\WhatsAppPhone;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config()->set('gowa.url', 'https://notify.buildcore.site');
    config()->set('gowa.username', 'gowa-user');
    config()->set('gowa.password', 'gowa-secret');
    Cache::flush();
});

function whatsappCompany(): Company
{
    return Company::query()->firstOrFail();
}

function whatsappSetting(Company $company, array $overrides = []): CompanyWhatsAppSetting
{
    return CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id], array_merge([
        'enabled' => true, 'sending_paused' => false, 'device_id' => 'device-company-a',
        'timezone' => 'Africa/Dar_es_Salaam', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
        'last_device_state' => 'logged_in', 'last_checked_at' => now(), 'minimum_send_interval_seconds' => 5,
        'maximum_messages_per_minute' => 3, 'maximum_messages_per_hour' => 60,
    ], $overrides));
}

function whatsappRecipient(Company $company, array $overrides = []): WhatsAppRecipient
{
    return WhatsAppRecipient::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id, 'name' => 'Owner', 'phone' => '255764123456',
        'scope' => 'company', 'active' => true, 'categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ], $overrides));
}

test('gowa sends text with basic auth and the explicit device header', function () {
    Http::fake(['*/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'MSG-1']])]);

    $response = app(Gowa::class)->sendText('device-a', '255764123456', 'Hello HARDEX');

    expect(data_get($response, 'results.message_id'))->toBe('MSG-1');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://notify.buildcore.site/send/message'
        && $request->header('X-Device-Id')[0] === 'device-a'
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('gowa-user:gowa-secret')
        && $request['phone'] === '255764123456' && $request['message'] === 'Hello HARDEX');
});

test('gowa sends files using the documented multipart field and device isolation', function () {
    Storage::fake('local');
    Storage::disk('local')->put('reports/daily.pdf', '%PDF-test');
    Http::fake(['*/send/file' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'FILE-1']])]);

    app(Gowa::class)->sendFile('device-file', '255764123456', Storage::disk('local')->path('reports/daily.pdf'), 'Daily report');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/send/file')
        && $request->header('X-Device-Id')[0] === 'device-file'
        && str_contains($request->body(), 'name="file"')
        && str_contains($request->body(), 'Daily report'));
});

test('tanzania phone numbers normalize consistently and malformed numbers fail', function () {
    expect(WhatsAppPhone::normalize('0764 123 456'))->toBe('255764123456')
        ->and(WhatsAppPhone::normalize('+255-764-123-456'))->toBe('255764123456')
        ->and(WhatsAppPhone::normalize('255764123456'))->toBe('255764123456');

    WhatsAppPhone::normalize('1234');
})->throws(InvalidArgumentException::class);

test('outbox uses only the current company device and suppresses a missing device', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);

    $queued = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:10:cancelled', 'Cancelled');

    expect($queued)->toHaveCount(1)
        ->and($queued[0]->device_id)->toBe('device-company-a')
        ->and($queued[0]->company_id)->toBe($company->id)
        ->and($queued[0]->status)->toBe('queued');

    whatsappSetting($company, ['device_id' => null]);
    $suppressed = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:11:cancelled', 'Cancelled');
    expect($suppressed[0]->status)->toBe('suppressed')
        ->and($suppressed[0]->failure_reason)->toContain('Device ID');
});

test('outbox event idempotency prevents duplicate recipient messages', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);
    $service = app(WhatsAppNotificationService::class);

    $first = $service->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:88:cancelled', 'Cancelled');
    $second = $service->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:88:cancelled', 'Cancelled again');

    expect($first[0]->id)->toBe($second[0]->id)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

test('delivery checks number availability then marks the outbox sent with message id', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);
    $notification = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:90:cancelled', 'Cancelled')[0];
    Http::fake([
        '*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'GOWA-90']]),
    ]);

    app(SendWhatsAppNotification::class, ['notificationId' => $notification->id])->handle(app(Gowa::class));

    expect($notification->refresh()->status)->toBe('sent')
        ->and($notification->message_id)->toBe('GOWA-90')
        ->and($notification->attempts)->toBe(1);
});

test('number not on whatsapp is suppressed without attempting a send', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);
    $notification = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:91:cancelled', 'Cancelled')[0];
    Http::fake(['*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => false]])]);

    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));

    expect($notification->refresh()->status)->toBe('suppressed')
        ->and($notification->failure_reason)->toContain('not registered');
    Http::assertSentCount(1);
});

test('disconnected device keeps notification pending and makes no send calls', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company, ['last_device_state' => 'disconnected']);
    whatsappRecipient($company);
    $notification = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:92:cancelled', 'Cancelled')[0];
    Http::fake();

    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));

    expect($notification->refresh()->status)->toBe('pending')
        ->and($notification->failure_reason)->toContain('not connected');
    Http::assertNothingSent();
});

test('quiet hours defer delivery instead of discarding the notification', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company, ['quiet_hours_start' => now()->subMinute()->format('H:i'), 'quiet_hours_end' => now()->addMinute()->format('H:i')]);
    whatsappRecipient($company);
    $notification = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:93:cancelled', 'Cancelled')[0];
    Http::fake();
    $job = (new SendWhatsAppNotification($notification->id))->withFakeQueueInteractions();

    $job->handle(app(Gowa::class));

    expect($notification->refresh()->status)->toBe('pending')
        ->and($notification->available_at)->not->toBeNull();
    $job->assertReleased();
    Http::assertNothingSent();
});

test('per device queue serialization middleware and retry backoff are configured', function () {
    $company = whatsappCompany();
    whatsappSetting($company);
    $recipient = whatsappRecipient($company);
    $notification = WhatsAppNotification::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'recipient_id' => $recipient->id, 'device_id' => 'device-company-a',
        'phone' => $recipient->phone, 'notification_type' => 'test', 'category' => 'system', 'message' => 'Test',
        'status' => 'queued', 'idempotency_key' => 'middleware-test',
    ]);
    $job = new SendWhatsAppNotification($notification->id);

    expect($job->backoff())->toBe([10, 60, 300])
        ->and($job->tries)->toBe(3)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('daily summary hides financial details without permission and reveals them with permission', function () {
    $company = whatsappCompany();
    $branch = Branch::query()->firstOrFail();
    $cashier = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $admin = User::query()->where('company_id', $company->id)->whereHas('roles', fn ($query) => $query->whereIn('name', ['Admin', 'Super Admin']))->firstOrFail();
    $restricted = whatsappRecipient($company, ['phone' => '255764123457', 'user_id' => $cashier->id, 'branch_id' => $branch->id, 'scope' => 'branch']);
    $authorized = whatsappRecipient($company, ['phone' => '255764123458', 'user_id' => $admin->id]);
    $factory = app(WhatsAppMessageFactory::class);

    expect($factory->dailySummary($company, $restricted, today()))->not->toContain('Gross Profit')
        ->and($factory->dailySummary($company, $authorized, today()))->toContain('Gross Profit')->toContain('COGS');
});

test('branch recipients are excluded from another branch event', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->take(2)->get();
    if ($branches->count() < 2) {
        $branches->push(Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Other', 'code' => 'OTHER', 'status' => 'active']));
    }
    whatsappRecipient($company, ['branch_id' => $branches[0]->id, 'scope' => 'branch']);

    $rows = app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'sale_cancelled', 'sale:100:cancelled', 'Cancelled', $branches[1]->id);

    expect($rows)->toBeEmpty();
});

test('device health command stores state and resumes eligible pending notifications', function () {
    Queue::fake();
    $company = whatsappCompany();
    $setting = whatsappSetting($company, ['last_device_state' => 'disconnected']);
    $recipient = whatsappRecipient($company);
    $pending = WhatsAppNotification::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'recipient_id' => $recipient->id, 'device_id' => $setting->device_id,
        'phone' => $recipient->phone, 'notification_type' => 'test', 'category' => 'system', 'message' => 'Pending',
        'status' => 'pending', 'available_at' => now()->subMinute(), 'idempotency_key' => 'health-test',
    ]);
    Http::fake(['*/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => true, 'is_logged_in' => true]])]);

    $this->artisan('whatsapp:check-devices', ['--company' => $company->id])->assertSuccessful();

    expect($setting->refresh()->last_device_state)->toBe('logged_in')
        ->and($pending->refresh()->status)->toBe('queued');
    Queue::assertPushed(SendWhatsAppNotification::class, fn ($job) => $job->notificationId === $pending->id);
});

test('whatsapp settings and retry log are permission protected', function () {
    $company = whatsappCompany();
    $branch = Branch::query()->firstOrFail();
    $cashier = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $admin = User::query()->where('company_id', $company->id)->whereHas('roles', fn ($query) => $query->whereIn('name', ['Admin', 'Super Admin']))->firstOrFail();

    $this->actingAs($cashier)->get(route('settings.whatsapp'))->assertForbidden();
    $this->actingAs($admin)->get(route('settings.whatsapp'))->assertOk()->assertSee('WhatsApp Notifications');
    $this->actingAs($admin)->get(route('settings.whatsapp.logs'))->assertOk()->assertSee('Notification Log');
});

test('business notification observers are explicitly after commit', function () {
    expect(app(SaleWhatsAppObserver::class))->toBeInstanceOf(ShouldHandleEventsAfterCommit::class)
        ->and(app(CustomerPaymentWhatsAppObserver::class))->toBeInstanceOf(ShouldHandleEventsAfterCommit::class)
        ->and(app(ProductionOrderWhatsAppObserver::class))->toBeInstanceOf(ShouldHandleEventsAfterCommit::class);
});

test('sale cancellation observer records an idempotent branch alert', function () {
    Queue::fake();
    $company = whatsappCompany();
    $branch = Branch::query()->firstOrFail();
    $admin = User::query()->where('company_id', $company->id)->firstOrFail();
    whatsappSetting($company);
    whatsappRecipient($company, ['branch_id' => $branch->id, 'scope' => 'branch']);
    $sale = Sale::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'sale_number' => 'WA-CANCEL-1',
        'sale_date' => today(), 'subtotal' => 50000, 'discount_amount' => 0, 'tax_amount' => 0,
        'total_amount' => 50000, 'paid_amount' => 50000, 'balance_amount' => 0, 'change_amount' => 0,
        'payment_status' => 'paid', 'status' => 'completed', 'created_by' => $admin->id,
    ]);
    $sale->update(['status' => 'cancelled', 'cancelled_by' => $admin->id, 'cancelled_at' => now()]);

    app(SaleWhatsAppObserver::class)->updated($sale);
    app(SaleWhatsAppObserver::class)->updated($sale);

    $alert = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_cancelled')->firstOrFail();
    expect($alert->branch_id)->toBe($branch->id)
        ->and($alert->message)->toContain('WA-CANCEL-1')
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'sale_cancelled')->count())->toBe(1);
});

test('daily summary command queues one summary per recipient without duplicates', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);

    $this->artisan('whatsapp:daily-summary', ['--company' => $company->id, '--force' => true])->assertSuccessful();
    $this->artisan('whatsapp:daily-summary', ['--company' => $company->id, '--force' => true])->assertSuccessful();

    $summaries = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'daily_management_summary')->get();
    expect($summaries)->toHaveCount(1)
        ->and($summaries->first()->message)->toContain('HARDEX DAILY SUMMARY')->toContain('Transactions:');
});

test('low stock command aggregates products into a controlled recipient message', function () {
    Queue::fake();
    $company = whatsappCompany();
    whatsappSetting($company);
    whatsappRecipient($company);

    $this->artisan('whatsapp:stock-alerts', ['--company' => $company->id])->assertSuccessful();

    $alerts = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'low_stock_aggregate')->get();
    expect($alerts->count())->toBeLessThanOrEqual(1);
    if ($alerts->isNotEmpty()) {
        expect($alerts->first()->message)->toContain('HARDEX STOCK ALERT')->toContain('products require attention');
    }
});

test('customer payment observer records an idempotent alert after payment creation', function () {
    Queue::fake();
    $company = whatsappCompany();
    $branch = Branch::query()->firstOrFail();
    $admin = User::query()->where('company_id', $company->id)->firstOrFail();
    $customer = Customer::query()->firstOrFail();
    whatsappSetting($company);
    whatsappRecipient($company, ['branch_id' => $branch->id, 'scope' => 'branch']);
    $payment = CustomerPayment::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'receipt_number' => 'WA-PAY-1', 'amount' => 25000, 'payment_method' => 'cash',
        'payment_date' => today(), 'received_by' => $admin->id,
    ]);

    app(CustomerPaymentWhatsAppObserver::class)->created($payment);
    app(CustomerPaymentWhatsAppObserver::class)->created($payment);

    $alert = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_payment_received')->firstOrFail();
    expect($alert->message)->toContain('WA-PAY-1')->toContain('25,000')
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_payment_received')->count())->toBe(1);
});

test('terminal queue failure is recorded for manual retry', function () {
    $company = whatsappCompany();
    whatsappSetting($company);
    $recipient = whatsappRecipient($company);
    $notification = WhatsAppNotification::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'recipient_id' => $recipient->id, 'device_id' => 'device-company-a',
        'phone' => $recipient->phone, 'notification_type' => 'test', 'category' => 'system', 'message' => 'Failure',
        'status' => 'sending', 'attempts' => 3, 'idempotency_key' => 'failed-test',
    ]);

    (new SendWhatsAppNotification($notification->id))->failed(new RuntimeException('Temporary upstream failure'));

    expect($notification->refresh()->status)->toBe('failed')
        ->and($notification->failed_at)->not->toBeNull()
        ->and($notification->failure_reason)->toContain('Temporary upstream failure');
});

test('production completion observer records accepted and rejected output', function () {
    Queue::fake();
    $company = whatsappCompany();
    $branch = Branch::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    whatsappSetting($company);
    whatsappRecipient($company, ['branch_id' => $branch->id, 'scope' => 'branch']);
    $order = new ProductionOrder;
    $order->setRawAttributes([
        'id' => 99001, 'company_id' => $company->id, 'branch_id' => $branch->id,
        'product_id' => $product->id, 'order_number' => 'WA-PROD-1',
        'status' => ProductionOrder::STATUS_AWAITING_COMPLETION,
        'accepted_quantity' => '80.0000', 'rejected_quantity' => '5.0000',
    ], true);
    $order->exists = true;
    $order->status = ProductionOrder::STATUS_COMPLETED;
    $order->syncChanges();
    $order->setRelation('company', $company)->setRelation('branch', $branch)->setRelation('product', $product);

    app(ProductionOrderWhatsAppObserver::class)->updated($order);

    $alert = WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'production_completed')->firstOrFail();
    expect($alert->message)->toContain('WA-PROD-1')->toContain('Accepted: 80.0000')->toContain('Rejected: 5.0000');
});
