<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceivingNote;
use App\Models\InternalSale;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesInvoice;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Support\BranchAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->staff = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->staff->forceFill(['is_system_owner' => false])->save();
    $this->branchA = Branch::findOrFail($this->staff->branch_id);
    $this->branchB = Branch::create(['company_id' => $this->staff->company_id, 'name' => 'Private Branch B', 'code' => 'PRIVATE-B', 'status' => 'active']);
    $this->otherCompany = Company::create(['company_name' => 'Other Tenant', 'business_type' => 'hardware', 'phone' => '123', 'whatsapp_number' => '123']);
    $this->branchC = Branch::create(['company_id' => $this->otherCompany->id, 'name' => 'Other Tenant Branch', 'code' => 'OTHER-TENANT', 'status' => 'active']);
    $this->foreignUnit = Unit::create(['company_id' => $this->otherCompany->id, 'name' => 'Other tenant unit', 'code' => 'OTHER-TENANT-UNIT', 'short_name' => 'otu', 'status' => 'active']);
    $this->rows = [];
    foreach ([$this->branchA, $this->branchB, $this->branchC] as $branch) {
        $user = User::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => 'active']);
        $location = StockLocation::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'Location '.$branch->code, 'code' => 'LOC-'.$branch->code, 'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_transfer' => true, 'can_issue_stock' => true, 'can_receive_stock' => true]);
        $product = Product::firstOrFail()->replicate()->fill(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'Product '.$branch->code, 'sku' => 'ISO-'.$branch->code, 'barcode' => null]);
        $product->save();
        $customer = Customer::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'Customer '.$branch->code, 'phone' => 'PHONE-'.$branch->id, 'status' => 'active']);
        $supplier = Supplier::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'Supplier '.$branch->code, 'phone' => 'SUP-PHONE-'.$branch->id, 'status' => 'active']);
        $sale = Sale::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'stock_location_id' => $location->id, 'customer_id' => $customer->id, 'sale_number' => 'ISO-SALE-'.$branch->id, 'sale_date' => today(), 'subtotal' => 1000, 'total_amount' => 1000, 'created_by' => $user->id, 'sold_by' => $user->id, 'status' => 'completed', 'payment_status' => 'unpaid']);
        $invoice = SalesInvoice::create(['company_id' => $branch->company_id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'invoice_number' => 'ISO-INV-'.$branch->id, 'source_type' => 'staff']);
        $purchase = Purchase::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'purchase_date' => today(), 'reference_number' => 'ISO-PO-'.$branch->id, 'created_by' => $user->id, 'total_amount' => 1500]);
        $movement = StockMovement::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_location_id' => $location->id, 'movement_type' => 'purchase_receipt', 'quantity' => 10, 'unit_cost' => 100, 'movement_date' => today(), 'created_by' => $user->id]);
        $transfer = StockTransfer::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'transfer_number' => 'ISO-TRF-'.$branch->id, 'from_location_id' => $location->id, 'to_location_id' => $location->id, 'transfer_date' => today(), 'created_by' => $user->id]);
        $quotation = Quotation::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'created_by' => $user->id, 'quotation_number' => 'ISO-QUOTE-'.$branch->id, 'subtotal' => 1000, 'total_amount' => 1000, 'quotation_date' => today(), 'valid_until' => today()->addDays(7), 'status' => 'draft']);
        $expense = Expense::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'expense_category_id' => ExpenseCategory::firstOrFail()->id, 'amount' => 100, 'payment_method' => 'cash', 'expense_date' => today(), 'paid_by' => $user->id]);
        $grn = GoodsReceivingNote::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'purchase_id' => $purchase->id, 'grn_number' => 'ISO-GRN-'.$branch->id, 'stock_location_id' => $location->id, 'received_date' => today(), 'received_by' => $user->id]);
        $opening = OpeningStock::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'stock_location_id' => $location->id, 'opening_date' => today(), 'reference_number' => 'ISO-OPEN-'.$branch->id, 'total_products' => 1, 'total_base_quantity' => 10, 'total_value' => 1000, 'created_by' => $user->id, 'posted_at' => now()]);
        $internal = InternalSale::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'internal_sale_number' => 'ISO-INTERNAL-'.$branch->id, 'sale_date' => today(), 'from_location_id' => $location->id, 'to_location_id' => $location->id, 'created_by' => $user->id]);
        $salePayment = SalePayment::create(['company_id' => $branch->company_id, 'sale_id' => $sale->id, 'amount' => 100, 'payment_method' => 'cash', 'payment_date' => today(), 'received_by' => $user->id]);
        $customerPayment = CustomerPayment::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'receipt_number' => 'ISO-PAY-'.$branch->id, 'amount' => 100, 'payment_method' => 'cash', 'payment_date' => today(), 'received_by' => $user->id]);
        $supplierPayment = SupplierPayment::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'amount' => 100, 'payment_method' => 'cash', 'payment_date' => today(), 'paid_by' => $user->id]);
        $transferItem = $transfer->items()->create(['company_id' => $branch->company_id, 'product_id' => $product->id, 'quantity' => 1]);
        $this->rows[$branch->id] = compact('location', 'product', 'customer', 'sale', 'invoice', 'purchase', 'movement', 'transfer', 'quotation', 'expense', 'grn', 'opening', 'internal', 'salePayment', 'customerPayment', 'supplierPayment', 'transferItem');
    }
    $this->actingAs($this->staff)->withSession(['staff_locale' => 'en']);
});

test('assigned super admin sees only its branch across direct and parent-owned models', function () {
    foreach ($this->rows[$this->branchA->id] as $key => $record) {
        expect($record::query()->whereKey($record->id)->exists())->toBeTrue();
        foreach ([$this->branchB, $this->branchC] as $branch) {
            $hidden = $this->rows[$branch->id][$key];
            expect($hidden::query()->whereKey($hidden->id)->exists())->toBeFalse();
        }
    }
    expect($this->staff->accessibleBranchIds())->toBe([$this->branchA->id])
        ->and($this->staff->canAccessBranch($this->branchB->id))->toBeFalse();
});

test('unassigned staff can access both company branches but never another tenant', function () {
    $this->staff->syncRoles(['Manager']);
    $this->staff->update(['branch_id' => null]);
    foreach ($this->rows[$this->branchA->id] as $key => $record) {
        $other = $this->rows[$this->branchB->id][$key];
        expect($record::query()->whereKey($other->id)->exists())->toBeTrue();
        expect($record::query()->whereKey($this->rows[$this->branchC->id][$key]->id)->exists())->toBeFalse();
    }
    expect($this->staff->accessibleBranchIds())->toContain($this->branchA->id, $this->branchB->id)->not->toContain($this->branchC->id);
    expect(app(FinancialReportService::class)->profitLoss(null, today()->toDateString(), today()->toDateString())['revenue'])->toEqual(2000);
});

test('direct URLs and document endpoints cannot expose another branch', function () {
    $rows = $this->rows[$this->branchB->id];
    foreach (['sales.show' => 'sale', 'purchases.show' => 'purchase', 'stock-transfers.show' => 'transfer', 'quotations.show' => 'quotation', 'quotations.pdf' => 'quotation', 'invoices.pdf' => 'invoice'] as $route => $key) {
        $response = $this->get(route($route, $rows[$key]));
        expect($response->status())->toBeIn([403, 404]);
    }
});

test('forged branch and foreign IDs are rejected without inventory changes', function () {
    $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
    $foreign = $this->rows[$this->branchB->id];
    $product = $this->rows[$this->branchA->id]['product'];
    expect(fn () => $product->update(['unit_id' => $this->foreignUnit->id]))->toThrow(ValidationException::class);
    $payment = $this->rows[$this->branchA->id]['salePayment'];
    expect(fn () => $payment->update(['company_id' => null]))->toThrow(ValidationException::class);
    expect($payment->fresh()->company_id)->toBe($this->staff->company_id);
    expect(fn () => Expense::create(['branch_id' => $this->branchB->id, 'expense_category_id' => ExpenseCategory::firstOrFail()->id, 'amount' => 1, 'payment_method' => 'cash', 'expense_date' => today(), 'paid_by' => $this->staff->id]))->toThrow(ValidationException::class);
    expect(fn () => $foreign['purchase']->update(['notes' => 'Forged update']))->toThrow(ValidationException::class);
    expect(fn () => StockTransfer::create(['branch_id' => $this->branchA->id, 'transfer_number' => 'FORGED-LOC', 'from_location_id' => $this->rows[$this->branchA->id]['location']->id, 'to_location_id' => $foreign['location']->id, 'transfer_date' => today(), 'created_by' => $this->staff->id]))->toThrow(ValidationException::class);
    expect(fn () => app(InventoryService::class)->completeStockTransfer($foreign['transfer']->id, $this->staff->id))->toThrow(ModelNotFoundException::class);
    expect(DB::table('stock_movements')->orderBy('id')->get()->toJson())->toBe($before);
});

test('reports dashboard and inventory totals respect branch assignment', function () {
    $report = app(FinancialReportService::class)->profitLoss(null, today()->toDateString(), today()->toDateString());
    expect($report['revenue'])->toEqual(1000)->and($report['expenses'])->toEqual(100);
    expect(app(InventoryService::class)->getProductTotalStock($this->rows[$this->branchA->id]['product']->id, $this->branchA->id))->toEqual(10);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Private Branch B');
    $this->staff->update(['branch_id' => null]);
    $report = app(FinancialReportService::class)->profitLoss(null, today()->toDateString(), today()->toDateString());
    expect($report['revenue'])->toEqual(2000)->and($report['expenses'])->toEqual(200);
});

test('exports reject unauthorized branch filters and show only accessible branches and locations', function () {
    $this->get(route('exports.download', ['export' => 'tables.sales', 'format' => 'excel', 'branch_id' => $this->branchB->id]))->assertSessionHasErrors('branch_id');
    $this->get(route('stock-transfers.create'))->assertOk()->assertDontSee('Location PRIVATE-B')->assertDontSee('Other Tenant Branch');
    expect(Branch::pluck('id')->all())->toBe([$this->branchA->id]);
    expect(StockLocation::whereKey($this->rows[$this->branchB->id]['location']->id)->exists())->toBeFalse();
});

test('cross-company staff branch assignment is rejected by validation and model writes', function () {
    expect(fn () => $this->staff->update(['branch_id' => $this->branchC->id]))->toThrow(ValidationException::class);
    $this->staff->refresh();
    Volt::test('users.create')->set('name', 'New Staff')->set('email', 'branch-new@example.test')->set('password', 'ValidPassword123!')->set('role', 'Admin')->set('branch_id', $this->branchC->id)->call('save')->assertHasErrors('branch_id');
});

test('scope ownership map matches schema and never adds invalid indirect branch columns', function () {
    foreach (BranchAccess::DIRECT as $name) {
        $class = 'App\\Models\\'.$name;
        expect(Schema::hasColumn((new $class)->getTable(), 'branch_id'), $name.' must have a direct branch_id')->toBeTrue();
    }
    foreach (BranchAccess::PARENTS as $name => $relation) {
        $class = 'App\\Models\\'.$name;
        expect(method_exists(new $class, $relation), $name.' must define '.$relation)->toBeTrue();
    }
    Auth::guard('web')->forgetUser();
    // Querying the authentication model must not recurse into its own provider.
    expect(User::whereKey($this->staff->id)->exists())->toBeTrue();
});
