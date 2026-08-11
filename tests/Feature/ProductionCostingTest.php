<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Models\ProductionOrderCostingLine;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProductionCostingService;
use App\Services\ProductionCuringService;
use App\Services\ProductionMouldService;
use App\Services\ProductionOrderService;
use App\Services\ProductionRecipeService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true, 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam']);
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $template = Product::query()->firstOrFail();
    $this->finished = $template->replicate()->forceFill([
        'name' => 'Costed Block', 'sku' => 'COST-BLOCK', 'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'requires_curing' => false,
    ]);
    $this->finished->save();
    $this->material = Product::query()->whereKeyNot($this->finished->id)->firstOrFail();
    $this->material->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED, 'buying_price' => 12]);
    $this->unit = Unit::findOrFail($this->material->unit_id);
    $this->machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Costing Press', 'code' => 'CST-PRESS', 'status' => 'active']);
    $this->assignment = ProductionMachineAssignment::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'machine_id' => $this->machine->id,
        'product_id' => $this->finished->id, 'production_date' => '2026-07-29', 'target_quantity' => 100, 'status' => 'confirmed',
    ]);
    $this->raw = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Cost Raw', 'code' => 'CST-RAW',
        'type' => 'warehouse', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => false, 'is_sellable' => false,
    ]);
    $this->finishedLocation = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Cost Finished', 'code' => 'CST-FG',
        'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => true, 'is_sellable' => true,
    ]);
    $this->curingLocation = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Cost Curing', 'code' => 'CST-CUR',
        'type' => 'curing', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => false, 'is_sellable' => false,
    ]);
    Setting::query()->updateOrCreate(['company_id' => $this->company->id], [
        'default_raw_material_location_id' => $this->raw->id,
        'default_curing_location_id' => $this->curingLocation->id,
        'default_finished_goods_location_id' => $this->finishedLocation->id,
    ]);
    $this->recipe = app(ProductionRecipeService::class)->save([
        'name' => 'Costing Recipe', 'code' => 'CST-R1', 'version' => '1', 'product_id' => $this->finished->id,
        'output_quantity' => 1, 'output_unit_id' => $this->finished->unit_id, 'status' => ProductionRecipe::STATUS_ACTIVE,
        'effective_from' => '', 'effective_to' => '', 'notes' => '',
    ], [
        ['cost_type' => ProductionRecipeItem::TYPE_INVENTORY, 'material_product_id' => $this->material->id, 'material_unit_id' => $this->unit->id, 'entry_mode' => ProductionRecipeItem::MODE_PER_OUTPUT, 'source_quantity' => 0.1, 'yield_quantity' => '', 'notes' => ''],
        ['cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY, 'cost_name' => 'Electricity', 'source_quantity' => '', 'material_unit_id' => '', 'unit_cost' => 2, 'notes' => ''],
        ['cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY, 'cost_name' => 'Labour', 'source_quantity' => '', 'material_unit_id' => '', 'unit_cost' => 3, 'notes' => ''],
        ['cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY, 'cost_name' => 'Water', 'source_quantity' => 0.5, 'material_unit_id' => $this->unit->id, 'unit_cost' => 1.5, 'notes' => ''],
    ], $this->admin);
    $this->mould = ProductionMould::query()->create(['company_id' => $this->company->id, 'product_family_id' => $this->finished->product_family_id, 'code' => 'CST-MOULD', 'name' => 'Costing Mould', 'active' => true]);
    $this->mould->compatibleMachines()->syncWithPivotValues([$this->machine->id], ['company_id' => $this->company->id]);
    $installation = app(ProductionMouldService::class)->install($this->machine, $this->mould, $this->admin);
    $this->assignment->update(['production_mould_id' => $this->mould->id, 'production_mould_installation_id' => $installation->id, 'production_recipe_id' => $this->recipe->id]);
    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->branch->id, 'product_id' => $this->material->id, 'stock_location_id' => $this->raw->id,
        'quantity' => 100, 'cost_price' => 10, 'selling_price' => 20, 'reason' => 'Historical cost test',
        'notes' => '', 'movement_date' => '2026-07-29',
    ], $this->admin->id);
    Carbon::setTestNow('2026-07-29 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function completedOrderForCosting(object $test, bool $curing = false): ProductionOrder
{
    if ($curing) {
        $test->finished->update(['requires_curing' => true, 'sellable_after_days' => 1, 'curing_days_required' => 2]);
    }
    $service = app(ProductionOrderService::class);
    $order = $service->createFromAssignment($test->assignment, [
        'planned_quantity' => 100, 'raw_material_stock_location_id' => $test->raw->id,
        'production_output_stock_location_id' => $curing ? $test->curingLocation->id : $test->finishedLocation->id,
        'final_finished_goods_stock_location_id' => $test->finishedLocation->id,
        'finished_goods_stock_location_id' => $test->finishedLocation->id,
    ], $test->admin);
    $order = $service->start($order, $test->admin);
    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => match ($line->name) {
            $test->material->name => '10',
            'Water' => '50',
            default => $line->actual_quantity,
        },
        'actual_cost' => $line->actual_cost,
    ]])->all();
    $order = $service->saveExecution($order, $materials, '80', '20', null, $test->admin);

    return $service->complete($service->submit($order, $test->admin), $test->admin);
}

test('costing routes require feature and financial permission', function () {
    $this->get(route('production.costing.index'))->assertOk();
    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('production.costing.index'))->assertForbidden();
    $this->company->update(['manufacturing_enabled' => true]);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'is_system_owner' => false]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);
    Volt::test('production.costing.index')->assertForbidden();
});

test('costing details enforce tenant and branch isolation', function () {
    $costing = app(ProductionCostingService::class)->calculate(completedOrderForCosting($this), $this->admin);
    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Other Costing Branch', 'code' => 'OTHER-CST', 'status' => 'active',
    ]);
    $branchUser = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $otherBranch->id, 'status' => 'active', 'is_system_owner' => false,
    ]);
    $branchUser->givePermissionTo('production.view_costing');
    $this->actingAs($branchUser);
    $this->get(route('production.costing.show', $costing))->assertNotFound();

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Cost Company', 'business_type' => 'Factory',
        'phone' => '+255700833333', 'whatsapp_number' => '+255700833333', 'manufacturing_enabled' => true,
    ]);
    $otherCompanyBranch = Branch::query()->create([
        'company_id' => $otherCompany->id, 'name' => 'Other Company Branch', 'code' => 'OTHER-CO-CST', 'status' => 'active',
    ]);
    $otherUser = User::factory()->create([
        'company_id' => $otherCompany->id, 'branch_id' => $otherCompanyBranch->id, 'status' => 'active', 'is_system_owner' => false,
    ]);
    $otherUser->givePermissionTo('production.view_costing');
    $this->actingAs($otherUser);
    $this->get(route('production.costing.show', $costing))->assertNotFound();
});

test('costing requires completed order and creates one concurrency safe snapshot without stock effects', function () {
    $service = app(ProductionCostingService::class);
    $orderService = app(ProductionOrderService::class);
    $planned = $orderService->createFromAssignment($this->assignment, [
        'planned_quantity' => 100, 'raw_material_stock_location_id' => $this->raw->id,
        'finished_goods_stock_location_id' => $this->finishedLocation->id,
    ], $this->admin);
    expect(fn () => $service->calculate($planned, $this->admin))->toThrow(ValidationException::class);

    $orderService->cancel($planned, 'Replace test order', $this->admin);
    $order = completedOrderForCosting($this);
    $movementCount = StockMovement::count();
    $stock = app(InventoryService::class)->getProductStock($this->finished->id, $this->finishedLocation->id, $this->branch->id);
    $costing = $service->calculate($order, $this->admin);
    $same = $service->calculate($order, $this->admin);

    expect($costing->id)->toBe($same->id)
        ->and($costing->costing_number)->toStartWith('CST-20260729-')
        ->and(ProductionOrderCosting::where('production_order_id', $order->id)->count())->toBe(1)
        ->and(StockMovement::count())->toBe($movementCount)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->finishedLocation->id, $this->branch->id))->toBe($stock);
});

test('planned actual historical non inventory loss and variance calculations are precise and stable', function () {
    $order = completedOrderForCosting($this);
    $movement = StockMovement::where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_consumption')->firstOrFail();
    expect($movement->unit_cost)->toBe('10.00');
    $this->material->update(['buying_price' => 99999]);
    $this->recipe = ProductionRecipe::where('product_id', $this->finished->id)->first();
    $this->recipe->items()->update(['unit_cost' => 999]);

    $costing = app(ProductionCostingService::class)->calculate($order, $this->admin);
    expect($costing->planned_inventory_material_cost)->toBe('120.0000')
        ->and($costing->actual_inventory_material_cost)->toBe('100.0000')
        ->and($costing->planned_non_inventory_cost)->toBe('575.0000')
        ->and($costing->actual_non_inventory_cost)->toBe('575.0000')
        ->and($costing->total_planned_cost)->toBe('695.0000')
        ->and($costing->total_actual_cost)->toBe('675.0000')
        ->and($costing->cost_per_total_produced_unit)->toBe('6.75000000')
        ->and($costing->cost_per_accepted_unit)->toBe('8.43750000')
        ->and($costing->rejected_loss_cost)->toBe('135.0000')
        ->and($costing->curing_damage_loss_cost)->toBe('0.0000')
        ->and($costing->cost_variance)->toBe('-20.0000')
        ->and($costing->variance_percentage)->toBe('-2.8776')
        ->and($costing->output_variance)->toBe('0.000000000000')
        ->and($costing->yield_variance)->toBe('-20.000000000000');

    $inventoryLine = $costing->lines->firstWhere('line_type', ProductionOrderCostingLine::INVENTORY);
    $water = $costing->lines->firstWhere('name', 'Water');
    expect($inventoryLine->source_id)->toBe($movement->id)
        ->and($inventoryLine->actual_unit_cost)->toBe('10.00000000')
        ->and($water->cost_basis)->toBe('measured_quantity')
        ->and($water->actual_total_cost)->toBe('75.0000');

    expect(app(ProductionCostingService::class)->calculate($order, $this->admin)->total_actual_cost)->toBe('675.0000');
});

test('missing historical cost is warned and manual non inventory adjustment requires reason', function () {
    $order = completedOrderForCosting($this);
    StockMovement::where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_consumption')->update(['unit_cost' => null]);
    $order->materials()->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY)->update(['unit_cost' => null, 'planned_cost' => 0]);
    $this->material->update(['buying_price' => 0]);
    $service = app(ProductionCostingService::class);
    $costing = $service->calculate($order, $this->admin);
    expect($costing->has_missing_cost)->toBeTrue()
        ->and($costing->warnings)->not->toBeEmpty();

    $electricity = $costing->lines->firstWhere('name', 'Electricity');
    expect(fn () => $service->adjustNonInventoryCost($electricity, 250, '', $this->admin))->toThrow(ValidationException::class);
    $costing = $service->adjustNonInventoryCost($electricity, 250, 'Metered batch usage', $this->admin);
    expect($costing->lines->firstWhere('name', 'Electricity')->is_manual)->toBeTrue()
        ->and($costing->lines->firstWhere('name', 'Electricity')->actual_total_cost)->toBe('250.0000');
});

test('curing damage is separate and partial release does not change effective sellable unit cost', function () {
    $order = completedOrderForCosting($this, true);
    $service = app(ProductionCostingService::class);
    $initial = $service->calculate($order, $this->admin);
    expect(fn () => $service->finalize($initial, $this->admin))->toThrow(ValidationException::class);

    Carbon::setTestNow('2026-07-30 00:00:00');
    $batch = $order->curingBatch;
    app(ProductionCuringService::class)->recordDamage($batch, 5, 'Curing breakage', $this->admin, 'cost-damage');
    app(ProductionCuringService::class)->release($batch->refresh(), 40, $this->finishedLocation->id, $this->admin, 'cost-release-1');
    $partial = $service->calculate($order, $this->admin);
    expect($partial->curing_damaged_quantity)->toBe('5.000000000000')
        ->and($partial->released_quantity)->toBe('40.000000000000')
        ->and($partial->curing_damage_loss_cost)->toBe('42.1875')
        ->and($partial->cost_per_released_unit)->toBe('9.00000000')
        ->and($partial->rejected_quantity)->toBe('20.000000000000');
    expect(fn () => $service->finalize($partial, $this->admin))->toThrow(ValidationException::class);

    app(ProductionCuringService::class)->release($batch->refresh(), 35, $this->finishedLocation->id, $this->admin, 'cost-release-2');
    $complete = $service->calculate($order, $this->admin);
    expect($complete->cost_per_released_unit)->toBe('9.00000000');
    $finalized = $service->finalize($complete, $this->admin, 'All curing output accounted for');
    expect($finalized->status)->toBe(ProductionOrderCosting::STATUS_FINALIZED)
        ->and(fn () => $service->calculate($order, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $finalized->update(['notes' => 'Changed']))->toThrow(LogicException::class);
});

test('zero planned cost has null variance percentage and costing never changes commercial data', function () {
    $order = completedOrderForCosting($this);
    $order->materials()->update(['planned_cost' => 0, 'unit_cost' => 0, 'actual_cost' => 0]);
    StockMovement::where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_consumption')->update(['unit_cost' => null]);
    $this->material->update(['buying_price' => 0]);
    $sellingPrice = $this->finished->selling_price;
    $movementCount = StockMovement::count();
    $costing = app(ProductionCostingService::class)->calculate($order, $this->admin);

    expect($costing->total_planned_cost)->toBe('0.0000')
        ->and($costing->variance_percentage)->toBeNull()
        ->and(StockMovement::count())->toBe($movementCount)
        ->and($this->finished->refresh()->selling_price)->toBe($sellingPrice);
});
