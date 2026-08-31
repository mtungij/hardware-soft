<?php

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppDailySummaryService;
use App\Services\WhatsAppLocalization;
use App\Services\WhatsAppNotificationService;
use App\Services\WhatsAppStockAlertService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

beforeEach(function () {
    Queue::fake();
});

function languageTestCompany(string $name): Company
{
    return Company::query()->create([
        'company_name' => $name,
        'business_type' => 'hardware',
        'phone' => '2557'.random_int(10000000, 99999999),
        'whatsapp_number' => '2557'.random_int(10000000, 99999999),
        'timezone' => 'Africa/Dar_es_Salaam',
    ]);
}

function languageTestSetting(Company $company, string $language = 'en', array $overrides = []): CompanyWhatsAppSetting
{
    return CompanyWhatsAppSetting::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'enabled' => true,
        'sending_paused' => false,
        'device_id' => 'language-device-'.$company->id,
        'timezone' => 'Africa/Dar_es_Salaam',
        'whatsapp_notification_language' => $language,
        'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
        'last_device_state' => 'logged_in',
    ], $overrides));
}

function languageTestRecipient(Company $company, string $phone = '255764100001'): WhatsAppRecipient
{
    return WhatsAppRecipient::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Language Recipient',
        'phone' => $phone,
        'scope' => 'company',
        'active' => true,
        'categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);
}

function languageTestDailyData(Company $company, string $language): array
{
    return [
        'company_name' => $company->company_name,
        'notification_language' => $language,
        'scope_label' => $company->company_name,
        'report_date' => '2026-08-31',
        'sales' => ['total' => 24289050, 'cash' => 24289050, 'credit' => 0, 'transactions' => 32],
        'top_products' => [['name' => 'Mabati Gauge 28', 'quantity' => 7, 'amount' => 12626000]],
    ];
}

test('company without a whatsapp language setting defaults to English', function () {
    $company = languageTestCompany('English Default Hardware');

    expect(app(WhatsAppLocalization::class)->language($company))->toBe('en')
        ->and(app(WhatsAppDailySummaryService::class)->message(languageTestDailyData($company, 'en')))
        ->toContain('HARDEX DAILY SUMMARY', 'Sales: TZS 24,289,050', '31 Aug 2026');
});

test('admin can save Kiswahili as the company WhatsApp notification language', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($admin);

    Volt::test('settings.whatsapp')
        ->assertSee('Notification Language')
        ->assertSee('Controls the language used for automatic WhatsApp notifications and report captions.')
        ->set('whatsapp_notification_language', 'sw')
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $admin->company_id)->value('whatsapp_notification_language'))->toBe('sw');
});

test('stock alert is generated fully in Kiswahili with a localized date', function () {
    $company = languageTestCompany('mikoposoft');
    languageTestSetting($company, 'sw');
    $recipient = languageTestRecipient($company);
    $rows = collect([(object) ['status' => 'OUT OF STOCK'], (object) ['status' => 'OUT OF STOCK'], (object) ['status' => 'LOW STOCK']]);

    $message = app(WhatsAppStockAlertService::class)->message($company, $recipient, CarbonImmutable::parse('2026-08-31 03:00'), $rows, true, true);

    expect($message)->toContain('TAARIFA YA STOCK - HARDEX')
        ->toContain('Bidhaa 3 zinahitaji uangalizi.')
        ->toContain('Zilizoisha Stock: 2')
        ->toContain('Stock Ndogo: 1')
        ->toContain('Eneo: mikoposoft (Maeneo yote yaliyoruhusiwa)')
        ->toContain('Imetengenezwa: 31 Ago 2026 03:00')
        ->toContain('Ripoti kamili ya bidhaa zenye stock ndogo au zilizoisha imeambatanishwa.')
        ->not->toContain('stock items require attention', 'Out of Stock:', 'Generated:');
});

test('daily summary is generated fully in Kiswahili without translating stored names or values', function () {
    $company = languageTestCompany('mikoposoft');
    languageTestSetting($company, 'sw');

    $message = app(WhatsAppDailySummaryService::class)->message(languageTestDailyData($company, 'sw'));

    expect($message)->toContain('MUHTASARI WA SIKU - HARDEX', 'mikoposoft', '31 Ago 2026')
        ->toContain('Mauzo: TZS 24,289,050', 'Idadi ya Miamala: 32')
        ->toContain('Mauzo ya Cash: TZS 24,289,050', 'Mauzo ya Mkopo: TZS 0')
        ->toContain('Bidhaa Iliyouzwa Zaidi: Mabati Gauge 28 — TZS 12,626,000')
        ->not->toContain('Sales:', 'Transactions:', 'Top Product:');
});

test('stored product customer and company names remain unchanged', function () {
    $company = languageTestCompany('English Company Name Ltd');
    languageTestSetting($company, 'sw');
    $data = languageTestDailyData($company, 'sw');
    $data['scope_label'] = 'English Company Name Ltd';
    $data['top_products'][0]['name'] = 'Customer Special Cement 50kg';

    $message = app(WhatsAppDailySummaryService::class)->message($data);

    expect($message)->toContain('English Company Name Ltd', 'Customer Special Cement 50kg')
        ->and($data['top_products'][0]['name'])->toBe('Customer Special Cement 50kg');
});

test('English stock alert remains unchanged when English is selected', function () {
    $company = languageTestCompany('English Alert Hardware');
    languageTestSetting($company, 'en');
    $recipient = languageTestRecipient($company);
    $rows = collect([(object) ['status' => 'OUT OF STOCK'], (object) ['status' => 'LOW STOCK']]);

    $message = app(WhatsAppStockAlertService::class)->message($company, $recipient, CarbonImmutable::parse('2026-08-31 03:00'), $rows, true, true);

    expect($message)->toContain('HARDEX STOCK ALERT', '2 stock items require attention.', 'Out of Stock: 1', 'Low Stock: 1', 'Generated: 31 Aug 2026 03:00');
});

test('company WhatsApp languages remain isolated', function () {
    $swCompany = languageTestCompany('Kampuni A');
    $enCompany = languageTestCompany('Company B');
    languageTestSetting($swCompany, 'sw');
    languageTestSetting($enCompany, 'en');

    $service = app(WhatsAppDailySummaryService::class);
    $sw = $service->message(languageTestDailyData($swCompany, app(WhatsAppLocalization::class)->language($swCompany)));
    $en = $service->message(languageTestDailyData($enCompany, app(WhatsAppLocalization::class)->language($enCompany)));

    expect($sw)->toContain('MUHTASARI WA SIKU - HARDEX')->not->toContain('HARDEX DAILY SUMMARY')
        ->and($en)->toContain('HARDEX DAILY SUMMARY')->not->toContain('MUHTASARI WA SIKU - HARDEX');
});

test('scheduled summary resolves company language instead of current UI locale', function () {
    app()->setLocale('en');
    $company = languageTestCompany('Scheduled Kiswahili Hardware');
    languageTestSetting($company, 'sw');
    languageTestRecipient($company);

    $this->artisan('whatsapp:daily-summary', ['--company' => $company->id, '--date' => '2026-08-31', '--force' => true])->assertSuccessful();

    $message = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $company->id)->where('notification_type', 'daily_management_summary')->value('message');
    expect($message)->toContain('MUHTASARI WA SIKU - HARDEX', '31 Ago 2026')->not->toContain('HARDEX DAILY SUMMARY');
});

test('invalid company notification language falls back to English', function () {
    $company = languageTestCompany('Invalid Language Hardware');
    $setting = languageTestSetting($company, 'en');
    CompanyWhatsAppSetting::withoutGlobalScopes()->whereKey($setting->id)->update(['whatsapp_notification_language' => 'xx']);

    expect(app(WhatsAppLocalization::class)->language($company))->toBe('en')
        ->and(app(WhatsAppLocalization::class)->get($company, 'daily_summary.title'))->toBe('HARDEX DAILY SUMMARY');
});

test('changing language does not rewrite existing WhatsApp logs', function () {
    $company = languageTestCompany('Historical Message Hardware');
    $setting = languageTestSetting($company, 'en');
    $recipient = languageTestRecipient($company);
    $notification = app(WhatsAppNotificationService::class)->queueRecipient(
        $company, $setting, $recipient, 'daily_summary', 'historical_test', 'historical-language-test', 'Original English historical message',
    );

    $setting->update(['whatsapp_notification_language' => 'sw']);

    expect($notification->fresh()->message)->toBe('Original English historical message');
});
