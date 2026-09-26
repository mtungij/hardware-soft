<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\InventoryService;
use App\Services\PurchaseCostBreakdownService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::where('code', 'MAIN')->firstOrFail();
    $this->supplier = Supplier::create([
        'company_id' => $this->admin->company_id,
        'branch_id' => $this->branch->id,
        'name' => 'Cost Breakdown Supplier',
        'phone' => '255700888111',
        'status' => 'active',
    ]);
    $this->product = Product::query()->firstOrFail();
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->update(['can_receive_stock' => true, 'is_active' => true, 'status' => 'active']);
    $this->actingAs($this->admin);
});

function breakdownPurchaseForm($test, string $reference)
{
    return Volt::test('purchases.create')
        ->set('supplier_id', (string) $test->supplier->id)
        ->call('selectProduct', 0, (string) $test->product->id)
        ->set('items.0.ordered_quantity', '100')
        ->set('items.0.cost_price', '24000')
        ->set('items.0.selling_price', '29500')
        ->set('reference_number', $reference);
}

test('purchase saves without a cost breakdown and keeps its ordinary value and selling price', function () {
    breakdownPurchaseForm($this, 'PO-BREAKDOWN-NONE')->call('savePurchase', 'ordered')->assertHasNoErrors();
    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-NONE')->with('items.costBreakdown')->firstOrFail();
    $item = $purchase->items->first();

    expect((float) $purchase->total_amount)->toBe(2400000.0)
        ->and((float) $purchase->balance_amount)->toBe(2400000.0)
        ->and((float) $item->cost_price)->toBe(24000.0)
        ->and((float) $item->selling_price)->toBe(29500.0)
        ->and($item->costBreakdown)->toBeEmpty();
});

test('purchase stores a matching per-unit breakdown without adding cost or accounting records', function () {
    $types = app(PurchaseCostBreakdownService::class);
    $types->ensureDefaultTypes($this->admin->company_id);
    $rows = [['Product Cost', '18000'], ['Transportation', '2000'], ['Freight', '1500'], ['Import Duty', '2000'], ['Other', '500']];
    $component = breakdownPurchaseForm($this, 'PO-BREAKDOWN-MATCH')->call('toggleCostBreakdown', 0);
    foreach ($rows as $index => [$name, $amount]) {
        $type = PurchaseCostType::where('name', $name)->firstOrFail();
        $component->call('addCostComponent', 0)
            ->set("items.0.cost_breakdown.{$index}.type_id", (string) $type->id)
            ->set("items.0.cost_breakdown.{$index}.amount", $amount);
    }
    $component->assertSee('Cost breakdown imekamilika.')
        ->call('completeCostBreakdown', 0)
        ->assertSet('breakdown_complete.0', true)
        ->assertSee('Cost Breakdown Complete')
        ->assertSee('View Breakdown')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();

    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-MATCH')->with('items.costBreakdown')->firstOrFail();
    $item = $purchase->items->first();
    expect($item->costBreakdown)->toHaveCount(5)
        ->and((float) $item->costBreakdown->sum('amount'))->toBe(24000.0)
        ->and((float) $item->cost_price)->toBe(24000.0)
        ->and((float) $item->line_total)->toBe(2400000.0)
        ->and((float) $item->selling_price)->toBe(29500.0)
        ->and((float) $purchase->total_amount)->toBe(2400000.0)
        ->and((float) $purchase->balance_amount)->toBe(2400000.0)
        ->and(app(AccountingService::class)->supplierBalance($this->supplier))->toBe(2400000.0)
        ->and(SupplierPayment::where('purchase_id', $purchase->id)->count())->toBe(0)
        ->and(Expense::where('company_id', $this->admin->company_id)->count())->toBe(0)
        ->and(DB::table('customer_material_cash_transactions')->where('company_id', $this->admin->company_id)->count())->toBe(0);

    Volt::test('purchases.show', ['purchase' => $purchase])
        ->assertSee('Cost Breakdown')
        ->assertSee('Transportation')
        ->assertSee('Cost breakdown matches Unit Cost.');
});

test('mismatched breakdown warns but never overwrites authoritative unit cost', function () {
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Transportation')->firstOrFail();
    breakdownPurchaseForm($this, 'PO-BREAKDOWN-MISMATCH')
        ->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '2000')
        ->assertSee('Cost Breakdown bado haijakamilika.')
        ->assertSeeHtml('wire:confirm="Cost Breakdown bado haijakamilika. Unataka kuhifadhi bila kuikamilisha?"')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();
    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-MISMATCH')->with('items.costBreakdown')->firstOrFail();
    expect((float) $purchase->items->first()->cost_price)->toBe(24000.0)
        ->and((float) $purchase->total_amount)->toBe(2400000.0);
    Volt::test('purchases.show', ['purchase' => $purchase])->assertSee('Cost breakdown does not match Unit Cost.');
});

test('partial GRNs use the purchase unit cost and protect the historical breakdown', function () {
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Product Cost')->firstOrFail();
    breakdownPurchaseForm($this, 'PO-BREAKDOWN-RECEIVE')
        ->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '24000')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();
    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-RECEIVE')->with('items.costBreakdown')->firstOrFail();
    $item = $purchase->items->first();
    Volt::test('purchases.receive', ['purchase' => $purchase])
        ->assertSee('TZS 24,000')
        ->assertDontSeeHtml('wire:model="lines.'.$item->id.'.unit_cost"')
        ->assertDontSee('Landed Cost');

    $inventory = app(InventoryService::class);
    foreach ([40, 60] as $quantity) {
        $receipt = $inventory->receivePurchase($purchase->fresh(), [
            $item->id => ['quantity' => $quantity, 'stock_location_id' => $this->location->id, 'unit_cost' => 48000],
        ], today()->toDateString(), $this->admin->id);
        expect((float) $receipt->items()->firstOrFail()->unit_cost)->toBe(24000.0);
        $movement = StockMovement::where('reference_id', $receipt->id)->where('movement_type', 'purchase_receipt')->firstOrFail();
        expect((float) $movement->unit_cost)->toBe(24000.0);
    }
    expect($item->fresh()->costBreakdown()->count())->toBe(1)
        ->and((float) $item->fresh()->received_quantity)->toBe(100.0)
        ->and($inventory->getAverageCost($this->product->id, $this->location->id, $this->branch->id))->toBe(24000.0)
        ->and(app(AccountingService::class)->supplierBalance($this->supplier))->toBe(2400000.0);
    expect(fn () => $item->costBreakdown->first()->update(['amount' => 30000]))->toThrow(LogicException::class);
});

test('alternative purchase unit breakdown stays per selected unit while stock cost converts to base unit', function () {
    $purchaseUnit = Unit::create([
        'company_id' => $this->admin->company_id,
        'name' => 'Twenty Bag Lot',
        'code' => 'twenty-bag-lot',
        'short_name' => 'lot',
        'measurement_type_id' => $this->product->unit->measurement_type_id,
        'status' => 'active',
    ]);
    $this->product->update([
        'purchase_unit_id' => $purchaseUnit->id,
        'purchase_conversion_factor' => 20,
        'buying_price' => 15000,
    ]);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Product Cost')->firstOrFail();
    Volt::test('purchases.create')
        ->set('supplier_id', (string) $this->supplier->id)
        ->call('selectProduct', 0, (string) $this->product->id)
        ->set('items.0.ordered_quantity', '1')
        ->set('items.0.cost_price', '300000')
        ->set('reference_number', 'PO-BREAKDOWN-UNIT')
        ->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '300000')
        ->assertSee('Cost breakdown imekamilika.')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();

    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-UNIT')->with('items.costBreakdown')->firstOrFail();
    $item = $purchase->items->first();
    expect((float) $item->purchaseFactor())->toBe(20.0)
        ->and((float) $item->costBreakdown->sum('amount'))->toBe(300000.0)
        ->and((float) $item->cost_price)->toBe(300000.0)
        ->and((float) $purchase->total_amount)->toBe(300000.0);

    $receipt = app(InventoryService::class)->receivePurchase($purchase, [
        $item->id => ['quantity' => 1, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id);
    $movement = StockMovement::where('reference_id', $receipt->id)->where('movement_type', 'purchase_receipt')->firstOrFail();
    expect((float) $movement->quantity_in)->toBe(20.0)
        ->and((float) $movement->unit_cost)->toBe(15000.0)
        ->and((float) $receipt->items()->firstOrFail()->unit_cost)->toBe(300000.0);
});

test('breakdown can be edited before receiving and cannot be edited after receiving', function () {
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Product Cost')->firstOrFail();
    breakdownPurchaseForm($this, 'PO-BREAKDOWN-EDIT')
        ->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '24000')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();
    $purchase = Purchase::where('reference_number', 'PO-BREAKDOWN-EDIT')->firstOrFail();
    Volt::test('purchases.edit', ['purchase' => $purchase])
        ->assertSet('items.0.cost_breakdown.0.amount', '24000.00')
        ->set('items.0.cost_breakdown.0.amount', '23000')
        ->assertSee('Cost Breakdown bado haijakamilika.')
        ->assertSeeHtml('wire:confirm="Cost Breakdown bado haijakamilika. Unataka kuhifadhi bila kuikamilisha?"')
        ->call('savePurchase', 'ordered')->assertHasNoErrors();
    $purchase = $purchase->fresh();
    $item = $purchase->items()->with('costBreakdown')->firstOrFail();
    expect((float) $item->costBreakdown->first()->amount)->toBe(23000.0)
        ->and((float) $item->cost_price)->toBe(24000.0);

    app(InventoryService::class)->receivePurchase($purchase, [
        $item->id => ['quantity' => 40, 'stock_location_id' => $this->location->id],
    ], today()->toDateString(), $this->admin->id);
    expect($purchase->fresh()->canBeModified())->toBeFalse();
    expect(fn () => $item->costBreakdown->first()->delete())->toThrow(LogicException::class);
});

test('unit cost comes first and live progress shows remaining, exact match, and excess', function () {
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->admin->company_id);
    $type = PurchaseCostType::where('name', 'Product Cost')->firstOrFail();
    $component = breakdownPurchaseForm($this, 'PO-BREAKDOWN-PROGRESS')
        ->set('items.0.cost_price', '0')
        ->call('toggleCostBreakdown', 0)
        ->assertDontSee('Unit Cost to Explain')
        ->set('items.0.cost_price', '24000')
        ->call('toggleCostBreakdown', 0)
        ->assertSee('Unit Cost to Explain')
        ->assertSee('Breakdown Entered')
        ->assertSee('Remaining')
        ->assertSee('TZS 24,000');

    $component->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '22500')
        ->assertSee('Bado TZS 1,500 haijaelezwa.')
        ->assertSee('Cost Breakdown bado haijakamilika.');
    expect((bool) preg_match('/wire:click="completeCostBreakdown\\(0\\)"[^>]*\sdisabled(?:\s|=|>)/', $component->html()))->toBeTrue();

    $component->set('items.0.cost_breakdown.0.amount', '24000')
        ->assertSee('Cost breakdown imekamilika.');
    expect((bool) preg_match('/wire:click="completeCostBreakdown\\(0\\)"[^>]*\sdisabled(?:\s|=|>)/', $component->html()))->toBeFalse();
    $component->call('completeCostBreakdown', 0)
        ->assertSet('breakdown_complete.0', true)
        ->assertSee('Cost Breakdown Complete')
        ->assertSee('View Breakdown')
        ->assertSet('items.0.cost_price', 24000.0);

    $component->call('toggleCostBreakdown', 0)
        ->set('items.0.cost_breakdown.0.amount', '25000')
        ->assertSet('breakdown_complete.0', false)
        ->assertSee('Exceeded by')
        ->assertSee('Breakdown imezidi Unit Cost kwa TZS 1,000.')
        ->assertSee('Cost Breakdown bado haijakamilika.')
        ->assertSet('items.0.cost_price', 24000.0);
});

test('cost type management stays outside the purchase line while new types remain selectable', function () {
    $component = breakdownPurchaseForm($this, 'PO-BREAKDOWN-TYPE')
        ->call('toggleCostBreakdown', 0)
        ->assertSee('Manage Cost Types')
        ->assertDontSee('New reusable cost type')
        ->set('new_cost_type', 'Local Delivery')
        ->call('addCostType')
        ->assertHasNoErrors();

    $type = PurchaseCostType::where('name', 'Local Delivery')->firstOrFail();
    $component->call('addCostComponent', 0)
        ->set('items.0.cost_breakdown.0.type_id', (string) $type->id)
        ->set('items.0.cost_breakdown.0.amount', '24000')
        ->assertSee('Local Delivery')
        ->assertSee('Cost breakdown imekamilika.');
});
