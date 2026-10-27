<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPayment;
use App\Models\CustomerReceipt;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CashbookService;
use App\Services\InventoryService;
use App\Services\PaymentMethodService;
use App\Services\ReportExportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Dynamic payments A', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->otherCompany = Company::create(['company_name' => 'Dynamic payments B', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
    $this->otherBranch = Branch::create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
    $this->foreignBranch = Branch::create(['company_id' => $this->otherCompany->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'status' => 'active']);
    Setting::create(['company_id' => $this->company->id, 'company_name' => 'Dynamic payments A', 'enable_warehouse' => false, 'inventory_mode' => 'single_location']);
    foreach (['payment_methods.view', 'payment_methods.manage', 'sales.view', 'receive sale payments', 'receive customer payments', 'pay suppliers', 'approve customer receipts', 'view expenses', 'create expenses', 'export expenses'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role = Role::create(['company_id' => $this->company->id, 'name' => 'Super Admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->admin->assignRole($role);
    $this->customer = Customer::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Payment customer', 'phone' => '255700001111', 'customer_type' => 'credit', 'credit_limit' => 100000, 'status' => 'active']);
    $this->method = PaymentMethod::create(['company_id' => $this->company->id, 'display_name' => 'M-Pesa', 'type' => 'mobile_money', 'is_active' => true]);
    $this->foreignMethod = PaymentMethod::create(['company_id' => $this->otherCompany->id, 'display_name' => 'Foreign Bank', 'type' => 'bank', 'is_active' => true]);
    $this->branchMethod = PaymentMethod::create(['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id, 'display_name' => 'Other branch card', 'type' => 'card', 'is_active' => true]);
    $this->category = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Transport', 'status' => 'active']);
    $unit = Unit::create(['company_id' => $this->company->id, 'name' => 'Piece', 'short_name' => 'pcs', 'status' => 'active']);
    $productCategory = Category::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Products', 'code' => 'DYNAMIC', 'status' => 'active']);
    $this->product = Product::create(['category_id' => $productCategory->id, 'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'unit_id' => $unit->id, 'name' => 'Dynamic payment product', 'sku' => 'DP-1', 'buying_price' => 50, 'selling_price' => 100, 'wholesale_price' => 100, 'status' => 'active']);
    $this->location = StockLocation::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => 'DYNAMIC', 'name' => 'Counter', 'type' => 'dispensing', 'is_active' => true, 'status' => 'active', 'can_sell' => true, 'is_sellable' => true, 'can_receive_stock' => true, 'can_issue_stock' => true]);
    StockMovement::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_location_id' => $this->location->id, 'movement_type' => 'direct_stock_in', 'quantity' => 20, 'quantity_in' => 20, 'quantity_out' => 0, 'unit_cost' => 50, 'created_by' => $this->admin->id, 'movement_date' => today()]);
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
});

function dynamicPaymentSale($test, array $payments, ?string $token = null): Sale
{
    return app(InventoryService::class)->completeSale([['product_id' => $test->product->id, 'quantity' => 1, 'unit_price' => 100, 'discount_amount' => 0, 'tax_amount' => 0]], $payments, $test->customer->id, $test->location->id, $test->branch->id, $test->admin->id, idempotencyKey: $token ?: (string) str()->uuid());
}

test('payment management creates edits orders defaults and deactivates methods without deleting history', function () {
    $this->get(route('settings.payment-methods'))->assertOk()->assertSee('M-Pesa')->assertDontSee('Foreign Bank');
    Volt::test('settings.payment-methods')->set('display_name', 'Visa/Mastercard')->set('type', 'card')
        ->set('account_name', 'Card account')->set('account_number', '1234')->set('instructions', 'Use the terminal')->set('sort_order', 2)->set('is_default', true)->call('save')->assertHasNoErrors();
    $card = PaymentMethod::where('display_name', 'Visa/Mastercard')->firstOrFail();
    expect(PaymentMethodService::defaultValue())->toBe('method:'.$card->id)
        ->and(PaymentMethod::where('company_id', $this->company->id)->where('is_default', true)->count())->toBe(1);
    Volt::test('settings.payment-methods')->call('edit', $card->id)->set('display_name', 'Card Terminal')->set('is_default', false)->set('is_active', false)->call('save')->assertHasNoErrors();
    expect($card->fresh()->is_active)->toBeFalse()->and(DB::table('payment_methods')->where('id', $card->id)->exists())->toBeTrue();
    Volt::test('settings.payment-methods')->set('display_name', 'Invalid Credit')->set('type', 'credit')->call('save')->assertHasErrors('type');
});

test('unauthorized and foreign catalog writes and branch assignments are rejected', function () {
    expect(fn () => Volt::test('settings.payment-methods')->call('edit', $this->foreignMethod->id))->toThrow(ModelNotFoundException::class);
    Volt::test('settings.payment-methods')->set('display_name', 'Bad branch')->set('branch_id', (string) $this->foreignBranch->id)->call('save')->assertHasErrors('branch_id');
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->givePermissionTo('payment_methods.view');
    $this->actingAs($user);
    Volt::test('settings.payment-methods')->set('display_name', 'Forged')->call('save')->assertForbidden();
    $user->revokePermissionTo('payment_methods.view');
    $this->actingAs($user->fresh())->get(route('settings.payment-methods'))->assertForbidden();
});

test('sales accept split payments and preserve stock and payment idempotency', function () {
    $token = (string) str()->uuid();
    $payments = [['payment_method' => 'cash', 'amount' => 40], ['payment_method' => 'method:'.$this->method->id, 'amount' => 60]];
    $sale = dynamicPaymentSale($this, $payments, $token);
    $again = dynamicPaymentSale($this, $payments, $token);
    expect($again->id)->toBe($sale->id)->and($sale->payments)->toHaveCount(2)
        ->and((float) $sale->payments()->sum('amount'))->toBe(100.0)
        ->and($sale->payments->last()->payment_method_name_snapshot)->toBe('M-Pesa')
        ->and(StockMovement::where('reference_type', Sale::class)->where('reference_id', $sale->id)->where('movement_type', 'sale_out')->count())->toBe(1);
});

test('noncash split overpayment is rejected without creating a sale or moving stock', function () {
    expect(fn () => dynamicPaymentSale($this, [['payment_method' => 'method:'.$this->method->id, 'amount' => 101]]))->toThrow(ValidationException::class);
    expect(fn () => dynamicPaymentSale($this, [['payment_method' => 'cash', 'amount' => 20], ['payment_method' => 'method:'.$this->method->id, 'amount' => 101]]))->toThrow(ValidationException::class);
    expect(Sale::count())->toBe(0)->and(StockMovement::count())->toBe(1);
});

test('cash tender change is excluded from received payment and reconciliation totals', function () {
    $sale = dynamicPaymentSale($this, [['payment_method' => 'cash', 'amount' => 100], ['payment_method' => 'method:'.$this->method->id, 'amount' => 40]]);
    expect((float) $sale->change_amount)->toBe(40.0)->and((float) $sale->paid_amount)->toBe(100.0)
        ->and((float) $sale->payments()->sum('amount'))->toBe(100.0)
        ->and((float) $sale->payments()->where('payment_method', 'cash')->sum('amount'))->toBe(60.0);
    expect(app(CashbookService::class)->totals($this->branch->id, today()->toDateString())['cash_sales'])->toBe(60.0);
});

test('invoice payments reject overpayment instead of silently truncating the amount', function () {
    $sale = dynamicPaymentSale($this, [['payment_method' => 'credit', 'amount' => 100]]);
    Volt::test('sales.payments', ['sale' => $sale])->set('amount', '101')->call('receivePayment')->assertHasErrors('amount');
    expect($sale->payments()->count())->toBe(0)->and((float) $sale->fresh()->balance_amount)->toBe(100.0);
});

test('credit and partial payment are balances and debt settlement uses a registered method once', function () {
    $sale = dynamicPaymentSale($this, [['payment_method' => 'method:'.$this->method->id, 'amount' => 25], ['payment_method' => 'credit', 'amount' => 75]]);
    expect($sale->payments)->toHaveCount(1)->and((float) $sale->balance_amount)->toBe(75.0)->and($sale->payments()->where('payment_method', 'credit')->exists())->toBeFalse();
    $data = ['branch_id' => $this->branch->id, 'amount' => 75, 'payment_method' => 'method:'.$this->method->id, 'payment_date' => today(), 'payment_submission_key' => (string) str()->uuid()];
    $first = app(AccountingService::class)->receiveCustomerPayment($this->customer, $data, $this->admin->id);
    $again = app(AccountingService::class)->receiveCustomerPayment($this->customer, $data, $this->admin->id);
    expect($again->id)->toBe($first->id)->and((float) $sale->fresh()->balance_amount)->toBe(0.0)
        ->and($first->paymentMethodLabel())->toBe('M-Pesa')->and($sale->payments()->count())->toBe(2);
});

test('cross tenant branch inactive and nonfinite payments cannot change stock', function () {
    $this->method->update(['is_active' => false]);
    foreach ([$this->foreignMethod, $this->branchMethod, $this->method] as $method) {
        expect(fn () => dynamicPaymentSale($this, [['payment_method' => 'method:'.$method->id, 'amount' => 100]]))->toThrow(ValidationException::class);
    }
    foreach ([-1, INF, NAN] as $amount) {
        expect(fn () => dynamicPaymentSale($this, [['payment_method' => 'cash', 'amount' => $amount]]))->toThrow(ValidationException::class);
    }
    expect(Sale::count())->toBe(0)->and(StockMovement::count())->toBe(1);
});

test('supplier payments use registered methods and replay without reducing balances twice', function () {
    $supplier = Supplier::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Supplier', 'phone' => '255700008888', 'status' => 'active']);
    $purchase = Purchase::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id, 'purchase_date' => today(), 'reference_number' => 'PAY-PO', 'status' => 'ordered', 'payment_status' => 'unpaid', 'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100, 'created_by' => $this->admin->id]);
    $data = ['branch_id' => $this->branch->id, 'amount' => 60, 'payment_method' => 'method:'.$this->method->id, 'payment_date' => today(), 'payment_submission_key' => (string) str()->uuid()];
    $first = app(AccountingService::class)->paySupplier($supplier, $data, $this->admin->id);
    $again = app(AccountingService::class)->paySupplier($supplier, $data, $this->admin->id);
    expect($again->id)->toBe($first->id)->and((float) $purchase->fresh()->balance_amount)->toBe(40.0)->and($first->paymentMethodLabel())->toBe('M-Pesa');
});

test('historical labels and reporting survive rename deactivation and type changes', function () {
    $sale = dynamicPaymentSale($this, [['payment_method' => 'method:'.$this->method->id, 'amount' => 100]]);
    $payment = $sale->payments->first();
    $snapshot = $payment->getAttributes();
    $this->method->update(['display_name' => 'Renamed Provider', 'type' => 'other', 'is_active' => false]);
    expect($payment->fresh()->getAttributes())->toBe($snapshot)->and($payment->fresh()->paymentMethodLabel())->toBe('M-Pesa');
    $request = request()->duplicate(['payment_method' => 'method:'.$this->method->id]);
    $request->setUserResolver(fn () => $this->admin);
    $payload = app(ReportExportService::class)->build('tables.sales-items', $request);
    expect(json_encode($payload['rows']))->toContain('M-Pesa')->not->toContain('Renamed Provider');
    expect((float) app(CashbookService::class)->totals($this->branch->id, today()->toDateString())['cash_sales'])->toBe(0.0);
});

test('portal payment proof uses active tenant methods and snapshots before staff approval', function () {
    Storage::fake('local');
    $sale = dynamicPaymentSale($this, [['payment_method' => 'credit', 'amount' => 100]]);
    $account = CustomerAccount::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Portal payer', 'phone' => '255700009999', 'password' => 'password', 'status' => 'active']);
    $this->app['auth']->forgetGuards();
    $this->actingAs($account, 'customer');
    Volt::test('customer.receipts.create')->set('sale_id', (string) $sale->id)->set('amount', '50')->set('payment_method', 'method:'.$this->foreignMethod->id)->set('receipt_file', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))->call('save')->assertHasErrors('payment_method');
    Volt::test('customer.receipts.create')->set('sale_id', (string) $sale->id)->set('amount', '50')->set('payment_method', 'method:'.$this->method->id)->set('receipt_file', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))->call('save')->assertHasNoErrors();
    $receipt = CustomerReceipt::withoutGlobalScopes()->firstOrFail();
    expect($receipt->paymentMethodLabel())->toBe('M-Pesa')->and($receipt->status)->toBe('pending')->and((float) $sale->fresh()->balance_amount)->toBe(100.0);
    $this->method->update(['is_active' => false, 'display_name' => 'Renamed']);
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin, 'web');
    Volt::test('admin.customer-receipts.show', ['customerReceipt' => $receipt])->call('approve')->assertHasNoErrors();
    expect((float) $sale->fresh()->balance_amount)->toBe(50.0)->and(CustomerPayment::firstOrFail()->paymentMethodLabel())->toBe('M-Pesa');
});

test('cash debt collections reconcile once rather than counting their sale allocation twice', function () {
    $sale = dynamicPaymentSale($this, [['payment_method' => 'credit', 'amount' => 100]]);
    app(AccountingService::class)->receiveCustomerPayment($this->customer, ['branch_id' => $this->branch->id, 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => today(), 'payment_submission_key' => (string) str()->uuid()], $this->admin->id);
    $totals = app(CashbookService::class)->totals($this->branch->id, today()->toDateString());
    expect($totals['cash_sales'])->toBe(0.0)->and($totals['customer_payments'])->toBe(40.0)->and($totals['cash_in'])->toBe(40.0)
        ->and((float) $sale->fresh()->balance_amount)->toBe(60.0);
});

test('catalog and receipt settings are translated and inactive transaction options are hidden', function () {
    $this->method->update(['is_active' => false]);
    $this->get(route('expenses.index'))->assertOk()->assertDontSee('>M-Pesa</option>', false)->assertDontSee('Foreign Bank');
    $this->withSession(['staff_locale' => 'sw'])->get(route('settings.payment-methods'))->assertOk()->assertSee('Njia za Malipo');
    expect(PaymentMethod::withoutGlobalScopes()->where('company_id', $this->otherCompany->id)->where('legacy_code', 'cash')->where('is_default', true)->exists())->toBeTrue();
});

test('additive catalog backfill preserves legacy IDs amounts credit markers and supports a safe retry', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.payment_backfill' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('payment_backfill');
    try {
        Schema::create('companies', fn ($table) => $table->id());
        Schema::create('branches', fn ($table) => $table->id());
        Schema::create('company_payment_methods', function ($table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('display_name');
        });
        $tables = ['sale_payments', 'customer_payments', 'supplier_payments', 'expenses', 'customer_receipts', 'customer_deposits', 'customer_material_cash_transactions', 'goods_receipt_additional_costs'];
        foreach ($tables as $name) {
            Schema::create($name, function ($table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('payment_method');
                $table->decimal('amount', 15, 2);
            });
        }
        DB::table('companies')->insert(['id' => 28]);
        DB::table('company_payment_methods')->insert(['id' => 85, 'company_id' => 28, 'type' => 'bank', 'display_name' => 'CRDB Bank']);
        DB::table('sale_payments')->insert([['id' => 100, 'company_id' => 28, 'payment_method' => 'cash', 'amount' => 100], ['id' => 101, 'company_id' => 28, 'payment_method' => 'credit', 'amount' => 50]]);
        $migration = require database_path('migrations/2026_10_27_000001_centralize_payment_methods.php');
        $migration->up();
        $migration->up();
        expect(DB::table('payment_methods')->where('id', 85)->value('display_name'))->toBe('CRDB Bank')
            ->and(DB::table('company_payment_methods')->count())->toBe(1)
            ->and(DB::table('sale_payments')->count())->toBe(2)
            ->and((float) DB::table('sale_payments')->where('id', 100)->value('amount'))->toBe(100.0)
            ->and(DB::table('sale_payments')->where('id', 100)->value('payment_method_name_snapshot'))->toBe('Cash')
            ->and(DB::table('sale_payments')->where('id', 101)->value('payment_method'))->toBe('credit')
            ->and(DB::table('sale_payments')->where('id', 101)->value('payment_method_id'))->toBeNull()
            ->and(DB::table('payment_methods')->where('company_id', 28)->count())->toBe(4);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('payment_backfill');
    }
});

test('portal API lists only available methods and idempotently accepts proof with a named method', function () {
    Storage::fake('local');
    $sale = dynamicPaymentSale($this, [['payment_method' => 'credit', 'amount' => 100]]);
    $account = CustomerAccount::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'API payer', 'phone' => '255700007777', 'login_phone' => '255700007777', 'password' => 'password', 'status' => 'active']);
    $token = $account->createToken('Payment proof')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->withHeader('Accept', 'application/json');
    $this->getJson('/api/customer/payment-methods')->assertOk()->assertSee('M-Pesa')->assertDontSee('Foreign Bank')->assertDontSee('Other branch card');
    $key = (string) str()->uuid();
    $this->withHeader('Idempotency-Key', $key);
    $data = ['invoice_id' => $sale->id, 'amount' => 50, 'payment_method' => 'method:'.$this->method->id, 'reference_number' => 'DYNAMIC-PROOF-1', 'receipt' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')];
    $first = $this->post('/api/customer/receipts', $data)->assertCreated()->assertJsonPath('payment_method_name', 'M-Pesa');
    $this->post('/api/customer/receipts', $data)->assertOk()->assertJsonPath('id', $first->json('id'));
    expect(CustomerReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and((float) $sale->fresh()->balance_amount)->toBe(100.0);
});

test('staff payment context never falls back to a simultaneous customer session company', function () {
    $this->app['auth']->forgetGuards();
    $staff = User::factory()->create(['company_id' => null, 'branch_id' => null, 'status' => 'active']);
    $account = CustomerAccount::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Separate customer session', 'phone' => '255700006666', 'password' => 'password', 'status' => 'active']);
    auth('customer')->setUser($account);
    $this->actingAs($staff, 'web');
    expect(PaymentMethodService::context())->toBe([0, null])->and(PaymentMethodService::defaultValue())->toBe('');
});

test('POS credit control works without an active money method and never creates a credit payment', function () {
    PaymentMethod::where('company_id', $this->company->id)->update(['is_active' => false]);
    $component = Volt::test('pos.index')->set('stock_location_id', (string) $this->location->id)->set('customer_id', (string) $this->customer->id)
        ->set('cart', [['product_id' => $this->product->id, 'stock_location_id' => $this->location->id, 'name' => $this->product->name, 'sku' => $this->product->sku, 'sale_type' => 'retail', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'tax_amount' => '0']])
        ->call('addCreditBalance')->assertSet('payments.0.payment_method', 'credit')->assertSet('payments.0.amount', '100');
    $token = $component->get('submission_token');
    $component->call('completeSale')->assertHasNoErrors();
    $sale = Sale::where('idempotency_key', $token)->firstOrFail();
    expect((float) $sale->paid_amount)->toBe(0.0)->and((float) $sale->balance_amount)->toBe(100.0)->and($sale->payments()->count())->toBe(0);
});

test('forged branch selections never reveal another branch methods to branch-restricted staff', function () {
    $staff = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $staff->givePermissionTo('create expenses');
    $this->actingAs($staff);
    expect(PaymentMethodService::available($this->company->id, $this->otherBranch->id)->count())->toBe(0);
    Volt::test('expenses.index')->set('branch_id', (string) $this->otherBranch->id)->assertDontSee('Other branch card')
        ->set('expense_category_id', (string) $this->category->id)->set('amount', '10')->set('payment_method', 'method:'.$this->branchMethod->id)
        ->call('save')->assertHasErrors('branch_id');
});

test('payment catalog rejects foreign companies and enforces restricted staff branch context', function () {
    $staff = User::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->actingAs($staff);

    expect(PaymentMethodService::available($this->otherCompany->id)->count())->toBe(0)
        ->and(PaymentMethodService::available($this->company->id, $this->otherBranch->id)->count())->toBe(0)
        ->and(PaymentMethodService::available($this->company->id)->whereKey($this->branchMethod->id)->exists())->toBeFalse()
        ->and(PaymentMethodService::available($this->company->id, $this->branch->id)->whereKey($this->method->id)->exists())->toBeTrue();

    $this->actingAs($this->admin);

    expect(PaymentMethodService::available($this->company->id, $this->otherBranch->id)
        ->whereKey($this->branchMethod->id)->exists())->toBeTrue();
});

test('customer payment catalog cannot access another company directly', function () {
    $account = CustomerAccount::create([
        'company_id' => $this->company->id,
        'customer_id' => $this->customer->id,
        'name' => 'Catalog customer',
        'phone' => '255700004444',
        'password' => 'password',
        'status' => 'active',
    ]);

    $this->app['auth']->forgetGuards();
    auth('customer')->setUser($account);

    $this->app['router']->getRoutes()->refreshNameLookups();

    $this->app->instance('request', request()->duplicate(
        server: ['REQUEST_URI' => '/customer/payment-methods']
    ));

    expect(PaymentMethodService::available($this->otherCompany->id)->count())->toBe(0);
});

test('portal API method availability follows the owned invoice branch', function () {
    Storage::fake('local');
    $location = StockLocation::create(['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id, 'code' => 'CARD-COUNTER', 'name' => 'Card counter', 'type' => 'dispensing']);
    $sale = Sale::create(['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id, 'stock_location_id' => $location->id, 'customer_id' => $this->customer->id, 'sale_number' => 'CARD-INVOICE', 'sale_date' => today(), 'sale_type' => 'retail', 'subtotal' => 100, 'total_amount' => 100, 'paid_amount' => 0, 'balance_amount' => 100, 'payment_status' => 'unpaid', 'status' => 'completed', 'sold_by' => $this->admin->id, 'created_by' => $this->admin->id]);
    $account = CustomerAccount::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Branch API payer', 'phone' => '255700005555', 'password' => 'password', 'status' => 'active']);
    $token = $account->createToken('Branch proof')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->withHeader('Accept', 'application/json');
    $this->getJson('/api/customer/payment-methods?invoice_id='.$sale->id)->assertOk()->assertSee('Other branch card')->assertDontSee('Foreign Bank');
    $this->post('/api/customer/receipts', ['invoice_id' => $sale->id, 'amount' => 50, 'payment_method' => 'method:'.$this->branchMethod->id, 'receipt' => UploadedFile::fake()->create('branch-proof.pdf', 10, 'application/pdf')])->assertCreated()->assertJsonPath('payment_method_name', 'Other branch card');
});
