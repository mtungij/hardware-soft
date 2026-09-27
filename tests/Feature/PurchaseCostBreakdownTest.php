<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\PurchaseCostBreakdownService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->supplier = Supplier::create([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Purchase Supplier',
        'phone' => '255700888111',
        'status' => 'active',
    ]);
    $this->product = Product::query()->firstOrFail();
    $this->actingAs($this->admin);
});

test('purchase create only accepts supplier goods cost and snapshots editable buying price', function () {
    $this->product->update(['buying_price' => 15000]);
    $component = Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->assertSet('items.0.cost_price', 15000.0)
        ->assertDontSee('Cost Breakdown')
        ->set('items.0.ordered_quantity', '100')
        ->set('items.0.cost_price', '16000')
        ->set('reference_number', 'PO-SUPPLIER-COST')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();

    $purchase = Purchase::where('reference_number', 'PO-SUPPLIER-COST')->with('items.costBreakdown')->firstOrFail();
    expect((float) $purchase->total_amount)->toBe(1600000.0)
        ->and((float) $purchase->balance_amount)->toBe(1600000.0)
        ->and((float) $purchase->items->first()->cost_price)->toBe(16000.0)
        ->and($purchase->items->first()->costBreakdown)->toBeEmpty()
        ->and(app(AccountingService::class)->supplierBalance($this->supplier))->toBe(1600000.0);

    $this->product->update(['buying_price' => 25000]);
    expect((float) $purchase->items()->first()->cost_price)->toBe(16000.0);
});

test('old purchase cost breakdown remains readable but new PO does not persist submitted breakdown rows', function () {
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Transportation')->firstOrFail();

    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->set('items.0.ordered_quantity', '2')
        ->set('items.0.cost_price', '100')
        ->set('items.0.cost_breakdown', [['type_id' => $type->id, 'amount' => '30']])
        ->set('reference_number', 'PO-NO-BREAKDOWN')
        ->call('savePurchase', 'ordered')
        ->assertHasNoErrors();

    $purchase = Purchase::where('reference_number', 'PO-NO-BREAKDOWN')->firstOrFail();
    $item = $purchase->items()->firstOrFail();
    expect($item->costBreakdown()->count())->toBe(0)
        ->and((float) $purchase->total_amount)->toBe(200.0);

    $item->costBreakdown()->create([
        'company_id' => $this->admin->company_id,
        'purchase_cost_type_id' => $type->id,
        'cost_type_name_snapshot' => $type->name,
        'amount' => 30,
    ]);
    Volt::test('purchases.show', ['purchase' => $purchase])->assertSee('Cost Breakdown')->assertSee('Transportation');
});
