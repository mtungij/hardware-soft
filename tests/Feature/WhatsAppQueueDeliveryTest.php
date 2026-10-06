<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Services\Gowa;
use App\Services\PurchaseOrderEmailService;
use App\Services\WhatsAppNotificationService;
use App\Services\WhatsAppQueueInspector;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('gowa.url', 'https://gowa.test');
    config()->set('gowa.username', 'test');
    config()->set('gowa.password', 'test');
    Cache::flush();
    Http::preventStrayRequests();
    $this->company = Company::create(['company_name' => 'Queue Test', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->setting = CompanyWhatsAppSetting::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'enabled' => true, 'sending_paused' => false,
        'device_id' => 'queue-test-device', 'last_device_state' => 'logged_in',
        'enabled_categories' => ['security'], 'minimum_send_interval_seconds' => 1,
        'maximum_messages_per_minute' => 100, 'maximum_messages_per_hour' => 1000,
    ]);
    $this->recipient = WhatsAppRecipient::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Queue Recipient', 'phone' => '255764123456',
        'scope' => 'company', 'active' => true, 'categories' => ['security'],
    ]);
});

function queueDeliveryNotification(Company $company, string $key = 'queue-delivery'): WhatsAppNotification
{
    return app(WhatsAppNotificationService::class)->queueForRecipients($company, 'security', 'product_deleted', $key, 'Test notification')[0];
}

function queueDeliveryWork(string $queue = 'whatsapp'): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 90])->assertSuccessful();
}

function queueDeliveryStale(WhatsAppNotification $notification): void
{
    $notification->forceFill(['created_at' => now()->subMinutes(15), 'queued_at' => now()->subMinutes(10)])->save();
}

test('database WhatsApp delivery stays queued until its queue worker runs and then sends once', function () {
    $notification = queueDeliveryNotification($this->company);
    expect($notification->refresh()->status)->toBe('queued')->and($notification->attempts)->toBe(0)->and($notification->sent_at)->toBeNull();
    expect(DB::table('jobs')->value('queue'))->toBe('whatsapp');
    Http::fake(function ($request) use ($notification) {
        expect($notification->refresh()->status)->toBe('sending')->and($notification->attempts)->toBe(1);

        return Http::response(str_contains($request->url(), '/user/check') ? ['results' => ['is_on_whatsapp' => true]] : ['results' => ['message_id' => 'QUEUE-SENT']]);
    });
    queueDeliveryWork('default');
    expect($notification->refresh()->status)->toBe('queued')->and(DB::table('jobs')->count())->toBe(1);
    Http::assertNothingSent();
    queueDeliveryWork();
    expect($notification->refresh()->status)->toBe('sent')->and($notification->attempts)->toBe(1)->and($notification->sent_at)->not->toBeNull()->and($notification->message_id)->toBe('QUEUE-SENT');
    expect(DB::table('jobs')->count())->toBe(0);
    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));
    (new SendWhatsAppNotification($notification->id))->failed(new RuntimeException('A duplicate job failed'));
    expect($notification->refresh()->status)->toBe('sent');
    Http::assertSentCount(2);
});

test('event idempotency does not enqueue again after the old dispatch cache lock expires', function () {
    $first = queueDeliveryNotification($this->company);
    $this->travel(1)->minutes();
    $second = queueDeliveryNotification($this->company);
    expect($second->id)->toBe($first->id)->and(DB::table('jobs')->count())->toBe(1);
});

test('a cache failure after GOWA accepts delivery cannot reset sent or trigger another send', function () {
    $notification = queueDeliveryNotification($this->company);
    Http::fake([
        '*/user/check*' => Http::response(['results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['results' => ['message_id' => 'ACCEPTED']]),
    ]);
    $cache = Mockery::mock(Cache::getFacadeRoot());
    $cache->shouldReceive('put')
        ->with('whatsapp:last-send:'.hash('sha256', $this->setting->device_id), Mockery::any(), 3600)
        ->once()->andThrow(new RuntimeException('Cache unavailable after send'));
    Cache::swap($cache);
    $job = new SendWhatsAppNotification($notification->id);
    expect(fn () => $job->handle(app(Gowa::class)))->toThrow(RuntimeException::class, 'Cache unavailable after send');
    expect($notification->refresh()->status)->toBe('sent')->and($notification->attempts)->toBe(1);
    $job->failed(new RuntimeException('Cache unavailable after send'));
    $job->handle(app(Gowa::class));
    expect($notification->refresh()->status)->toBe('sent');
    Http::assertSentCount(2);
});

test('throttle releases do not exhaust three reservations before any actual delivery attempt', function () {
    $notification = queueDeliveryNotification($this->company);
    $this->setting->update(['minimum_send_interval_seconds' => 60]);
    Cache::put('whatsapp:last-send:'.hash('sha256', $this->setting->device_id), now()->timestamp, 3600);
    Http::fake([
        '*/user/check*' => Http::response(['results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['results' => ['message_id' => 'AFTER-THROTTLE']]),
    ]);
    for ($i = 0; $i < 4; $i++) {
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        queueDeliveryWork();
    }
    expect($notification->refresh()->status)->toBe('queued')->and($notification->attempts)->toBe(0)->and(DB::table('jobs')->value('attempts'))->toBe(4);
    Http::assertNothingSent();
    Cache::forget('whatsapp:last-send:'.hash('sha256', $this->setting->device_id));
    DB::table('jobs')->update(['available_at' => now()->timestamp]);
    queueDeliveryWork();
    expect($notification->refresh()->status)->toBe('sent')->and($notification->attempts)->toBe(1);
});

test('three actual transient delivery exceptions reach terminal failed state with a useful reason', function () {
    $notification = queueDeliveryNotification($this->company);
    Http::fake([
        '*/user/check*' => Http::response(['results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['error' => 'Unavailable'], 503),
    ]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        queueDeliveryWork();
        expect($notification->refresh()->attempts)->toBe($attempt);
    }
    expect($notification->status)->toBe('failed')->and($notification->failed_at)->not->toBeNull()->and($notification->failure_reason)->toContain('503')->not->toContain('https://gowa.test');
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
});

test('number check exceptions count attempts and return to queued for retry', function () {
    $notification = queueDeliveryNotification($this->company);
    Http::fake(['*/user/check*' => Http::response(['error' => 'Unavailable'], 503)]);
    queueDeliveryWork();
    expect($notification->refresh()->status)->toBe('queued')->and($notification->attempts)->toBe(1)->and($notification->failure_reason)->toContain('503')->and($notification->failed_at)->toBeNull();
});

test('permanent delivery rejection is recorded as failed without retrying', function () {
    $notification = queueDeliveryNotification($this->company);
    Http::fake([
        '*/user/check*' => Http::response(['results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['error' => 'Rejected'], 422),
    ]);
    queueDeliveryWork();
    expect($notification->refresh()->status)->toBe('failed')->and($notification->failed_at)->not->toBeNull()->and($notification->attempts)->toBe(1)->and($notification->failure_reason)->toContain('422');
    expect(DB::table('jobs')->count())->toBe(0);
});

test('stale recovery inspects first and re-dispatches a missing job exactly once', function () {
    $notification = queueDeliveryNotification($this->company);
    DB::table('jobs')->delete();
    queueDeliveryStale($notification);
    $service = app(WhatsAppNotificationService::class);
    $this->artisan('whatsapp:recover-queued', ['--company' => $this->company->id])->expectsOutput("Notification {$notification->id}: eligible")->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0);
    expect($service->recoverQueued($this->company->id, $notification->id, true))->toBe('dispatched');
    expect($service->recoverQueued($this->company->id, $notification->id, true))->toBe('ineligible');
    expect(DB::table('jobs')->count())->toBe(1)->and($notification->refresh()->attempts)->toBe(0)->and($notification->status)->toBe('queued');
});

test('stale recovery preserves an existing ready delayed or reserved database job', function (string $state) {
    $notification = queueDeliveryNotification($this->company);
    queueDeliveryStale($notification);
    if ($state === 'delayed') {
        DB::table('jobs')->update(['available_at' => now()->addHour()->timestamp]);
    } elseif ($state === 'reserved') {
        DB::table('jobs')->update(['reserved_at' => now()->timestamp]);
    }
    expect(app(WhatsAppNotificationService::class)->recoverQueued($this->company->id, $notification->id, true))->toBe('job-exists');
    expect(DB::table('jobs')->count())->toBe(1);
})->with(['ready', 'delayed', 'reserved']);

test('recovery never re-dispatches ineligible lifecycle states', function (string $state) {
    $notification = queueDeliveryNotification($this->company);
    DB::table('jobs')->delete();
    queueDeliveryStale($notification);
    $notification->update(['status' => $state]);
    expect(app(WhatsAppNotificationService::class)->recoverQueued($this->company->id, $notification->id, true))->toBe('ineligible');
    expect(DB::table('jobs')->count())->toBe(0);
})->with(['sent', 'sending', 'cancelled', 'suppressed', 'failed', 'pending']);

test('recovery excludes historical attempted diagnostic paused future and outdated recipient records', function (string $case) {
    $notification = queueDeliveryNotification($this->company);
    DB::table('jobs')->delete();
    queueDeliveryStale($notification);
    match ($case) {
        'historical' => $notification->forceFill(['created_at' => now()->subHours(2)])->save(),
        'attempted' => $notification->update(['attempts' => 1]),
        'debug' => $notification->update(['notification_type' => 'debug_after_commit']),
        'future' => $notification->update(['available_at' => now()->addHour()]),
        'paused' => $this->setting->update(['sending_paused' => true]),
        'recipient' => $this->recipient->update(['active' => false]),
        'device' => $this->setting->update(['device_id' => 'replacement-device']),
        'category' => $this->setting->update(['enabled_categories' => ['sales']]),
    };
    expect(app(WhatsAppNotificationService::class)->recoverQueued($this->company->id, $notification->id, true))->toBe('ineligible');
    expect(DB::table('jobs')->count())->toBe(0);
})->with(['historical', 'attempted', 'debug', 'future', 'paused', 'recipient', 'device', 'category']);

test('recovery requires a company and cannot recover another company notification', function () {
    $notification = queueDeliveryNotification($this->company);
    DB::table('jobs')->delete();
    queueDeliveryStale($notification);
    $other = Company::create(['company_name' => 'Other Queue Tenant', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->artisan('whatsapp:recover-queued')->assertFailed();
    expect(app(WhatsAppNotificationService::class)->recoverQueued($other->id, $notification->id, true))->toBe('ineligible');
    expect(DB::table('jobs')->count())->toBe(0)->and($notification->refresh()->status)->toBe('queued');
});

test('debug notification jobs never send to real recipients', function () {
    $notification = queueDeliveryNotification($this->company);
    $notification->update(['notification_type' => 'debug_after_commit']);
    Http::fake();
    queueDeliveryWork();
    expect($notification->refresh()->status)->toBe('suppressed')->and($notification->attempts)->toBe(0);
    Http::assertNothingSent();
});

test('device reconnect does not duplicate a pending notification with a delayed job', function () {
    $notification = queueDeliveryNotification($this->company);
    $notification->update(['status' => 'pending']);
    DB::table('jobs')->update(['available_at' => now()->addHour()->timestamp]);
    app(WhatsAppNotificationService::class)->resumePending($notification);
    expect(DB::table('jobs')->count())->toBe(1)->and($notification->refresh()->status)->toBe('pending');
});

test('a concurrent cancellation cannot be overwritten by device deferral', function () {
    $notification = queueDeliveryNotification($this->company);
    $this->setting->update(['last_device_state' => 'disconnected']);
    $cancelled = false;
    DB::listen(function ($query) use ($notification, &$cancelled) {
        if (! $cancelled && str_contains($query->sql, 'from "company_whatsapp_settings"')) {
            $cancelled = true;
            DB::table('whatsapp_notifications')->where('id', $notification->id)->update(['status' => 'cancelled']);
        }
    });
    Http::fake();
    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));
    expect($cancelled)->toBeTrue()->and($notification->refresh()->status)->toBe('cancelled')->and($notification->attempts)->toBe(0);
    Http::assertNothingSent();
});

test('Redis atomic inspection respects the configured connection and cluster queue keys', function (bool $cluster) {
    config()->set('queue.default', 'redis');
    $job = new SendWhatsAppNotification(123);
    $payload = json_encode(['data' => ['commandName' => SendWhatsAppNotification::class, 'command' => serialize($job)]]);
    $redis = Mockery::mock();
    $redis->shouldReceive('isCluster')->andReturn($cluster);
    foreach (['whatsapp', 'default'] as $name) {
        $key = 'queues:'.($cluster ? '{'.$name.'}' : $name);
        $redis->shouldReceive('eval')->with(Mockery::on(fn ($script) => str_contains($script, 'LLEN') && str_contains($script, 'ZRANGE')), 3, $key, $key.':delayed', $key.':reserved')
            ->andReturn($name === 'whatsapp' ? [$payload] : []);
    }
    $queue = Mockery::mock(RedisQueue::class)->makePartial();
    $queue->shouldReceive('getConnection')->andReturn($redis);
    Queue::shouldReceive('connection')->with('redis')->andReturn($queue);
    expect(app(WhatsAppQueueInspector::class)->pendingNotificationIds())->toBe([123]);
    expect($job->queue)->toBe('whatsapp')->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan(90);
})->with([false, true]);

test('unverifiable queue state cannot trigger recovery', function () {
    $notification = queueDeliveryNotification($this->company);
    DB::table('jobs')->delete();
    queueDeliveryStale($notification);
    $this->mock(WhatsAppQueueInspector::class)->shouldReceive('pendingNotificationIds')->andReturn(null);
    expect(app(WhatsAppNotificationService::class)->recoverQueued($this->company->id, $notification->id, true))->toBe('unverified');
    expect(DB::table('jobs')->count())->toBe(0);
});

test('fresh Purchase Order and Product Deleted events deliver through the real WhatsApp database queue', function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    $this->mock(PurchaseOrderEmailService::class)->shouldReceive('pdfBinary')->once()->andReturn('%PDF-test');
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $company = $admin->company;
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id], [
        'enabled' => true, 'sending_paused' => false, 'device_id' => 'event-delivery-device',
        'last_device_state' => 'logged_in', 'enabled_categories' => ['security', 'purchase_order_created'],
        'minimum_send_interval_seconds' => 1, 'maximum_messages_per_minute' => 100, 'maximum_messages_per_hour' => 1000,
    ]);
    WhatsAppRecipient::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Event Recipient', 'phone' => '255764123457',
        'scope' => 'company', 'active' => true, 'categories' => ['security', 'purchase_order_created'],
    ]);
    $this->actingAs($admin);
    $product = Product::where('company_id', $company->id)->firstOrFail();
    $supplier = Supplier::create(['company_id' => $company->id, 'branch_id' => $admin->branch_id, 'name' => 'Queue Supplier', 'phone' => '255765123456', 'status' => 'active']);
    DB::transaction(function () use ($company, $admin, $supplier, $product) {
        $purchase = Purchase::create([
            'company_id' => $company->id, 'branch_id' => $admin->branch_id, 'supplier_id' => $supplier->id,
            'reference_number' => 'QUEUE-FRESH-PO', 'purchase_date' => today(), 'status' => 'ordered',
            'payment_status' => 'unpaid', 'total_amount' => 1000, 'balance_amount' => 1000, 'created_by' => $admin->id,
        ]);
        $purchase->items()->create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'purchase_unit_id' => $product->purchase_unit_id, 'stock_unit_id' => $product->unit_id,
            'purchase_conversion_factor' => $product->purchaseConversionFactor(),
            'ordered_quantity' => 1, 'received_quantity' => 0, 'cost_price' => 1000, 'line_total' => 1000,
        ]);
    });
    Volt::test('products.index')->call('deleteProduct', $product->id)->assertHasNoErrors();
    $notifications = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get();
    expect($notifications->pluck('notification_type')->all())->toBe(['purchase_order_created', 'product_deleted']);
    foreach ($notifications as $notification) {
        expect($notification->status)->toBe('queued')->and($notification->attempts)->toBe(0)->and($notification->sent_at)->toBeNull();
    }
    Http::fake(function ($request) use ($company) {
        expect(WhatsAppNotification::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'sending')->exists())->toBeTrue();

        return Http::response(str_contains($request->url(), '/user/check') ? ['results' => ['is_on_whatsapp' => true]] : ['results' => ['message_id' => 'EVENT-SENT']]);
    });
    auth()->logout();
    queueDeliveryWork();
    $this->travel(2)->seconds();
    queueDeliveryWork();
    foreach ($notifications as $notification) {
        expect($notification->refresh()->status)->toBe('sent')->and($notification->attempts)->toBe(1)->and($notification->sent_at)->not->toBeNull();
    }
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/file'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/message'));
});
