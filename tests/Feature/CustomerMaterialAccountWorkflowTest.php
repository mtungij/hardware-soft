<?php

use App\Models\Branch;
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
use App\Services\CustomerMaterialAccountService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
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
    $this->get(route('customer-material-accounts.show', $account))->assertOk()->assertSee('Available Funded Balance')->assertSee('Material Plan Progress');
    $this->get(route('customer-material-accounts.reports'))->assertOk()->assertSee('Outstanding Material Commitments')->assertSee('Project Profitability');

    $cashier = User::factory()->create(['company_id' => $this->branch->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    expect($cashier->can('customer_material_accounts.record_deposit'))->toBeTrue()
        ->and($cashier->can('customer_material_accounts.refund'))->toBeFalse();
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
