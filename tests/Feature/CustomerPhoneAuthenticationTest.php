<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPortalSecurityEvent;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Services\CustomerPortalCredentialService;
use App\Services\Gowa;
use App\Services\WhatsAppNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Http::fake();
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->company = Company::query()->findOrFail($this->admin->company_id);
    $this->branch = Branch::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(
        ['company_id' => $this->company->id],
        ['enabled' => true, 'sending_paused' => false, 'device_id' => 'portal-test-device', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES],
    );
});

function phoneCustomerData(array $overrides = []): array
{
    return array_merge([
        'branch_id' => test()->branch->id,
        'name' => 'ABC Contractors',
        'phone' => '0629 364 847',
        'email' => null,
        'address' => 'Dar es Salaam',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'customer_type' => 'contractor',
        'opening_balance' => 0,
        'balance_amount' => 0,
        'credit_limit' => 0,
        'status' => 'active',
    ], $overrides);
}

function createPhoneCustomer(string $operation = 'create-operation'): array
{
    return app(CustomerPortalCredentialService::class)->createCustomer(phoneCustomerData(), test()->admin, $operation);
}

function temporaryPasswordFor(CustomerAccount $account): string
{
    $notification = WhatsAppNotification::withoutGlobalScopes()->findOrFail($account->last_credentials_notification_id);
    preg_match('/Password ya Muda:\n([^\n]+)/', $notification->resolvedDeliveryMessage(), $matches);

    return $matches[1];
}

test('staff creates a normalized phone portal account without email and queues encrypted WhatsApp credentials', function () {
    $result = createPhoneCustomer();
    $customer = $result['customer'];
    $account = $result['account']->refresh();
    $notification = WhatsAppNotification::withoutGlobalScopes()->findOrFail($account->last_credentials_notification_id);
    $temporaryPassword = temporaryPasswordFor($account);

    expect($customer->email)->toBeNull()
        ->and($customer->phone)->toBe('255629364847')
        ->and($account->login_phone)->toBe('255629364847')
        ->and($account->email)->toBeNull()
        ->and($account->must_change_password)->toBeTrue()
        ->and(Hash::check($temporaryPassword, $account->password))->toBeTrue()
        ->and($account->getRawOriginal('password'))->not->toBe($temporaryPassword)
        ->and($notification->phone)->toBe('255629364847')
        ->and($notification->category)->toBe('customer_portal')
        ->and($notification->message)->toBe('Encrypted customer portal credential notification.')
        ->and(DB::table('whatsapp_notifications')->where('id', $notification->id)->value('encrypted_message'))->not->toContain($temporaryPassword)
        ->and($notification->resolvedDeliveryMessage())->toContain('ABC Contractors')->toContain('255629364847')->toContain($temporaryPassword)
        ->and(CustomerPortalSecurityEvent::withoutGlobalScopes()->where('event', 'portal_credentials_generated')->count())->toBe(1);
    Http::assertNothingSent();
});

test('credential job reloads and decrypts the real onboarding message only at the gowa boundary', function () {
    Cache::flush();
    $result = createPhoneCustomer('uat-delivery-regression');
    $account = $result['account']->refresh();
    $notification = WhatsAppNotification::withoutGlobalScopes()->findOrFail($account->last_credentials_notification_id);
    $temporaryPassword = temporaryPasswordFor($account);
    $rawEncryptedMessage = DB::table('whatsapp_notifications')->where('id', $notification->id)->value('encrypted_message');

    app('queue')->connection('database')->push(new SendWhatsAppNotification($notification->id), '', 'whatsapp');
    $jobPayload = DB::table('jobs')->latest('id')->value('payload');

    expect($notification->message)->toBe('Encrypted customer portal credential notification.')
        ->and($notification->message)->not->toContain($temporaryPassword)
        ->and($rawEncryptedMessage)->not->toBeNull()->not->toContain($temporaryPassword)
        ->and(json_encode($notification->metadata))->not->toContain($temporaryPassword)
        ->and($jobPayload)->toContain('notificationId')->toContain((string) $notification->id)
        ->and($jobPayload)->not->toContain($temporaryPassword)->not->toContain('Password ya Muda')
        ->and(CustomerPortalSecurityEvent::withoutGlobalScopes()->get()->toJson())->not->toContain($temporaryPassword)
        ->and(Hash::check($temporaryPassword, $account->password))->toBeTrue()
        ->and($account->getRawOriginal('password'))->not->toContain($temporaryPassword);

    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update([
        'last_device_state' => 'logged_in', 'minimum_send_interval_seconds' => 1,
        'maximum_messages_per_minute' => 100, 'maximum_messages_per_hour' => 100,
    ]);
    Http::swap(new HttpFactory);
    Http::fake([
        '*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'PORTAL-UAT-1']]),
    ]);

    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/send/message')
        && $request['phone'] === '255629364847'
        && str_contains($request['message'], 'ABC Contractors')
        && str_contains($request['message'], $this->company->company_name)
        && str_contains($request['message'], '255629364847')
        && str_contains($request['message'], $temporaryPassword)
        && str_contains($request['message'], '/customer/login')
        && str_contains($request['message'], 'badilisha password')
        && ! str_contains($request['message'], 'Encrypted customer portal credential notification.'));

    expect($notification->refresh()->status)->toBe('sent')
        ->and($notification->message)->toBe('Encrypted customer portal credential notification.')
        ->and(DB::table('whatsapp_notifications')->where('id', $notification->id)->value('encrypted_message'))->toBe($rawEncryptedMessage);
    $this->actingAs($this->admin)->get(route('settings.whatsapp.logs'))->assertOk()->assertDontSee($temporaryPassword);
});

test('credential retry decrypts the same password without rotating the account', function () {
    Cache::flush();
    $account = createPhoneCustomer('retry-original')['account']->refresh();
    $notification = WhatsAppNotification::withoutGlobalScopes()->findOrFail($account->last_credentials_notification_id);
    $temporaryPassword = temporaryPasswordFor($account);
    $credentialVersion = $account->credential_version;
    $passwordHash = $account->getRawOriginal('password');
    $encryptedPayload = DB::table('whatsapp_notifications')->where('id', $notification->id)->value('encrypted_message');
    $sentMessages = [];
    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['last_device_state' => 'logged_in']);

    Http::swap(new HttpFactory);
    Http::fake(function (Request $request) use (&$sentMessages) {
        if (str_contains($request->url(), '/user/check')) {
            return Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]);
        }
        if (str_ends_with($request->url(), '/send/message')) {
            $sentMessages[] = $request['message'];

            return count($sentMessages) === 1
                ? Http::response(['message' => 'temporary upstream error'], 500)
                : Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'PORTAL-RETRY-1']]);
        }

        return Http::response([], 404);
    });

    try {
        (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));
        $this->fail('Expected the first GOWA attempt to fail.');
    } catch (RequestException) {
    }

    expect($notification->refresh()->status)->toBe('queued');
    (new SendWhatsAppNotification($notification->id))->handle(app(Gowa::class));

    expect($sentMessages)->toHaveCount(2)
        ->and($sentMessages[0])->toBe($sentMessages[1])->toContain($temporaryPassword)
        ->and($sentMessages[1])->not->toContain('Encrypted customer portal credential notification.')
        ->and($notification->refresh()->status)->toBe('sent')
        ->and($account->refresh()->credential_version)->toBe($credentialVersion)
        ->and($account->getRawOriginal('password'))->toBe($passwordHash)
        ->and(DB::table('whatsapp_notifications')->where('id', $notification->id)->value('encrypted_message'))->toBe($encryptedPayload)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('id', $notification->id)->count())->toBe(1);
});

test('all credential sources resolve real protected messages while ordinary notifications stay unchanged', function () {
    Cache::flush();
    $service = app(CustomerPortalCredentialService::class);
    $account = createPhoneCustomer('all-flows-onboarding')['account']->refresh();
    $notificationIds = [$account->last_credentials_notification_id];

    $account = $service->enableOrReset($account->customer, $this->admin, 'all-flows-reset', true)->refresh();
    $notificationIds[] = $account->last_credentials_notification_id;
    $service->requestRecovery($account->login_phone, 'all-flows-recovery');
    $notificationIds[] = $account->refresh()->last_credentials_notification_id;

    $existing = Customer::withoutGlobalScopes()->create(phoneCustomerData([
        'name' => 'Existing Enable Customer', 'phone' => '0712 345 679', 'email' => null,
    ]) + ['company_id' => $this->company->id]);
    $enabled = $service->enableOrReset($existing, $this->admin, 'all-flows-enable')->refresh();
    $notificationIds[] = $enabled->last_credentials_notification_id;

    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update([
        'last_device_state' => 'logged_in', 'minimum_send_interval_seconds' => 1,
        'maximum_messages_per_minute' => 100, 'maximum_messages_per_hour' => 100,
    ]);
    Http::swap(new HttpFactory);
    Http::fake([
        '*/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]]),
        '*/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'ALL-FLOWS']]),
    ]);

    foreach ($notificationIds as $notificationId) {
        Cache::forget('whatsapp:last-send:'.hash('sha256', 'portal-test-device'));
        (new SendWhatsAppNotification((int) $notificationId))->handle(app(Gowa::class));
    }

    $types = WhatsAppNotification::withoutGlobalScopes()->whereIn('id', $notificationIds)->pluck('notification_type')->all();
    expect($types)->toContain('portal_onboarding', 'portal_password_reset', 'portal_password_recovery', 'portal_access_enabled');
    foreach (WhatsAppNotification::withoutGlobalScopes()->whereIn('id', $notificationIds)->get() as $credentialNotification) {
        expect($credentialNotification->failure_reason)->toBeNull()
            ->and($credentialNotification->status)->toBe('sent')
            ->and($credentialNotification->message)->toBe('Encrypted customer portal credential notification.')
            ->and($credentialNotification->resolvedDeliveryMessage())->toContain('Password ya Muda')->not->toContain('Encrypted customer portal credential notification.');
    }

    $ordinary = app(WhatsAppNotificationService::class)->queuePhone(
        $this->company,
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail(),
        '255764123456', 'system', 'ordinary_regression', 'ordinary:message', 'Ordinary HARDEX notification.',
    );
    expect($ordinary->resolvedDeliveryMessage())->toBe('Ordinary HARDEX notification.')
        ->and($ordinary->encrypted_message)->toBeNull();
});

test('credential placeholder fails closed when its encrypted payload is missing', function () {
    $notification = new WhatsAppNotification([
        'message' => WhatsAppNotification::SENSITIVE_MESSAGE_PLACEHOLDER,
        'encrypted_message' => null,
    ]);

    $notification->resolvedDeliveryMessage();
})->throws(RuntimeException::class, 'missing its encrypted delivery payload');

test('staff customer form requires phone but accepts missing email', function () {
    $this->actingAs($this->admin);
    Volt::test('customers.create')
        ->set('name', 'No Phone Customer')
        ->set('phone', '')
        ->set('email', '')
        ->call('save')
        ->assertHasErrors(['phone'])
        ->assertHasNoErrors(['email']);
});

test('portal login normalizes Tanzania phone formats rejects email and preserves remember behavior', function () {
    $account = createPhoneCustomer()['account']->refresh();
    $password = temporaryPasswordFor($account);
    RateLimiter::clear('customer-login:'.hash('sha256', $account->login_phone.'|127.0.0.1'));
    $this->withSession([]);

    Volt::test('customer.auth.login')
        ->set('phone', '0629 364 847')
        ->set('password', $password)
        ->set('remember', true)
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('customer.change-password', absolute: false));
    $this->assertAuthenticatedAs($account, 'customer');
    expect(auth('customer')->user()->getRememberToken())->not->toBeNull();

    auth('customer')->logout();
    Volt::test('customer.auth.login')
        ->set('phone', 'nobody@example.test')
        ->set('password', $password)
        ->call('login')
        ->assertHasErrors(['phone']);
});

test('wrong password is generic and repeated customer login attempts are throttled', function () {
    $account = createPhoneCustomer()['account']->refresh();
    $key = 'customer-login:'.hash('sha256', $account->login_phone.'|127.0.0.1');
    RateLimiter::clear($key);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        Volt::test('customer.auth.login')
            ->set('phone', '0629364847')
            ->set('password', 'definitely-wrong')
            ->call('login')
            ->assertHasErrors(['phone']);
    }

    Volt::test('customer.auth.login')
        ->set('phone', '255629364847')
        ->set('password', 'definitely-wrong')
        ->call('login')
        ->assertHasErrors(['phone']);
    expect(RateLimiter::tooManyAttempts($key, 5))->toBeTrue();
});

test('customer self registration uses normalized phone and optional email without forcing a generated password change', function () {
    Volt::test('customer.auth.register')
        ->set('name', 'Self Register')
        ->set('phone', '+255 713 456 789')
        ->set('email', '')
        ->set('password', 'SelfSecure@482731')
        ->set('password_confirmation', 'SelfSecure@482731')
        ->set('terms', true)
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('customer.pending', absolute: false));

    $account = CustomerAccount::withoutGlobalScopes()->where('login_phone', '255713456789')->firstOrFail();
    expect($account->email)->toBeNull()
        ->and($account->status)->toBe('pending')
        ->and($account->must_change_password)->toBeFalse()
        ->and(Hash::check('SelfSecure@482731', $account->password))->toBeTrue();
});

test('temporary password forces change then old password stops working and B2B portal remains accessible', function () {
    $account = createPhoneCustomer()['account']->refresh();
    $temporary = temporaryPasswordFor($account);
    $this->withSession([]);
    $this->actingAs($account, 'customer');

    $this->get(route('customer.purchase-requests.index'))->assertRedirect(route('customer.change-password'));
    Volt::test('customer.auth.change-password')
        ->set('current_password', $temporary)
        ->set('password', 'NewSecure@482731')
        ->set('password_confirmation', 'NewSecure@482731')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('customer.dashboard', absolute: false));

    $account->refresh();
    expect($account->must_change_password)->toBeFalse()
        ->and(Hash::check('NewSecure@482731', $account->password))->toBeTrue()
        ->and(Hash::check($temporary, $account->password))->toBeFalse()
        ->and(CustomerPortalSecurityEvent::withoutGlobalScopes()->where('event', 'customer_password_changed')->count())->toBe(1);
    $this->get(route('customer.purchase-requests.index'))->assertOk();
});

test('authorized reset is idempotent invalidates old password and allows a later legitimate reset', function () {
    $account = createPhoneCustomer()['account']->refresh();
    $original = temporaryPasswordFor($account);
    $service = app(CustomerPortalCredentialService::class);

    $reset = $service->enableOrReset($account->customer, $this->admin, 'same-reset-click', true)->refresh();
    $firstResetPassword = temporaryPasswordFor($reset);
    $service->enableOrReset($account->customer, $this->admin, 'same-reset-click', true);

    expect($reset->credential_version)->toBe(2)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('category', 'customer_portal')->count())->toBe(2)
        ->and(Hash::check($original, $reset->password))->toBeFalse()
        ->and(Hash::check($firstResetPassword, $reset->password))->toBeTrue();

    $later = $service->enableOrReset($account->customer, $this->admin, 'later-reset', true)->refresh();
    expect($later->credential_version)->toBe(3)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('category', 'customer_portal')->count())->toBe(3);
});

test('unauthorized staff cannot reset portal access', function () {
    $account = createPhoneCustomer()['account'];
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
    $cashier->assignRole('Cashier');

    app(CustomerPortalCredentialService::class)->enableOrReset($account->customer, $cashier, 'forbidden-reset', true);
})->throws(HttpException::class);

test('WhatsApp unavailability never rolls back customer registration', function () {
    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['device_id' => null]);
    $result = createPhoneCustomer('offline-create');
    $notification = WhatsAppNotification::withoutGlobalScopes()->findOrFail($result['account']->refresh()->last_credentials_notification_id);

    expect($result['customer']->exists)->toBeTrue()
        ->and($result['account']->exists)->toBeTrue()
        ->and($notification->status)->toBe('suppressed');
    Http::assertNothingSent();
});

test('an outbox exception is isolated after customer and portal account commit', function () {
    $whatsApp = Mockery::mock(WhatsAppNotificationService::class);
    $whatsApp->shouldReceive('queuePhone')->once()->andThrow(new RuntimeException('Outbox unavailable'));
    $service = new CustomerPortalCredentialService($whatsApp);

    $before = Customer::withoutGlobalScopes()->count();
    $result = $service->createCustomer(phoneCustomerData(), $this->admin, 'outbox-failure');

    expect(Customer::withoutGlobalScopes()->count())->toBe($before + 1)
        ->and($result['customer']->exists)->toBeTrue()
        ->and($result['account']->exists)->toBeTrue()
        ->and($result['account']->must_change_password)->toBeTrue();
});

test('global canonical phone uniqueness prevents ambiguous cross-company login and orphan customers', function () {
    createPhoneCustomer();
    $before = Customer::withoutGlobalScopes()->count();

    try {
        app(CustomerPortalCredentialService::class)->createCustomer(
            phoneCustomerData(['name' => 'Duplicate', 'phone' => '+255629364847']),
            $this->admin,
            'duplicate-create',
        );
        $this->fail('Expected duplicate portal phone validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('phone');
    }

    expect(Customer::withoutGlobalScopes()->count())->toBe($before)
        ->and(CustomerAccount::withoutGlobalScopes()->where('login_phone', '255629364847')->count())->toBe(1);
});

test('existing customer without email can be enabled without changing unrelated records', function () {
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Existing Customer',
        'phone' => '0712 345 678',
        'email' => null,
        'customer_type' => 'credit',
        'opening_balance' => 500,
        'balance_amount' => 500,
        'status' => 'active',
    ]);

    $account = app(CustomerPortalCredentialService::class)->enableOrReset($customer, $this->admin, 'enable-existing')->refresh();
    expect($account->login_phone)->toBe('255712345678')
        ->and($account->email)->toBeNull()
        ->and($account->must_change_password)->toBeTrue()
        ->and((float) $customer->refresh()->opening_balance)->toBe(500.0);
});

test('forgot password is generic and queues recovery only for a registered phone', function () {
    $account = createPhoneCustomer()['account']->refresh();
    $beforeVersion = $account->credential_version;

    Volt::test('customer.auth.forgot-password')
        ->set('phone', '255629364847')
        ->call('recover')
        ->assertSet('submitted', true);
    expect($account->refresh()->credential_version)->toBe($beforeVersion + 1);

    Volt::test('customer.auth.forgot-password')
        ->set('phone', '0711111111')
        ->call('recover')
        ->assertSet('submitted', true);
});
