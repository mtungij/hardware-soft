<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Services\B2bDocumentPdfService;
use App\Services\B2bQuotationService;
use App\Services\QuotationDocumentService;
use App\Support\QuotationTemplateRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
    $this->company = $this->admin->company;
    $this->product = Product::firstOrFail();
    $this->customer = Customer::create([
        'company_id' => $this->company->id, 'branch_id' => $this->admin->branch_id,
        'name' => 'Template Customer', 'phone' => '255700000001', 'address' => 'Customer project site',
        'customer_type' => 'cash', 'status' => 'active',
    ]);
    $this->documents = app(QuotationDocumentService::class);
});

function templateQuotation(object $test, ?string $key = null, string $type = 'quotation'): Quotation
{
    return app(B2bQuotationService::class)->createDirect(
        $test->customer, $test->admin, $test->admin->branch_id,
        [['product_id' => $test->product->id, 'quantity' => 2]],
        $type, today()->addWeek(), (string) str()->uuid(),
        notes: 'Deliver to the customer project site.', terms: 'Stored quotation terms.', quotationTemplateKey: $key,
    );
}

test('registry exposes exactly twelve application owned quotation templates', function () {
    expect(array_keys(QuotationTemplateRegistry::all()))->toBe([
        'classic', 'modern_blue', 'corporate', 'minimal', 'bold_header', 'elegant',
        'construction', 'hardware_pro', 'compact', 'executive', 'clean_border', 'premium',
    ]);
    foreach (QuotationTemplateRegistry::all() as $key => $template) {
        expect($template['key'])->toBe($key)->and(view()->exists($template['view']))->toBeTrue();
    }
    expect(QuotationTemplateRegistry::saved(null)['key'])->toBe('classic')
        ->and(QuotationTemplateRegistry::saved('removed_template')['key'])->toBe('classic');
});

test('all designs render the same stored quotation values and generate PDFs for one ten and thirty five lines', function () {
    $quotation = templateQuotation($this, 'corporate');
    $quotation->items->first()->update(['product_name_snapshot' => str_repeat('LONG HARDWARE PRODUCT NAME ', 6)]);
    CompanyPaymentMethod::create([
        'display_name' => 'M-PESA', 'type' => 'mobile_money', 'account_name' => 'Configured account',
        'phone_or_business_number' => '123456', 'instructions' => 'Use quotation reference.',
        'is_active' => true, 'show_on_quotation' => true,
    ]);
    $quotation->refresh();
    $tables = ['quotations', 'quotation_items', 'stock_movements', 'sales', 'sale_items', 'sale_payments', 'customer_payments', 'expenses', 'customers', 'document_sequences'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $document = $this->documents->buildDocumentData($quotation);
    $htmls = [];
    foreach (QuotationTemplateRegistry::all() as $key => $template) {
        $html = $this->documents->html($document, $key);
        $htmls[] = $html;
        foreach ([$document['grand_total'], $document['items'][0]['total'], $document['items'][0]['unit'], $document['items'][0]['quantity'],
            $document['items'][0]['product'], ...array_column($document['totals'], 'value'), $quotation->quotation_number, 'Stored quotation terms.', 'M-PESA', 'Configured account', '123456', $this->company->company_name] as $value) {
            expect($html)->toContain($value);
        }
        $pdf = $this->documents->pdf($document, $key);
        expect($pdf)->toStartWith('%PDF-');
        expect(preg_match_all('~/Type\s*/Page\b~', $pdf))->toBeLessThanOrEqual(2);
        foreach ([10, 35] as $count) {
            $large = $this->documents->sample($this->company, $count);
            $pdf = $this->documents->pdf($large, $key);
            expect($pdf)->toStartWith('%PDF-');
            $pages = preg_match_all('~/Type\s*/Page\b~', $pdf);
            expect($pages)->toBeGreaterThanOrEqual($count === 35 ? 2 : 1)->toBeLessThanOrEqual(5);
        }
    }
    expect(array_unique($htmls))->toHaveCount(12);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
});

test('company defaults resolve on creation and cannot restyle existing or historical quotations', function () {
    $this->company->update(['quotation_template_key' => 'corporate']);
    $first = templateQuotation($this);
    $explicit = templateQuotation($this, 'hardware_pro');
    $this->company->update(['quotation_template_key' => 'modern_blue']);
    $next = templateQuotation($this, '');
    expect($first->fresh()->quotation_template_key)->toBe('corporate')
        ->and($explicit->quotation_template_key)->toBe('hardware_pro')
        ->and($next->quotation_template_key)->toBe('modern_blue');
    DB::table('quotations')->where('id', $first->id)->update(['quotation_template_key' => null]);
    $first->refresh();
    $before = $first->getRawOriginal();
    $this->get(route('quotations.preview', $first))->assertOk()->assertSee('Classic')->assertSee($first->quotation_number);
    $this->get(route('quotations.pdf', $first))->assertOk();
    expect($first->fresh()->getRawOriginal())->toBe($before);
});

test('invalid keys are rejected without transactions or arbitrary views', function ($key) {
    $count = Quotation::count();
    expect(fn () => templateQuotation($this, $key))->toThrow(ValidationException::class);
    expect(Quotation::count())->toBe($count);
    $this->get(route('settings.quotation-templates.preview', ['template' => $key]))->assertRedirect()->assertSessionHasErrors('quotation_template_key');
    session()->forget('errors');
    Volt::test('settings.quotation-templates')->call('setDefault', $key)->assertHasErrors('quotation_template_key');
})->with(['unregistered', 'pdf.b2b-sales-invoice']);

test('admin can choose only their own company default and previews never persist business records', function () {
    $other = $this->company->replicate();
    $other->company_name = 'Other Company';
    $other->save();
    $this->admin->syncRoles(['Admin']);
    $this->get(route('settings.quotation-templates'))->assertOk()->assertSee('Quotation Templates')->assertSee('Premium');
    $before = DB::table('document_sequences')->get()->toJson();
    $quotes = Quotation::count();
    foreach (QuotationTemplateRegistry::all() as $key => $template) {
        $this->get(route('settings.quotation-templates.preview', $key))->assertOk()->assertSee('SAMPLE PREVIEW')->assertSee($this->company->company_name)->assertDontSee('Other Company');
    }
    $this->get(route('settings.quotation-templates.preview', ['template' => 'premium', 'download' => true]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(DB::table('document_sequences')->get()->toJson())->toBe($before)
        ->and(Quotation::count())->toBe($quotes)
        ->and($this->company->fresh()->quotation_template_key)->toBe('classic');
    Volt::test('settings.quotation-templates')->call('setDefault', 'premium')->assertHasNoErrors();
    expect($this->company->fresh()->quotation_template_key)->toBe('premium')
        ->and($other->fresh()->quotation_template_key)->toBe('classic');
});

test('ordinary staff cannot change company templates or use settings previews', function ($role) {
    $this->admin->syncRoles([$role]);
    $this->get(route('settings.quotation-templates'))->assertForbidden();
    $this->get(route('settings.quotation-templates.preview', 'classic'))->assertForbidden();
    Volt::test('settings.quotation-templates')->call('setDefault', 'modern_blue')->assertForbidden();
    expect($this->company->fresh()->quotation_template_key)->toBe('classic');
})->with(['Cashier', 'Store Keeper']);

test('PDF and preview enforce both company and branch visibility', function () {
    $quotation = templateQuotation($this, 'executive');
    $this->get(route('quotations.pdf', $quotation))->assertOk()->assertDownload($quotation->quotation_number.'.pdf');
    expect($quotation->fresh()->pdf_path)->toBeNull();
    $this->get(route('quotations.preview', $quotation))->assertOk()->assertSee('Executive');
    $otherBranch = Branch::create(['company_id' => $this->company->id, 'name' => 'Restricted Branch', 'code' => 'QT-RESTRICTED', 'status' => 'active']);
    $role = Role::where('name', 'Admin')->firstOrFail();
    $role->forceFill(['report_scope' => 'branch'])->save();
    $this->admin->syncRoles([$role]);
    $this->admin->unsetRelation('roles');
    DB::table('quotations')->where('id', $quotation->id)->update(['branch_id' => $otherBranch->id]);
    foreach (['quotations.pdf', 'quotations.preview'] as $route) {
        $this->get(route($route, $quotation))->assertForbidden();
    }
    $other = $this->company->replicate();
    $other->company_name = 'Foreign Company';
    $other->save();
    DB::table('quotations')->where('id', $quotation->id)->update(['company_id' => $other->id]);
    foreach (['quotations.pdf', 'quotations.preview'] as $route) {
        $response = $this->get(route($route, $quotation));
        expect($response->status())->toBeIn([403, 404]);
    }
});

test('missing branding and payments collapse cleanly and cross company related records are rejected', function () {
    $quotation = templateQuotation($this);
    $data = $this->documents->buildDocumentData($quotation);
    expect($data['company']['logo'])->toBeNull()->and($data['payments'])->toBe([]);
    expect($this->documents->html($data, 'minimal'))->not->toContain('Payment Instructions')->not->toContain('alt="Company logo"');
    $other = $this->company->replicate();
    $other->save();
    // Super-admin scope must not permit mixing another company's related record.
    $quotation->customer->company_id = $other->id;
    expect(fn () => $this->documents->buildDocumentData($quotation))->toThrow(HttpException::class);
});

test('proformas retain their original renderer and no template selection', function () {
    $quotation = templateQuotation($this, 'premium', 'proforma');
    expect($quotation->quotation_template_key)->toBeNull();
    $path = app(B2bDocumentPdfService::class)->quotation($quotation);
    expect($path)->toEndWith($quotation->quotation_number.'.pdf')->and(Storage::disk('local')->get($path))->toStartWith('%PDF-');
    $this->get(route('quotations.preview', $quotation))->assertNotFound();
});

test('staff create screen persists an explicit template and shows it on details', function () {
    Volt::test('quotations.create')
        ->set('customer_id', (string) $this->customer->id)
        ->set('quotation_template_key', 'elegant')
        ->set('lines', [['product_id' => $this->product->id, 'product_unit_conversion_id' => '', 'quantity' => 2, 'unit_price' => '', 'discount_per_unit' => 0, 'tax_amount' => 0]])
        ->call('save')->assertHasNoErrors();
    $quotation = Quotation::latest('id')->firstOrFail();
    expect($quotation->quotation_template_key)->toBe('elegant');
    $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('Elegant')->assertSee('Preview');
});

test('repeated previews and downloads leave all business tables unchanged including historical rows', function () {
    $quotation = templateQuotation($this, 'corporate');
    DB::table('quotations')->where('id', $quotation->id)->update(['quotation_template_key' => null]);
    // Snapshot every application table, including all accounting and audit tables.
    $tables = collect(Schema::getTableListing())
        ->reject(fn ($table) => in_array($table, ['sessions', 'cache', 'cache_locks']))->values();
    $snapshot = fn () => $tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    for ($i = 0; $i < 2; $i++) {
        $this->get(route('quotations.preview', $quotation))->assertOk()->assertSee('Classic');
        $this->get(route('quotations.pdf', $quotation))->assertOk();
        $this->get(route('settings.quotation-templates.preview', ['template' => 'corporate', 'download' => 1]))->assertOk();
    }
    expect($snapshot())->toBe($before);
});

test('default selector persists the effective key and exposes every registered choice', function () {
    $this->company->update(['quotation_template_key' => 'corporate']);
    $component = Volt::test('quotations.create')->assertSee('Use Company Default');
    foreach (QuotationTemplateRegistry::all() as $template) {
        $component->assertSee($template['name']);
    }
    $component->set('customer_id', (string) $this->customer->id)
        ->set('quotation_template_key', '')
        ->set('lines', [['product_id' => $this->product->id, 'product_unit_conversion_id' => '', 'quantity' => 2, 'unit_price' => '', 'discount_per_unit' => 0, 'tax_amount' => 0]])
        ->call('save')->assertHasNoErrors();
    expect(Quotation::latest('id')->firstOrFail()->quotation_template_key)->toBe('corporate');
});

test('company A changes do not affect company B modern blue default', function () {
    $other = $this->company->replicate();
    $other->company_name = 'Company B';
    $other->quotation_template_key = 'modern_blue';
    $other->save();
    Volt::test('settings.quotation-templates')->call('setDefault', 'corporate')->assertHasNoErrors();
    expect($this->company->fresh()->quotation_template_key)->toBe('corporate')
        ->and($other->fresh()->quotation_template_key)->toBe('modern_blue')
        ->and(QuotationTemplateRegistry::forNew($other->id, null))->toBe('modern_blue');
});

test('configured large logo and missing logo render with optional payment sections', function () {
    Storage::fake('public');
    $logo = imagecreatetruecolor(2400, 600);
    ob_start();
    imagepng($logo);
    Storage::disk('public')->put('quotation-test-logo.png', ob_get_clean());
    imagedestroy($logo);
    $this->company->update(['logo' => 'quotation-test-logo.png']);
    $document = $this->documents->buildDocumentData(templateQuotation($this));
    expect($document['company']['logo'])->toStartWith('data:image/png;base64,');
    foreach (['classic', 'compact', 'premium'] as $key) {
        expect($this->documents->html($document, $key))->toContain('alt="Company logo"')->not->toContain('Payment Instructions');
        expect($this->documents->pdf($document, $key))->toStartWith('%PDF-');
    }
    $this->company->update(['logo' => 'missing-logo.png']);
    expect($this->documents->buildDocumentData(templateQuotation($this))['company']['logo'])->toBeNull();
});

test('registry rejects traversal and Blade paths at the rendering boundary', function ($key) {
    expect(fn () => $this->documents->html($this->documents->sample($this->company), $key))->toThrow(ValidationException::class);
})->with(['../classic', '../../pdf/b2b-quotation', 'documents.quotations.templates.classic', 'default']);

test('quotation and gallery routes require authentication', function () {
    $quotation = templateQuotation($this);
    auth()->logout();
    foreach ([route('settings.quotation-templates'), route('settings.quotation-templates.preview', 'classic'), route('quotations.preview', $quotation), route('quotations.pdf', $quotation)] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});
