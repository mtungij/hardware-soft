<?php

use App\Mail\CustomerPortalAccountCreatedMail;
use App\Mail\SupplierPurchaseOrderMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerNotification;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\CustomerPortalCredentialService;
use App\Services\ExcelExportService;
use App\Services\QuotationDocumentService;
use App\Services\WhatsAppLocalization;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppTemplateService;
use App\Support\QuotationTemplateRegistry;
use App\Support\UiText;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Livewire\Volt\Volt;

beforeEach(function () {
    Queue::fake();
});

test('canonical catalogs have matching keys and substitution placeholders', function () {
    foreach (glob(resource_path('lang/en/*.php')) as $path) {
        $group = basename($path, '.php');
        $en = Arr::dot(require $path);
        $sw = Arr::dot(require resource_path('lang/sw/'.$group.'.php'));
        expect(array_diff_key($en, $sw))->toBe([], $group.' missing Kiswahili keys')
            ->and(array_diff_key($sw, $en))->toBe([], $group.' missing English keys');
        foreach ($en as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            preg_match_all('/(?<![\w:]):[a-zA-Z_]+|{{\s*[a-zA-Z_]+\s*}}/', $value, $a);
            preg_match_all('/(?<![\w:]):[a-zA-Z_]+|{{\s*[a-zA-Z_]+\s*}}/', $sw[$key], $b);
            sort($a[0]);
            sort($b[0]);
            expect($b[0])->toBe($a[0], $group.'.'.$key);
        }
    }
    expect(app()->langPath())->toBe(resource_path('lang'));
});

test('staff screens switch headings actions and placeholders without mixed terminology', function (string $locale) {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($admin);
    Branch::query()->update(['name' => 'Kariakoo']);
    app()->setLocale($locale);

    foreach (['categories.index', 'quotations.index', 'customer-requests.index', 'settings.whatsapp', 'help-center.index'] as $screen) {
        $html = Volt::test($screen)->html();
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        if ($locale === 'sw') {
            preg_match_all('/\b(Create|Edit|Delete|Save|Cancel|Search|Status|Customer|Supplier|Branch|Dashboard|Settings|Quotation)\b/', $text, $matches);
            expect(array_unique($matches[0]))->toBe([], $screen);
        } else {
            expect($text)->not->toMatch('/\b(Tengeneza|Hariri|Futa|Hifadhi|Ghairi|Tafuta|Mteja|Msambazaji|Tawi|Dashibodi|Mipangilio)\b/');
        }
        expect($html)->not->toContain('application.');
    }
})->with(['en', 'sw']);

test('statuses use translated labels and unknown codes never leak into Kiswahili', function () {
    app()->setLocale('sw');
    foreach (['active' => 'Hai', 'inactive' => 'Isiyotumika', 'paid' => 'Imelipwa', 'pending' => 'Inasubiri', 'completed' => 'Imekamilika', 'cancelled' => 'Imeghairiwa', 'sent' => 'Imetumwa', 'failed' => 'Imeshindikana'] as $code => $label) {
        expect(UiText::status($code))->toBe($label);
    }
    expect(UiText::status('unexpected_internal_code'))->toBe('Haijulikani')
        ->and(UiText::status('LOW STOCK'))->toBe('HISA CHACHE')
        ->and(UiText::status('OUT OF STOCK'))->toBe('BIDHAA ZIMEISHA');
    app()->setLocale('en');
    expect(UiText::status('completed'))->toBe('Completed')
        ->and(UiText::translate('Hifadhi'))->toBe('Save');
});

test('Laravel validation rules render in the selected language', function (string $locale) {
    app()->setLocale($locale);
    $errors = Validator::make(['email' => 'invalid', 'password' => 'x', 'amount' => -1], ['email' => 'email', 'password' => 'min:8', 'amount' => 'numeric|gt:0'])->errors()->all();
    expect(implode(' ', $errors))->not->toContain('validation.');
    if ($locale === 'sw') {
        expect(implode(' ', $errors))->not->toMatch('/\b(The|field|must|email|password|amount)\b/');
    } else {
        expect(implode(' ', $errors))->not->toMatch('/\b(lazima|nenosiri|kiasi|barua)\b/');
    }
})->with(['en', 'sw']);

function strictReportPayload(): array
{
    return [
        'title' => UiText::translate('Sales Report'),
        'header' => ['company_name' => 'English Company Ltd', 'logo' => null, 'phone' => '255700123456', 'whatsapp' => '', 'email' => '', 'address' => '', 'region' => '', 'district' => '', 'date_range' => '2026-10-01 / 2026-10-14', 'branch_name' => 'Kariakoo', 'printed_by' => 'James', 'printed_date' => '2026-10-14'],
        'headers' => [UiText::translate('Product'), UiText::translate('Total')],
        'rows' => [['English Hammer', '123,000']],
        'totals' => [UiText::translate('Total Amount') => '123,000'],
    ];
}

test('PDF report and streamed exports follow the chosen language without altering business data', function (string $locale) {
    app()->setLocale($locale);
    $payload = strictReportPayload();
    $html = view('exports.pdf-report', $payload)->render();
    expect($html)->toContain('English Company Ltd', 'English Hammer', '123,000')
        ->not->toContain('application.');
    if ($locale === 'sw') {
        expect(strip_tags($html))->toContain('Bidhaa', 'Jumla', 'Tawi', 'Imeandaliwa na')
            ->not->toMatch('/\b(Product|Total|Printed|Prepared|Branch)\b/');
    } else {
        expect(strip_tags($html))->toContain('Product', 'Total', 'Branch', 'Prepared By')
            ->not->toMatch('/\b(Bidhaa|Jumla|Tawi|Imeandaliwa)\b/');
    }
    $response = app(ExcelExportService::class)->generateExcel('report.xls', $payload);
    app()->setLocale($locale === 'sw' ? 'en' : 'sw');
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain($locale === 'sw' ? 'Imehamishwa na' : 'Exported By');
})->with(['en', 'sw']);

test('WhatsApp templates use the company language independently of the UI', function (string $locale) {
    app()->setLocale($locale === 'sw' ? 'en' : 'sw');
    $company = Company::create(['company_name' => 'English Company Ltd', 'business_type' => 'hardware', 'phone' => '255700123456', 'whatsapp_number' => '255700123456', 'timezone' => 'Africa/Dar_es_Salaam']);
    CompanyWhatsAppSetting::withoutGlobalScopes()->create(['company_id' => $company->id, 'whatsapp_notification_language' => $locale]);
    $localization = app(WhatsAppLocalization::class);
    foreach (['sale_completed', 'sale_cancelled', 'customer_payment_received', 'purchase_order_created', 'goods_received_grn', 'production_completed'] as $type) {
        $body = $localization->get($company, 'templates.'.$type);
        if ($locale === 'sw') {
            expect($body)->not->toMatch('/\b(Product|SKU|Branch|Customer|Total|Date|Stock|Cash|Order|Payment)\b/');
        } else {
            expect($body)->not->toMatch('/\b(Bidhaa|Tawi|Mteja|Jumla|Tarehe|Hisa|Taslimu|Malipo)\b/');
        }
    }
    $product = new Product(['name' => 'English Hammer', 'sku' => 'SKU-ABC']);
    $product->setRelation('branch', null);
    $actor = new User(['name' => 'James']);
    $body = app(WhatsAppMessageFactory::class)->productDeleted($product, $company, $actor);
    expect($body)->toContain('English Hammer', 'SKU-ABC', 'James', 'English Company Ltd')
        ->toContain($locale === 'sw' ? 'Amefuta:' : 'Deleted By:');
})->with(['en', 'sw']);

test('portal registration WhatsApp preserves an unescaped URL and credentials', function (string $locale) {
    $company = Company::create(['company_name' => 'English Company Ltd', 'business_type' => 'hardware', 'phone' => '255700123456', 'whatsapp_number' => '255700123456']);
    CompanyWhatsAppSetting::withoutGlobalScopes()->create(['company_id' => $company->id, 'whatsapp_notification_language' => $locale]);
    config(['app.customer_portal_url' => 'https://customer.buildcore.site']);
    $customer = new Customer(['name' => 'James Customer']);
    $account = new CustomerAccount(['name' => 'James Customer', 'login_phone' => '255700123456']);
    $method = new ReflectionMethod(CustomerPortalCredentialService::class, 'credentialMessage');
    $body = $method->invoke(app(CustomerPortalCredentialService::class), $company, $customer, $account, 'Password-ABC');
    expect($body)->toContain('https://customer.buildcore.site/customer/login', 'James Customer', 'Password-ABC', '255700123456')
        ->not->toContain('https\\:', 'https\\/', 'whatsapp.');
    if ($locale === 'sw') {
        expect($body)->not->toMatch('/\b(Portal|Password|Customer|Login|Stock|Cash)\b(?=:|\n)/');
    }
})->with(['en', 'sw']);

test('account emails retain the recipient language when rendered later', function (string $locale) {
    $company = new Company(['company_name' => 'English Company Ltd']);
    $account = new CustomerAccount(['name' => 'James', 'email' => 'james@example.test', 'preferred_locale' => $locale]);
    $mail = new CustomerPortalAccountCreatedMail(new Customer(['name' => 'James']), $account, 'Pass-ABC', 'https://customer.buildcore.site/customer/login', $company, null);
    app()->setLocale($locale === 'sw' ? 'en' : 'sw');
    $html = $mail->render();
    expect($html)->toContain('James', 'Pass-ABC', 'james@example.test', 'https://customer.buildcore.site/customer/login')
        ->not->toContain('mail.portal.', 'application.');
    expect(strip_tags($html))->toContain($locale === 'sw' ? 'Fungua Tovuti ya Wateja' : 'Open Customer Portal');
    expect($mail->envelope()->subject)->toBe($locale === 'sw' ? 'Karibu kwenye Tovuti ya Wateja ya English Company Ltd' : 'Welcome to English Company Ltd Customer Portal');
})->with(['en', 'sw']);

test('scoped document locale is restored even on failure', function () {
    app()->setLocale('en');
    try {
        UiText::withLocale('sw', function () {
            expect(app()->getLocale())->toBe('sw');
            throw new RuntimeException('test failure');
        });
    } catch (RuntimeException) {
        expect(app()->getLocale())->toBe('en');
    }
});

test('WhatsApp template editing keeps each language separate', function () {
    $company = Company::create(['company_name' => 'Company', 'business_type' => 'hardware', 'phone' => '255700123456', 'whatsapp_number' => '255700123456']);
    $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->create(['company_id' => $company->id, 'whatsapp_notification_language' => 'sw']);
    $templates = app(WhatsAppTemplateService::class);
    $templates->seedDefaults($company);
    $template = WhatsAppTemplate::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'production_completed')->firstOrFail();
    expect($templates->bodyForLanguage($template, 'sw'))->not->toContain('Product:', 'Branch:');
    $templates->saveBodyForLanguage($template, 'sw', 'Bidhaa: {{product}}');
    expect($templates->bodyForLanguage($template, 'en'))->toContain('Product:');
    app()->setLocale('en');
    expect($templates->render($company, 'production_completed', ['product' => 'English Hammer']))->toBe('Bidhaa: English Hammer');
    $setting->update(['whatsapp_notification_language' => 'en']);
    expect($templates->render($company, 'production_completed', ['product' => 'English Hammer']))->toContain('Product: English Hammer')->not->toContain('Bidhaa:');
});

test('stored feedback translates surrounding text while keeping names and diagnostics intact', function () {
    $original = 'Cancelled by James';
    app()->setLocale('sw');
    expect(UiText::feedback($original))->toBe('Imeghairiwa na James')
        ->and($original)->toBe('Cancelled by James')
        ->and(UiText::feedback('HTTP upstream connection error'))->toBe('Shughuli haikuweza kukamilika. Tafadhali jaribu tena.');
    app()->setLocale('en');
    expect(UiText::feedback('Imeghairiwa na James'))->toBe('Cancelled by James');
});

test('nested validation and system customers localize without changing stored names', function () {
    app()->setLocale('sw');
    $errors = Validator::make(['items' => [['product_id' => 'bad']]], ['items.*.product_id' => 'integer'])->errors()->all();
    expect(implode(' ', $errors))->not->toContain('product', 'items', 'field');
    $system = new Customer(['name' => 'Mteja wa Mkopo Ambaye Hajatajwa', 'is_unassigned_credit_customer' => true]);
    $real = new Customer(['name' => 'English Customer Ltd']);
    expect($system->displayName('en'))->toBe('Unassigned Credit Customer')
        ->and($system->name)->toBe('Mteja wa Mkopo Ambaye Hajatajwa')
        ->and($real->displayName('sw'))->toBe('English Customer Ltd');
});

test('supplier email copy retains the selected language for delayed rendering', function (string $locale) {
    app()->setLocale($locale);
    $purchase = new Purchase(['reference_number' => 'PO-001', 'purchase_date' => '2026-10-14', 'total_amount' => 123000]);
    $purchase->setRelation('supplier', new Supplier(['name' => 'English Supplier Ltd']));
    $purchase->setRelation('items', collect());
    $settings = new Setting(['company_name' => 'English Company Ltd', 'currency' => 'TZS']);
    $mail = new SupplierPurchaseOrderMail($purchase, $settings, 'pdf');
    app()->setLocale($locale === 'sw' ? 'en' : 'sw');
    $html = $mail->render();
    expect($html)->toContain('PO-001', 'English Supplier Ltd', '123,000')->not->toContain('purchases.', 'mail.purchase.');
    if ($locale === 'sw') {
        expect(strip_tags($html))->toContain('Agizo la Ununuzi', 'Msimbo wa Bidhaa', 'Haki zote zimehifadhiwa')
            ->not->toMatch('/\b(Purchase|Order|Product|SKU|Quantity|Cost|Total|Regards)\b/');
    } else {
        expect(strip_tags($html))->toContain('Purchase Order', 'SKU', 'All rights reserved');
    }
})->with(['en', 'sw']);

test('offline shells can be cached in both languages independently of the session locale', function () {
    $this->withSession(['staff_locale' => 'en'])->get('/offline?locale=sw')->assertOk()->assertSee('Uko nje ya mtandao kwa sasa.')->assertSee('lang="sw"', false)->assertDontSee('You are currently offline.');
    $this->withSession(['staff_locale' => 'sw'])->get('/offline?locale=en')->assertOk()->assertSee('You are currently offline.')->assertSee('lang="en"', false)->assertDontSee('Uko nje ya mtandao kwa sasa.');
});

test('customer API validation follows the explicit supported locale', function () {
    $response = $this->withHeader('X-Locale', 'sw')->postJson('/api/customer/login', []);
    $response->assertUnprocessable();
    expect(implode(' ', Arr::flatten($response->json('errors'))))->not->toMatch('/\b(The|field|required|phone|password)\b/');
});

test('quotation designs translate totals and template descriptions without translating customer data', function () {
    app()->setLocale('sw');
    $company = new Company(['company_name' => 'English Company Ltd']);
    $document = app(QuotationDocumentService::class)->sample($company);
    $html = app(QuotationDocumentService::class)->html($document, 'classic');
    expect(strip_tags($html))->toContain('English Company Ltd', 'Jumla', 'Punguzo', 'Kodi')->not->toContain('Subtotal', 'Discount', 'Prepared By', 'Sample Branch');
    expect(QuotationTemplateRegistry::require('classic')['name'])->toBe('Ya Kawaida');
});

test('portal system notices follow the viewing locale without rewriting history or rejection reasons', function () {
    $notice = new CustomerNotification(['type' => 'deposit_approved', 'title' => 'Amana Yako Imethibitishwa', 'message' => 'Amana yako ya TZS 123,000 imethibitishwa.']);
    app()->setLocale('en');
    expect($notice->displayTitle())->toBe('Your Deposit Was Approved')
        ->and($notice->displayMessage())->toBe('Your deposit of TZS 123,000 was approved.')
        ->and($notice->message)->toBe('Amana yako ya TZS 123,000 imethibitishwa.');
    $notice->type = 'deposit_rejected';
    $notice->message = 'Your deposit was rejected. Reason: James needs receipt ABC';
    app()->setLocale('sw');
    expect($notice->displayMessage())->toBe('Amana yako imekataliwa. Sababu: James needs receipt ABC');
    $announcement = new CustomerNotification(['type' => 'announcement', 'title' => 'Project ABC', 'message' => 'Customer-authored business text']);
    expect($announcement->displayMessage())->toBe('Customer-authored business text');
});

test('Kiswahili system catalogs contain none of the forbidden English UI words', function () {
    foreach (glob(resource_path('lang/sw/*.php')) as $path) {
        foreach (Arr::dot(require $path) as $key => $phrase) {
            if (! is_string($phrase)) {
                continue;
            }
            $copy = preg_replace('/:[a-zA-Z_]+|{{.*?}}/', '', $phrase);
            preg_match_all('/\b(Create|Edit|Delete|Save|Cancel|Search|Status|Total|Product|Customer|Supplier|Purchase|Sale|Stock|Branch|Report|Login|Logout|Dashboard|Settings|Cash|Portal|Discount|Mould)\b/i', $copy, $matches);
            expect($matches[0])->toBe([], basename($path).'.'.$key);
        }
    }
});

test('permission labels translate while submitted permission codes and grants stay unchanged', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($admin);
    app()->setLocale('sw');
    $before = DB::table('role_has_permissions')->count();
    $html = Volt::test('roles.index')->html();
    expect(strip_tags($html))->toContain('Angalia Bidhaa')->not->toContain('View Products');
    expect($html)->toContain('value="view products"');
    expect(DB::table('role_has_permissions')->count())->toBe($before);
});
