<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 8)->startOfDay()->addHours(16));
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->supplier = Supplier::create([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->admin->branch_id,
        'name' => 'Reference Test Supplier',
        'phone' => '+255700000001',
        'status' => 'active',
    ]);
    $this->insertPurchase = fn (string $reference, array $attributes = []) => DB::table('purchases')->insertGetId(array_merge([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->admin->branch_id,
        'supplier_id' => $this->supplier->id,
        'purchase_date' => today()->toDateString(),
        'reference_number' => $reference,
        'created_by' => $this->admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
});

test('purchase references follow existing numeric suffixes regardless of creation date gaps or custom references', function () {
    ($this->insertPurchase)('PO-20261008-0001');
    ($this->insertPurchase)('PO-20261008-0003', ['created_at' => now()->subDay()]);
    ($this->insertPurchase)('PO-20261008-CUSTOM');
    ($this->insertPurchase)('PO-20261007-9999');

    expect(app(InventoryService::class)->generatePurchaseReference())->toBe('PO-20261008-0004');

    ($this->insertPurchase)('PO-20261008-9999');
    ($this->insertPurchase)('PO-20261008-10000');

    expect(app(InventoryService::class)->generatePurchaseReference())->toBe('PO-20261008-10001');
});

test('purchase numbering includes references hidden by company and branch access', function () {
    $this->admin->update(['is_system_owner' => false]);
    $this->actingAs($this->admin->fresh());

    $company = Company::create([
        'company_name' => 'Other Reference Company',
        'business_type' => 'hardware',
        'phone' => '0700000000',
        'whatsapp_number' => '0700000000',
    ]);
    $branch = (array) DB::table('branches')->where('id', $this->admin->branch_id)->first();
    unset($branch['id']);
    $branch['code'] = 'REF-OTHER';
    $otherBranchId = DB::table('branches')->insertGetId($branch);
    ($this->insertPurchase)('PO-20261008-0001', ['branch_id' => $otherBranchId]);

    $branch['company_id'] = $company->id;
    $branch['code'] = 'REF-COMPANY';
    $companyBranchId = DB::table('branches')->insertGetId($branch);
    $supplier = (array) DB::table('suppliers')->where('id', $this->supplier->id)->first();
    unset($supplier['id']);
    $supplier['company_id'] = $company->id;
    $supplier['branch_id'] = $companyBranchId;
    $companySupplierId = DB::table('suppliers')->insertGetId($supplier);
    ($this->insertPurchase)('PO-20261008-0002', [
        'company_id' => $company->id,
        'branch_id' => $companyBranchId,
        'supplier_id' => $companySupplierId,
    ]);

    expect(Purchase::whereIn('reference_number', ['PO-20261008-0001', 'PO-20261008-0002'])->count())->toBe(0);

    Volt::test('purchases.create')->assertSet('reference_number', 'PO-20261008-0003');
});

test('an automatic purchase reference is refreshed when it is used while the form is open', function () {
    $component = Volt::test('purchases.create');
    $reference = $component->get('reference_number');
    ($this->insertPurchase)($reference);

    $component
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) Product::firstOrFail()->id)
        ->call('savePurchase', 'draft')
        ->assertHasNoErrors()
        ->assertRedirect(route('purchases.index'));

    expect(Purchase::where('reference_number', 'PO-20261008-0002')->exists())->toBeTrue();
});

test('a duplicate manually entered purchase reference still fails validation', function () {
    ($this->insertPurchase)('MANUAL-REFERENCE');

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) Product::firstOrFail()->id)
        ->set('reference_number', 'MANUAL-REFERENCE')
        ->call('savePurchase', 'draft')
        ->assertHasErrors(['reference_number' => 'unique']);

    expect(Purchase::where('reference_number', 'MANUAL-REFERENCE')->count())->toBe(1);
});
