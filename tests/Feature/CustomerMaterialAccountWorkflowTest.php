<?php

use App\Models\Branch;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerMaterialAccount;
use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\CustomerMaterialTransaction;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Services\CustomerMaterialAccountService;
use App\Services\CustomerMaterialIssueCommunicationService;
use App\Services\InventoryService;
use App\Services\WhatsAppMessageFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    app()->setLocale('en');
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->user);
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->customer = Customer::create([
        'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Juma', 'phone' => '0712000001', 'customer_type' => 'cash',
        'opening_balance' => 0, 'balance_amount' => 0, 'status' => 'active',
    ]);
    $this->location = StockLocation::create([
        'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Project Dispatch', 'code' => 'PROJECT-DISPATCH', 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
        'can_issue_stock' => true, 'can_sell' => true, 'is_sellable' => true,
    ]);
    $this->products = Product::with('unit')->where('status', 'active')->take(3)->get();
    expect($this->products)->toHaveCount(3);
    foreach ($this->products as $product) {
        $product->update(['buying_price' => 6000, 'selling_price' => 12000]);
        StockMovement::create([
            'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'stock_location_id' => $this->location->id,
            'movement_type' => 'direct_stock_in', 'quantity' => 200, 'quantity_in' => 200,
            'quantity_out' => 0, 'unit_cost' => 6000, 'unit_price' => 12000,
            'created_by' => $this->user->id, 'movement_date' => today(),
        ]);
    }
    $this->service = app(CustomerMaterialAccountService::class);
});

function createAcceptanceMaterialAccount($test): CustomerMaterialAccount
{
    return $test->service->create([
        'company_id' => $test->branch->company_id, 'branch_id' => $test->branch->id,
        'customer_id' => $test->customer->id, 'project_name' => 'House Construction', 'status' => 'active',
    ], [
        ['product_id' => $test->products[0]->id, 'planned_quantity' => 90, 'agreed_unit_price' => 10000],
        ['product_id' => $test->products[1]->id, 'planned_quantity' => 70, 'agreed_unit_price' => 10000],
        ['product_id' => $test->products[2]->id, 'planned_quantity' => 40, 'agreed_unit_price' => 10000],
    ], $test->user->id);
}

test('acceptance scenario supports partial funding and collection and blocks unfunded issue', function () {
    $account = createAcceptanceMaterialAccount($this);
    expect($account->plannedValue())->toBe(2000000.0)
        ->and(StockMovement::where('reference_type', CustomerMaterialAccount::class)->count())->toBe(0);

    $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'deposit-1');
    expect($account->availableFundedBalance())->toBe(500000.0);

    $before = app(InventoryService::class)->getProductStock($this->products[0]->id, $this->location->id, $this->branch->id);
    $issueOne = $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 25]], $this->location->id, [], $this->user->id, 'issue-1');
    expect($account->depositedAmount())->toBe(500000.0)
        ->and($account->issuedValue())->toBe(250000.0)
        ->and($account->availableFundedBalance())->toBe(250000.0)
        ->and(app(InventoryService::class)->getProductStock($this->products[0]->id, $this->location->id, $this->branch->id))->toBe($before - 25)
        ->and((float) $issueOne->total_cost)->toBe(150000.0);

    $this->service->recordDeposit($account, ['amount' => 300000, 'payment_method' => 'mobile_money'], $this->user->id, 'deposit-2');
    $this->service->issue($account, [['plan_line_id' => $account->planLines[1]->id, 'quantity' => 40]], $this->location->id, [], $this->user->id, 'issue-2');
    expect($account->depositedAmount())->toBe(800000.0)
        ->and($account->issuedValue())->toBe(650000.0)
        ->and($account->availableFundedBalance())->toBe(150000.0)
        ->and($account->remainingProjectCommitment())->toBe(1350000.0)
        ->and($account->fresh()->status)->toBe('active');

    expect(fn () => $this->service->issue($account, [['plan_line_id' => $account->planLines[2]->id, 'quantity' => 20]], $this->location->id, [], $this->user->id, 'unfunded'))
        ->toThrow(ValidationException::class, 'Insufficient funded balance');
});

test('deposit and issue idempotency prevent duplicate money stock and statement postings', function () {
    $account = createAcceptanceMaterialAccount($this);
    $firstDeposit = $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'bank'], $this->user->id, 'same-deposit');
    $secondDeposit = $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'bank'], $this->user->id, 'same-deposit');
    expect($secondDeposit->id)->toBe($firstDeposit->id)
        ->and(CustomerMaterialCashTransaction::where('idempotency_key', 'same-deposit')->count())->toBe(1);

    $line = $account->planLines[0];
    $firstIssue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 10]], $this->location->id, [], $this->user->id, 'same-issue');
    $secondIssue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 10]], $this->location->id, [], $this->user->id, 'same-issue');
    expect($secondIssue->id)->toBe($firstIssue->id)
        ->and(CustomerMaterialIssue::where('idempotency_key', 'same-issue')->count())->toBe(1)
        ->and(StockMovement::where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $firstIssue->id)->count())->toBe(1)
        ->and(CustomerMaterialTransaction::where('source_type', CustomerMaterialIssue::class)->where('source_id', $firstIssue->id)->count())->toBe(1);
});

test('unit and price snapshots remain historical while stock uses normalized base quantity', function () {
    $product = $this->products[0];
    $bag = Unit::where('company_id', $this->branch->company_id)
        ->where('measurement_type_id', $product->measurement_type_id)
        ->whereKeyNot($product->unit_id)
        ->firstOrFail();
    $conversion = ProductUnitConversion::create([
        'company_id' => $this->branch->company_id, 'product_id' => $product->id, 'unit_id' => $bag->id,
        'conversion_factor' => 50, 'retail_price' => 20000, 'can_sell' => true, 'active' => true,
    ]);
    $account = $this->service->create([
        'company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'project_name' => 'Bag Project', 'status' => 'active',
    ], [['product_id' => $product->id, 'product_unit_conversion_id' => $conversion->id, 'planned_quantity' => 3, 'agreed_unit_price' => 250000]], $this->user->id);
    $line = $account->planLines->first();
    $product->update(['selling_price' => 999999]);
    $conversion->update(['conversion_factor' => 40, 'retail_price' => 999999]);
    $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'bag-deposit');
    $issue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 2]], $this->location->id, [], $this->user->id, 'bag-issue');
    expect((float) $line->conversion_factor_snapshot)->toBe(50.0)
        ->and((float) $line->agreed_unit_price)->toBe(250000.0)
        ->and((float) $issue->lines->first()->base_quantity)->toBe(100.0)
        ->and((float) $issue->total_value)->toBe(500000.0)
        ->and((float) StockMovement::where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->value('quantity_out'))->toBe(100.0);
});

test('no deposit insufficient stock refunds and cancellation are enforced without deleting history', function () {
    $account = createAcceptanceMaterialAccount($this);
    expect(fn () => $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 1]], $this->location->id, [], $this->user->id, 'no-money'))
        ->toThrow(ValidationException::class, 'Insufficient funded balance');
    $this->service->recordDeposit($account, ['amount' => 1000000, 'payment_method' => 'cash'], $this->user->id, 'refund-deposit');
    $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 20]], $this->location->id, [], $this->user->id, 'refund-issue');
    expect(fn () => $this->service->refund($account, ['amount' => 900000, 'payment_method' => 'cash', 'reason' => 'Too much'], $this->user->id, 'bad-refund'))
        ->toThrow(ValidationException::class, 'cannot exceed');
    $this->service->refund($account, ['amount' => 300000, 'payment_method' => 'cash', 'reason' => 'Project reduction'], $this->user->id, 'good-refund');
    expect($account->availableFundedBalance())->toBe(500000.0);
    $transactionCount = $account->transactions()->count();
    $issueCount = $account->issues()->count();
    $this->service->cancel($account, 'Customer paused construction', $this->user->id);
    expect($account->fresh()->status)->toBe('cancelled')
        ->and($account->transactions()->count())->toBe($transactionCount)
        ->and($account->issues()->count())->toBe($issueCount);
});

test('material account pages reports and permissions are wired', function () {
    $account = createAcceptanceMaterialAccount($this);
    $this->get(route('customer-material-accounts.index'))->assertOk()->assertSee('Customer Material Accounts');
    $this->get(route('customer-material-accounts.create'))->assertOk()->assertSee('Agreed Material Plan');
    $this->withSession(['staff_locale' => 'en'])->get(route('customer-material-accounts.show', $account))->assertOk()->assertSee('Available Funded Balance')->assertSee('Material Plan Progress');
    $this->get(route('customer-material-accounts.reports'))->assertOk()->assertSee('Outstanding Material Commitments')->assertSee('Project Profitability');

    $cashier = User::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    expect($cashier->can('customer_material_accounts.record_deposit'))->toBeTrue()
        ->and($cashier->can('customer_material_accounts.refund'))->toBeFalse();
});

test('material account page clearly separates funded money from remaining plan value in both locales', function () {
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'clarity-deposit');
    $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 10]], $this->location->id, [], $this->user->id, 'clarity-issue');

    $this->withSession(['staff_locale' => 'en'])->get(route('customer-material-accounts.show', $account))
        ->assertOk()
        ->assertSeeText('Planned Material Value')
        ->assertSeeText('Available Funded Balance')
        ->assertSeeText('Remaining Planned Material Value')
        ->assertSeeText('Planned Qty')
        ->assertSeeText('Issued Qty')
        ->assertSeeText('Customer-agreed value deducted from the funded balance.')
        ->assertSeeText('Internal company cost for profit and accounting use only.');

    $this->withSession(['staff_locale' => 'sw'])->get(route('customer-material-accounts.show', $account))
        ->assertOk()
        ->assertSeeText('Idadi Iliyopangwa')
        ->assertSeeText('Iliyotolewa')
        ->assertSeeText('Iliyobaki')
        ->assertSeeText('Amana / Mkopo')
        ->assertSeeText('Bidhaa / Marejesho')
        ->assertSeeText('Salio la Fedha')
        ->assertSeeText('Thamani ya Mteja')
        ->assertSeeText('Gharama ya Ndani');
});

test('material issue action gives immediate feedback resets fields and refreshes all account sections', function () {
    app()->setLocale('en');
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'ux-deposit');
    $line = $account->planLines[0];
    $stockBefore = app(InventoryService::class)->getProductStock($line->product_id, $this->location->id, $this->branch->id);

    $component = Volt::test('customer-material-accounts.show', ['customerMaterialAccount' => $account])
        ->set('stock_location_id', (string) $this->location->id)
        ->set('issue_quantities', [$line->id => '10'])
        ->set('collected_by', 'Juma')
        ->set('issue_notes', 'First collection');
    $stockLocation = $component->get('stock_location_id');
    $submissionKey = $component->get('issue_key');

    $component->call('issueMaterials');
    $issue = CustomerMaterialIssue::query()->where('idempotency_key', $submissionKey)->firstOrFail();

    $component
        ->assertHasNoErrors()
        ->assertSet('issue_quantities', [])
        ->assertSet('collected_by', '')
        ->assertSet('issue_notes', '')
        ->assertSet('stock_location_id', $stockLocation)
        ->assertDispatched('hardex-notify', fn (string $event, array $payload) => $payload['tone'] === 'success'
            && $payload['title'] === 'Success'
            && str_contains($payload['message'], $issue->reference_number))
        ->assertSee('TZS 400,000')
        ->assertSee($issue->reference_number);

    expect(substr_count($component->html(), $issue->reference_number))->toBeGreaterThanOrEqual(2)
        ->and($line->fresh()->issuedQuantity())->toBe(10.0)
        ->and($line->fresh()->remainingQuantity())->toBe(80.0)
        ->and($account->availableFundedBalance())->toBe(400000.0)
        ->and($account->issuedValue())->toBe(100000.0)
        ->and(CustomerMaterialIssue::query()->where('idempotency_key', $submissionKey)->count())->toBe(1)
        ->and(CustomerMaterialTransaction::query()->where('source_type', CustomerMaterialIssue::class)->where('source_id', $issue->id)->count())->toBe(1)
        ->and(StockMovement::query()->where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->count())->toBe(1)
        ->and(app(InventoryService::class)->getProductStock($line->product_id, $this->location->id, $this->branch->id))->toBe($stockBefore - 10)
        ->and($component->get('issue_key'))->not->toBe($submissionKey);
});

test('failed material issue preserves user input and never emits success feedback or changes balances', function () {
    app()->setLocale('en');
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'ux-validation-deposit');
    $line = $account->planLines[0];
    $stockBefore = app(InventoryService::class)->getProductStock($line->product_id, $this->location->id, $this->branch->id);

    $component = Volt::test('customer-material-accounts.show', ['customerMaterialAccount' => $account])
        ->set('stock_location_id', (string) $this->location->id)
        ->set('issue_quantities', [$line->id => '60'])
        ->set('collected_by', 'Juma')
        ->set('issue_notes', 'Keep these values');
    $submissionKey = $component->get('issue_key');

    $component->call('issueMaterials')
        ->assertHasErrors(['funded_balance'])
        ->assertNotDispatched('hardex-notify')
        ->assertSet('issue_quantities', [$line->id => '60'])
        ->assertSet('collected_by', 'Juma')
        ->assertSet('issue_notes', 'Keep these values')
        ->assertSet('issue_key', $submissionKey);

    expect(CustomerMaterialIssue::query()->where('idempotency_key', $submissionKey)->exists())->toBeFalse()
        ->and($account->availableFundedBalance())->toBe(500000.0)
        ->and($account->issuedValue())->toBe(0.0)
        ->and($line->fresh()->issuedQuantity())->toBe(0.0)
        ->and(app(InventoryService::class)->getProductStock($line->product_id, $this->location->id, $this->branch->id))->toBe($stockBefore);
});

test('material issue feedback and loading labels render in English and Kiswahili', function () {
    $account = createAcceptanceMaterialAccount($this);

    $this->withSession(['staff_locale' => 'en'])->get(route('customer-material-accounts.show', $account))
        ->assertOk()
        ->assertSeeText('Post Material Issue')
        ->assertSeeText('Posting...');

    $this->withSession(['staff_locale' => 'sw'])->get(route('customer-material-accounts.show', $account))
        ->assertOk()
        ->assertSeeText('Toa Bidhaa')
        ->assertSeeText('Inahifadhi...');

    app()->setLocale('sw');
    expect(__('customer_material_accounts.material_issue.success_title'))->toBe('Imefanikiwa')
        ->and(__('customer_material_accounts.material_issue.success_with_reference', ['reference' => 'CMI-2026-000001']))->toBe('Bidhaa zimetolewa kwa mafanikio. Kumbukumbu: CMI-2026-000001')
        ->and(__('customer_material_accounts.material_issue.failure_message'))->toBe('Imeshindikana kutoa bidhaa. Tafadhali jaribu tena.');
});

test('funded balance validation follows the active English and Kiswahili locale', function () {
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 10000, 'payment_method' => 'cash'], $this->user->id, 'localized-balance-deposit');
    $line = $account->planLines[0];

    app()->setLocale('sw');
    expect(fn () => $this->service->issue($account, [], $this->location->id, [], $this->user->id, 'localized-empty'))
        ->toThrow(ValidationException::class, 'Chagua angalau bidhaa moja.');
    expect(fn () => $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 2]], $this->location->id, [], $this->user->id, 'localized-sw'))
        ->toThrow(ValidationException::class, 'Salio halitoshi. Umebakiwa na TZS 10,000 lakini bidhaa unazotaka kutoa zina thamani ya TZS 20,000.');

    app()->setLocale('en');
    expect(fn () => $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 2]], $this->location->id, [], $this->user->id, 'localized-en'))
        ->toThrow(ValidationException::class, 'Insufficient funded balance. Available: TZS 10,000. Requested material value: TZS 20,000.');
});

test('material issue receipt uses immutable snapshots and historical funded balances', function () {
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 80000, 'payment_method' => 'cash'], $this->user->id, 'receipt-deposit');
    $line = $account->planLines[0];
    $snapshotName = $line->product_name_snapshot;
    $issue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 5]], $this->location->id, ['collected_by' => 'James', 'notes' => 'Collect at counter'], $this->user->id, 'receipt-issue');
    $line->product->update(['name' => 'Renamed After Issue', 'selling_price' => 999999]);
    $issueBefore = $issue->fresh()->getRawOriginal();
    $stockPostings = StockMovement::query()->where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->count();
    $ledgerPostings = CustomerMaterialTransaction::query()->where('source_type', CustomerMaterialIssue::class)->where('source_id', $issue->id)->count();

    $this->withSession(['staff_locale' => 'en'])->get(route('customer-material-accounts.issue-document', $issue))
        ->assertOk()
        ->assertSeeText('MATERIAL ISSUE RECEIPT / RISITI YA UTOAJI BIDHAA')
        ->assertSeeText($issue->reference_number)
        ->assertSeeText($snapshotName)
        ->assertDontSeeText('Renamed After Issue')
        ->assertSeeText('5 '.$line->unit_code_snapshot)
        ->assertSeeText('TZS 50,000')
        ->assertSeeText('Previous Funded Balance:')
        ->assertSeeText('TZS 80,000')
        ->assertSeeText('Remaining Funded Balance:')
        ->assertSeeText('TZS 30,000')
        ->assertSeeText('James')
        ->assertSeeText('Collect at counter')
        ->assertSeeText('Print Receipt');

    $this->get(route('customer-material-accounts.show', $account))
        ->assertOk()
        ->assertSeeText('View')
        ->assertSeeText('Print')
        ->assertSeeText('WhatsApp');

    expect($issue->fresh()->getRawOriginal())->toBe($issueBefore)
        ->and(StockMovement::query()->where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->count())->toBe($stockPostings)
        ->and(CustomerMaterialTransaction::query()->where('source_type', CustomerMaterialIssue::class)->where('source_id', $issue->id)->count())->toBe($ledgerPostings);
});

test('customer material issue WhatsApp receipt respects company language and prevents duplicates', function (string $language, string $title, string $balanceLabel, string $attachment) {
    Queue::fake();
    Storage::fake('local');
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 80000, 'payment_method' => 'cash'], $this->user->id, 'wa-receipt-deposit-'.$language);
    $line = $account->planLines[0];
    $issue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 5]], $this->location->id, ['collected_by' => 'James'], $this->user->id, 'wa-receipt-issue-'.$language);
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $account->company_id], [
        'enabled' => true,
        'sending_paused' => false,
        'device_id' => 'material-device',
        'last_device_state' => 'logged_in',
        'whatsapp_notification_language' => $language,
        'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);
    $issueBefore = $issue->fresh()->getRawOriginal();
    $stockPostings = StockMovement::query()->where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->count();
    $ledgerPostings = CustomerMaterialTransaction::query()->where('source_type', CustomerMaterialIssue::class)->where('source_id', $issue->id)->count();

    $message = app(WhatsAppMessageFactory::class)->materialIssue($issue);
    $first = app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($issue);
    $second = app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($issue);

    expect($message)->toContain($title, $issue->reference_number, $line->product_name_snapshot, '5 '.$line->unit_code_snapshot, 'TZS 50,000', $balanceLabel, 'TZS 30,000', $attachment)
        ->and($first)->not->toBeNull()
        ->and($second?->id)->toBe($first?->id)
        ->and($first?->attachment_type)->toBe('file')
        ->and(Storage::disk('local')->exists((string) $first?->attachment_path))->toBeTrue()
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_material_issue_receipt')->count())->toBe(1)
        ->and($issue->fresh()->getRawOriginal())->toBe($issueBefore)
        ->and(StockMovement::query()->where('reference_type', CustomerMaterialIssue::class)->where('reference_id', $issue->id)->count())->toBe($stockPostings)
        ->and(CustomerMaterialTransaction::query()->where('source_type', CustomerMaterialIssue::class)->where('source_id', $issue->id)->count())->toBe($ledgerPostings);
})->with([
    'English' => ['en', 'MATERIALS ISSUED - HARDEX', 'New Balance: TZS 30,000', 'Material issue receipt'],
    'Kiswahili' => ['sw', 'BIDHAA ZIMETOLEWA - HARDEX', 'Salio Jipya: TZS 30,000', 'Risiti ya utoaji bidhaa'],
]);

test('customer material WhatsApp safely skips invalid phones and disabled notifications', function () {
    Queue::fake();
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 80000, 'payment_method' => 'cash'], $this->user->id, 'wa-skip-deposit');
    $issue = $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 1]], $this->location->id, [], $this->user->id, 'wa-skip-issue');
    $settings = CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $account->company_id], [
        'enabled' => false, 'sending_paused' => false, 'device_id' => 'material-device', 'last_device_state' => 'logged_in',
        'whatsapp_notification_language' => 'sw', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);

    expect(app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($issue))->toBeNull();
    $settings->update(['enabled' => true]);
    $this->customer->update(['phone' => '123']);
    $issue->unsetRelation('account');

    expect(app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($issue))->toBeNull()
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('notification_type', 'customer_material_issue_receipt')->exists())->toBeFalse();
});

test('customer material WhatsApp language is independent from UI locale and changes for future issues', function () {
    Queue::fake();
    Storage::fake('local');
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 200000, 'payment_method' => 'cash'], $this->user->id, 'wa-language-deposit');
    $line = $account->planLines[0];
    $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $account->company_id], [
        'enabled' => true, 'sending_paused' => false, 'device_id' => 'language-device', 'last_device_state' => 'logged_in',
        'whatsapp_notification_language' => 'en', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);

    app()->setLocale('sw');
    $englishIssue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 5]], $this->location->id, ['collected_by' => 'James'], $this->user->id, 'wa-language-en-issue');
    $english = app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($englishIssue);

    expect($english?->message)->toContain('MATERIALS ISSUED - HARDEX', 'Material issue receipt '.$englishIssue->reference_number.' is attached.')
        ->not->toContain('BIDHAA ZIMETOLEWA - HARDEX', 'Risiti ya utoaji bidhaa');

    $setting->update(['whatsapp_notification_language' => 'sw']);
    app()->setLocale('en');
    $swahiliIssue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 5]], $this->location->id, ['collected_by' => 'James'], $this->user->id, 'wa-language-sw-issue');
    $swahili = app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($swahiliIssue);

    expect($swahili?->message)->toContain('BIDHAA ZIMETOLEWA - HARDEX', 'Risiti ya utoaji bidhaa '.$swahiliIssue->reference_number.' imeambatanishwa.')
        ->not->toContain('MATERIALS ISSUED - HARDEX', 'Material issue receipt');

    app()->setLocale('sw');
    $swUiMessage = app(WhatsAppMessageFactory::class)->materialIssue($swahiliIssue, $setting->refresh());
    app()->setLocale('en');
    $enUiMessage = app(WhatsAppMessageFactory::class)->materialIssue($swahiliIssue, $setting->refresh());
    expect($swUiMessage)->toBe($enUiMessage)->toContain('BIDHAA ZIMETOLEWA - HARDEX');

    $setting->update(['whatsapp_notification_language' => 'en']);
    app()->setLocale('sw');
    $futureIssue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 5]], $this->location->id, [], $this->user->id, 'wa-language-future-issue');
    $future = app(CustomerMaterialIssueCommunicationService::class)->queueCustomerReceipt($futureIssue);
    expect($future?->message)->toContain('MATERIALS ISSUED - HARDEX')->not->toContain('BIDHAA ZIMETOLEWA - HARDEX');
});

test('customer material WhatsApp language safely falls back to English without adding another language field', function () {
    $account = createAcceptanceMaterialAccount($this);
    $this->service->recordDeposit($account, ['amount' => 50000, 'payment_method' => 'cash'], $this->user->id, 'wa-language-fallback-deposit');
    $issue = $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 1]], $this->location->id, [], $this->user->id, 'wa-language-fallback-issue');

    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $account->company_id)->delete();
    app()->setLocale('sw');
    $missing = app(WhatsAppMessageFactory::class)->materialIssue($issue);

    CompanyWhatsAppSetting::withoutGlobalScopes()->create([
        'company_id' => $account->company_id,
        'whatsapp_notification_language' => 'xx',
        'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    ]);
    $invalid = app(WhatsAppMessageFactory::class)->materialIssue($issue);

    expect($missing)->toContain('MATERIALS ISSUED - HARDEX', 'Material issue receipt')->not->toContain('BIDHAA ZIMETOLEWA - HARDEX')
        ->and($invalid)->toContain('MATERIALS ISSUED - HARDEX', 'Material issue receipt')->not->toContain('BIDHAA ZIMETOLEWA - HARDEX')
        ->and(Schema::hasColumn('company_whatsapp_settings', 'whatsapp_notification_language'))->toBeTrue()
        ->and(Schema::hasColumn('customer_material_accounts', 'whatsapp_notification_language'))->toBeFalse()
        ->and(Schema::hasColumn('customer_material_issues', 'whatsapp_notification_language'))->toBeFalse();
});

test('post transaction plan amendments require reasons and printable documents render', function () {
    $account = createAcceptanceMaterialAccount($this);
    $deposit = $this->service->recordDeposit($account, ['amount' => 500000, 'payment_method' => 'cash'], $this->user->id, 'document-deposit');
    $line = $account->planLines[0];
    expect(fn () => $this->service->amendPlanLine($line, 100, 11000, null, $this->user->id))
        ->toThrow(ValidationException::class, 'reason is required');
    $this->service->amendPlanLine($line, 100, 11000, 'Customer added ten units', $this->user->id);
    expect($line->fresh()->revision)->toBe(2)
        ->and((float) $line->fresh()->planned_line_total)->toBe(1100000.0)
        ->and($account->audits()->where('action', 'plan_line_amended')->exists())->toBeTrue();

    $issue = $this->service->issue($account, [['plan_line_id' => $line->id, 'quantity' => 10]], $this->location->id, ['collected_by' => 'Juma'], $this->user->id, 'document-issue');
    $this->get(route('customer-material-accounts.edit-plan', $account))->assertOk()->assertSee('Historical safety');
    $this->get(route('customer-material-accounts.deposit-receipt', $deposit))->assertOk()->assertSee('CUSTOMER MATERIAL DEPOSIT RECEIPT')->assertSee('does not represent a stock issue');
    $this->get(route('customer-material-accounts.issue-document', $issue))->assertOk()->assertSee('MATERIAL COLLECTION / ISSUE')->assertSee('Remaining Funded Balance')->assertDontSee('COGS');
});

test('account branch cannot post from a different branch or stock location', function () {
    $account = createAcceptanceMaterialAccount($this);
    $otherBranch = Branch::create(['company_id' => $this->branch->company_id, 'name' => 'Other Branch', 'code' => 'OTHER', 'status' => 'active']);
    $otherLocation = StockLocation::create(['company_id' => $this->branch->company_id, 'branch_id' => $otherBranch->id, 'name' => 'Other Store', 'code' => 'OTHER-STORE', 'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true, 'can_sell' => true, 'is_sellable' => true]);
    expect(fn () => $this->service->recordDeposit($account, ['amount' => 100000, 'payment_method' => 'cash', 'branch_id' => $otherBranch->id], $this->user->id, 'wrong-branch-deposit'))
        ->toThrow(ValidationException::class, 'must match');
    $this->service->recordDeposit($account, ['amount' => 100000, 'payment_method' => 'cash'], $this->user->id, 'right-branch-deposit');
    expect(fn () => $this->service->issue($account, [['plan_line_id' => $account->planLines[0]->id, 'quantity' => 1]], $otherLocation->id, [], $this->user->id, 'wrong-branch-issue'))
        ->toThrow(ValidationException::class, 'account branch');
});
