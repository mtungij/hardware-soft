<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionQualityPlan;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProductionCuringService;
use App\Services\ProductionMouldService;
use App\Services\ProductionOrderService;
use App\Services\ProductionQualityService;
use App\Services\ProductionRecipeService;
use App\Support\InventorySettings;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    app()->setLocale('en');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true, 'timezone' => 'Africa/Dar_es_Salaam']);
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $template = Product::query()->firstOrFail();
    $this->finished = $template->replicate()->forceFill([
        'name' => 'Cured Block', 'sku' => 'CURED-BLOCK', 'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'requires_curing' => true, 'sellable_after_days' => 10, 'curing_days_required' => 14,
    ]);
    $this->finished->save();
    $this->material = Product::query()->whereKeyNot($this->finished->id)->firstOrFail();
    $this->material->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED]);
    $this->unit = Unit::findOrFail($this->material->unit_id);
    $this->machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Curing Press', 'code' => 'CUR-PRESS', 'status' => 'active']);
    $this->assignment = ProductionMachineAssignment::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'machine_id' => $this->machine->id,
        'product_id' => $this->finished->id, 'production_date' => '2026-07-29', 'target_quantity' => 1000, 'status' => 'confirmed',
    ]);
    $this->raw = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Curing Raw', 'code' => 'CUR-RAW',
        'type' => 'warehouse', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => false, 'is_sellable' => false,
    ]);
    $this->curing = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Curing Yard', 'code' => 'CUR-YARD',
        'type' => 'curing', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => false, 'is_sellable' => false,
    ]);
    $this->sellable = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Finished Goods', 'code' => 'CUR-FG',
        'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => true, 'is_sellable' => true,
    ]);
    Setting::query()->updateOrCreate(['company_id' => $this->company->id], [
        'default_raw_material_location_id' => $this->raw->id,
        'default_curing_location_id' => $this->curing->id,
        'default_finished_goods_location_id' => $this->sellable->id,
    ]);
    $this->recipe = app(ProductionRecipeService::class)->save([
        'name' => 'Curing Recipe', 'code' => 'CUR-R1', 'version' => '1', 'product_id' => $this->finished->id,
        'output_quantity' => 1, 'output_unit_id' => $this->finished->unit_id, 'status' => ProductionRecipe::STATUS_ACTIVE,
        'effective_from' => '', 'effective_to' => '', 'notes' => '',
    ], [[
        'cost_type' => ProductionRecipeItem::TYPE_INVENTORY, 'material_product_id' => $this->material->id,
        'material_unit_id' => $this->unit->id, 'entry_mode' => ProductionRecipeItem::MODE_PER_OUTPUT,
        'source_quantity' => 0.01, 'yield_quantity' => '', 'notes' => '',
    ]], $this->admin);
    $this->mould = ProductionMould::query()->create(['company_id' => $this->company->id, 'product_family_id' => $this->finished->product_family_id, 'code' => 'CUR-MOULD', 'name' => 'Curing Mould', 'active' => true]);
    $this->mould->compatibleMachines()->syncWithPivotValues([$this->machine->id], ['company_id' => $this->company->id]);
    $installation = app(ProductionMouldService::class)->install($this->machine, $this->mould, $this->admin);
    $this->assignment->update(['production_mould_id' => $this->mould->id, 'production_mould_installation_id' => $installation->id, 'production_recipe_id' => $this->recipe->id]);
    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->branch->id, 'product_id' => $this->material->id, 'stock_location_id' => $this->raw->id,
        'quantity' => 100, 'cost_price' => 10, 'selling_price' => 20, 'reason' => 'Curing raw test',
        'notes' => '', 'movement_date' => '2026-07-29',
    ], $this->admin->id);
    Carbon::setTestNow('2026-07-29 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function completeCuringOrder(object $test, string $accepted = '1000', string $rejected = '0', string $planned = '1000'): ProductionOrder
{
    $service = app(ProductionOrderService::class);
    $order = $service->createFromAssignment($test->assignment, [
        'planned_quantity' => $planned, 'raw_material_stock_location_id' => $test->raw->id,
        'production_output_stock_location_id' => $test->curing->id,
        'final_finished_goods_stock_location_id' => $test->sellable->id,
    ], $test->admin);
    $order = $service->start($order, $test->admin);
    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => $line->line_type === ProductionOrderMaterial::TYPE_INVENTORY ? '10' : $line->actual_quantity,
        'actual_cost' => $line->actual_cost,
    ]])->all();
    $order = $service->saveExecution($order, $materials, $accepted, $rejected, null, $test->admin);

    return $service->complete($service->submit($order, $test->admin), $test->admin);
}

test('product curing configuration is backward compatible and purchased products are normalized', function () {
    expect(Product::query()->whereKeyNot($this->finished->id)->first()->requires_curing)->toBeFalse()
        ->and($this->finished->requires_curing)->toBeTrue()
        ->and($this->finished->sellable_after_days)->toBe(10)
        ->and($this->finished->curing_days_required)->toBe(14);

    expect(fn () => $this->finished->replicate()->forceFill([
        'sku' => 'INVALID-CURING', 'requires_curing' => true,
        'sellable_after_days' => 15, 'curing_days_required' => 14,
    ])->save())->toThrow(ValidationException::class);

    $this->finished->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED, 'requires_curing' => true]);
    expect($this->finished->refresh()->requires_curing)->toBeFalse()
        ->and($this->finished->curing_days_required)->toBeNull();

    $this->company->update(['manufacturing_enabled' => false]);
    Volt::test('products.create')->assertDontSee(__('production.curing.requires_curing'));
});

test('curing completion posts accepted output to quarantine and creates one correctly dated batch', function () {
    $order = completeCuringOrder($this);
    $batch = $order->curingBatch()->firstOrFail();
    $inventory = app(InventoryService::class);

    expect($batch->accepted_quantity)->toBe('1000.000000000000')
        ->and($batch->remaining_quantity)->toBe('1000.000000000000')
        ->and($batch->minimum_sellable_at->toDateString())->toBe('2026-08-08')
        ->and($batch->full_curing_at->toDateString())->toBe('2026-08-12')
        ->and($inventory->getProductStock($this->finished->id, $this->curing->id, $this->branch->id))->toBe(1000.0)
        ->and($inventory->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(0.0)
        ->and(ProductionCuringBatch::where('production_order_id', $order->id)->count())->toBe(1)
        ->and(StockMovement::where('movement_type', 'production_output')->where('reference_id', $order->id)->value('production_curing_batch_id'))->toBe($batch->id);

    app(ProductionOrderService::class)->complete($order->refresh(), $this->admin);
    expect(ProductionCuringBatch::where('production_order_id', $order->id)->count())->toBe(1);

    Volt::test('production.orders.show', ['order' => $order->refresh()])
        ->assertSee('Production Progress')
        ->assertSeeInOrder(['Completed', 'Curing', 'QC', 'Released'])
        ->assertSee('View Curing Batch')
        ->assertSee('View Costing')
        ->assertSee('View QC')
        ->assertSee('Print Production Order')
        ->assertDontSee('Start Production')
        ->assertDontSee('Cancel Order');
});

test('production completion queues one linked quality inspection and approval unlocks curing release', function () {
    $this->finished->update([
        'requires_quality_control' => true,
        'requires_pre_release_inspection' => true,
    ]);
    $this->finished->productFamily->update(['production_method' => 'mould_only']);
    $manualMould = ProductionMould::query()->create([
        'company_id' => $this->company->id,
        'product_family_id' => $this->finished->product_family_id,
        'code' => 'CUR-QC-MANUAL-MOULD',
        'name' => 'Curing QC Manual Mould',
        'active' => true,
    ]);
    $this->assignment->update([
        'production_method' => 'mould_only',
        'machine_id' => null,
        'production_mould_id' => $manualMould->id,
        'production_mould_installation_id' => null,
    ]);
    $plan = ProductionQualityPlan::query()->create([
        'company_id' => $this->company->id,
        'product_id' => $this->finished->id,
        'name' => 'Pre-release Quality Template',
        'code' => 'CURING-PRE-RELEASE',
        'version' => '1',
        'inspection_stage' => 'pre_release',
        'status' => 'draft',
        'requires_approval' => true,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    $check = $plan->checks()->create([
        'company_id' => $this->company->id,
        'name' => 'Minimum strength',
        'check_type' => 'numeric',
        'minimum_value' => '10',
        'required' => true,
        'critical' => true,
        'acceptance_rule' => 'minimum',
        'sort_order' => 1,
    ]);
    app(ProductionQualityService::class)->activatePlan($plan, $this->admin);

    $order = completeCuringOrder($this, '970', '30');
    $batch = $order->curingBatch()->firstOrFail();
    $inspection = ProductionQualityInspection::query()
        ->where('production_order_id', $order->id)
        ->where('production_curing_batch_id', $batch->id)
        ->sole();

    expect($inspection->product_id)->toBe($order->product_id)
        ->and($inspection->recipe_snapshot_id)->toBe($order->snapshot()->value('id'))
        ->and($order->machine_id)->toBeNull()
        ->and($inspection->machine_id)->toBe($order->machine_id)
        ->and($inspection->applicable_quantity)->toBe('970.000000000000')
        ->and($inspection->branch_id)->toBe($order->branch_id)
        ->and($inspection->company_id)->toBe($order->company_id)
        ->and($inspection->production_quality_plan_id)->toBe($plan->id)
        ->and($inspection->plan_name_snapshot)->toBe('Pre-release Quality Template')
        ->and($inspection->plan_version_snapshot)->toBe('1')
        ->and($inspection->results)->toHaveCount(1)
        ->and($inspection->results->first()->production_quality_plan_check_id)->toBe($check->id)
        ->and($inspection->results->first()->check_name)->toBe('Minimum strength')
        ->and($inspection->results->first()->result)->toBe('pending')
        ->and($inspection->result)->toBe('pending');

    app(ProductionOrderService::class)->complete($order->refresh(), $this->admin);
    expect(ProductionQualityInspection::query()->where('production_curing_batch_id', $batch->id)->count())->toBe(1);

    Volt::test('production.quality.inspections.index')
        ->assertSee($inspection->inspection_number)
        ->assertSee($this->finished->name);

    Volt::test('production.quality.inspections.show', ['inspection' => $inspection])
        ->assertSee('Pass')
        ->assertSee('Conditional Pass')
        ->assertSee('Fail')
        ->assertSee('QC Accepted Quantity')
        ->assertSee('QC Rejected Quantity')
        ->assertSee('Inspector')
        ->assertSee('Inspection Notes')
        ->assertSee('Minimum strength');

    $snapshotCheck = $inspection->results->firstOrFail();

    app(ProductionQualityService::class)->recordQueuedInspection($inspection, [
        'result' => 'conditional',
        'accepted_quantity' => '950',
        'rejected_quantity' => '20',
        'inspector_id' => $this->admin->id,
        'reason_justification' => 'Twenty units did not meet the visual release standard.',
        'corrective_action' => 'Scrap rejected units and release only the approved balance.',
        'disposition' => 'scrap',
        'notes' => 'Batch meets release specification.',
        'check_answers' => [$snapshotCheck->id => ['numeric_value' => '12']],
    ], $this->admin);

    app(ProductionQualityService::class)->approve($inspection->refresh(), $this->admin, 'Approved balance is within specification.');
    app(ProductionQualityService::class)->approve($inspection->refresh(), $this->admin, 'Repeated approval is idempotent.');

    expect($batch->refresh()->status)->toBe(ProductionCuringBatch::STATUS_READY_FOR_RELEASE)
        ->and($batch->qc_approved_at)->not->toBeNull()
        ->and($batch->approved_by)->toBe($this->admin->id)
        ->and($batch->productionOrder->rejected_quantity)->toBe('30.0000')
        ->and($batch->qc_rejected_quantity)->toBe('20.000000000000')
        ->and($batch->damaged_quantity)->toBe('0.000000000000')
        ->and($batch->release_eligible_quantity)->toBe('950.000000000000')
        ->and($batch->released_quantity)->toBe('0.000000000000')
        ->and($batch->remaining_quantity)->toBe('950.000000000000')
        ->and($inspection->refresh()->approval_status)->toBe('approved')
        ->and($inspection->passed_quantity)->toBe('950.000000000000')
        ->and($inspection->failed_quantity)->toBe('20.000000000000')
        ->and($inspection->results()->first()->result)->toBe('passed')
        ->and(StockMovement::query()->where('posting_reference', 'QC-REJ-'.$inspection->inspection_number)->count())->toBe(1)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(0.0);

    Volt::test('production.curing.index')
        ->assertSee('Ready For Release');

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.ready_for_release'))
        ->assertSee('Production Reject')
        ->assertSee('QC Reject')
        ->assertSee('Curing Damage')
        ->assertSee('Release Eligible')
        ->assertDontSee(__('production.curing.details.release_locked'));

    Volt::test('production.orders.show', ['order' => $order->refresh()])
        ->assertSeeInOrder(['Current', 'Ready For Release']);
});

test('curing details show accessible progress human age readiness dates and unchanged summary quantities', function () {
    $batch = completeCuringOrder($this)->curingBatch;
    $unit = $this->finished->unit?->short_name;

    Volt::test('production.curing.show', ['batch' => $batch])
        ->assertSee(__('production.curing.details.curing_progress'))
        ->assertSee('4%')
        ->assertSee(__('production.curing.details.less_than_one_day'))
        ->assertSee(__('production.curing.details.less_than_day_progress', ['total' => 14]))
        ->assertSee(__('production.curing.details.batch_number'))
        ->assertSee($batch->batch_number)
        ->assertSee(__('production.curing.details.batch_flow'))
        ->assertSee(__('production.curing.details.quality_control'))
        ->assertSee(__('production.curing.details.finished_goods_store'))
        ->assertSee(__('production.curing.details.release_locked'))
        ->assertSee('08 Aug 2026')
        ->assertSee('12 Aug 2026')
        ->assertSee(__('production.curing.details.not_yet_eligible'))
        ->assertSee(__('production.curing.details.release_available_on'))
        ->assertSee(__('production.curing.details.time_remaining'))
        ->assertSee(__('production.curing.details.date_block', ['date' => '08 Aug 2026']))
        ->assertSee(__('production.curing.details.accepted'))
        ->assertSee(__('production.curing.details.released'))
        ->assertSee('Curing Damage')
        ->assertSee(__('production.curing.details.remaining_curing'))
        ->assertSee($unit)
        ->assertSee(__('production.curing.details.production_completed'))
        ->assertSee(__('production.curing.details.curing_started'))
        ->assertSee(__('production.curing.details.upcoming_milestone'))
        ->assertSeeHtml('role="progressbar"')
        ->assertSeeHtml('aria-valuemin="0"')
        ->assertSeeHtml('aria-valuemax="100"')
        ->assertSeeHtml('aria-valuenow="4"')
        ->assertSeeHtml('aria-current="step"')
        ->assertSeeHtml('aria-hidden="true"')
        ->assertSeeHtml('bg-cyan-500')
        ->assertSeeHtml('dark:bg-cyan-400')
        ->assertSeeHtml('border-amber-200 bg-amber-50')
        ->assertSeeHtml('dark:border-amber-500/30 dark:bg-amber-500/10')
        ->assertSeeHtml('disabled:bg-slate-200 disabled:text-slate-600')
        ->assertSeeHtml('dark:disabled:bg-slate-800 dark:disabled:text-slate-400')
        ->assertDontSeeHtml('<table')
        ->assertDontSee('By —')
        ->assertSeeHtml('disabled');

    expect($batch->refresh()->accepted_quantity)->toBe('1000.000000000000')
        ->and($batch->released_quantity)->toBe('0.000000000000')
        ->and($batch->damaged_quantity)->toBe('0.000000000000')
        ->and($batch->remaining_quantity)->toBe('1000.000000000000');
});

test('curing progress is clamped and age is displayed as whole human days', function () {
    $batch = completeCuringOrder($this)->curingBatch;

    Carbon::setTestNow('2026-07-28 00:00:00');
    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee('0%')
        ->assertSee(__('production.curing.details.less_than_day_progress', ['total' => 14]))
        ->assertSeeHtml('aria-valuenow="0"');

    Carbon::setTestNow('2026-08-20 00:00:00');
    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee('100%')
        ->assertSee('22 days')
        ->assertSee(__('production.curing.details.days_progress', ['current' => 14, 'total' => 14]))
        ->assertSeeHtml('aria-valuenow="100"');
});

test('release readiness becomes positive at eligibility and real quarantine reason takes precedence', function () {
    $batch = completeCuringOrder($this)->curingBatch;
    Carbon::setTestNow('2026-08-08 00:00:00');

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.ready_for_release'))
        ->assertSee(__('production.curing.details.ready_for_release_help'))
        ->assertDontSee(__('production.curing.details.release_locked'))
        ->assertDontSee(__('production.curing.details.release_disabled'));

    Carbon::setTestNow('2026-08-09 00:00:00');
    app(ProductionCuringService::class)->quarantine($batch->refresh(), 'Cracking inspection', $this->admin, 'ui-quarantine');

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.not_yet_eligible'))
        ->assertSee(__('production.curing.details.release_locked'))
        ->assertSee(__('production.curing.details.blocked_reason'))
        ->assertSee('Cracking inspection')
        ->assertSee(__('production.curing.details.quarantine_block', ['reason' => 'Cracking inspection']))
        ->assertSeeHtml('disabled');
});

test('curing history follows manufacturing order while preserving posted timestamps and event types', function () {
    $batch = completeCuringOrder($this)->curingBatch;
    $unit = $this->finished->unit?->short_name;

    $plan = ProductionQualityPlan::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->finished->id,
        'name' => 'Curing timeline plan', 'inspection_stage' => 'curing', 'status' => 'active',
        'requires_approval' => false, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
    Carbon::setTestNow('2026-08-07 15:30:00');
    $inspection = ProductionQualityInspection::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'production_quality_plan_id' => $plan->id, 'production_curing_batch_id' => $batch->id,
        'product_id' => $this->finished->id, 'machine_id' => $this->machine->id,
        'inspection_number' => 'QIN-TIMELINE-1', 'inspection_stage' => 'curing',
        'applicable_quantity' => 1000, 'inspected_quantity' => 1000, 'result' => 'passed',
        'approval_status' => 'approved', 'inspected_at' => now(), 'inspected_by' => $this->admin->id,
    ]);
    Carbon::setTestNow('2026-08-07 16:00:00');
    $qualityService = app(ProductionQualityService::class);
    $hold = $qualityService->placeHold([
        'production_curing_batch_id' => $batch->id, 'production_quality_inspection_id' => $inspection->id,
        'product_id' => $this->finished->id, 'branch_id' => $this->branch->id,
        'held_quantity' => 20, 'reason' => 'Timeline quality hold',
    ], $this->admin);
    Carbon::setTestNow('2026-08-07 17:00:00');
    $qualityService->releaseHold($hold, $this->admin, 'Timeline hold cleared');
    Carbon::setTestNow('2026-08-08 00:00:00');
    $service = app(ProductionCuringService::class);
    $service->release($batch->refresh(), 600, $this->sellable->id, $this->admin, 'ui-release');
    Carbon::setTestNow('2026-08-08 01:00:00');
    $service->quarantine($batch->refresh(), 'Awaiting review', $this->admin, 'ui-history-quarantine');

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.partial_release'))
        ->assertSee(__('production.curing.details.quarantined'))
        ->assertSee(__('production.curing.details.quality_inspection'))
        ->assertSee(__('production.curing.details.posted_event'))
        ->assertSee(__('production.curing.details.quality_event'))
        ->assertSee(__('production.curing.details.quality_hold'))
        ->assertSee(__('production.curing.details.hold_released'))
        ->assertSee(__('production.curing.details.stock_hold'))
        ->assertSee(__('production.curing.details.release_event'))
        ->assertSee(__('production.curing.details.upcoming_milestone'))
        ->assertSee(__('production.curing.details.by_actor', ['name' => $this->admin->name]))
        ->assertSee('29 Jul 2026 12:00')
        ->assertSee('29 Jul 2026 00:00')
        ->assertSee('07 Aug 2026 15:30')
        ->assertSee('600 '.$unit)
        ->assertSee('400')
        ->assertSeeInOrder([
            __('production.curing.details.production_completed'),
            __('production.curing.details.curing_started'),
            __('production.curing.details.quality_inspection'),
            __('production.curing.details.quality_hold_created'),
            __('production.curing.details.quality_hold_released'),
            __('production.curing.details.quarantined'),
            __('production.curing.details.earliest_release'),
            __('production.curing.details.partial_release'),
            __('production.curing.details.full_curing'),
        ]);

    expect($batch->refresh()->released_quantity)->toBe('600.000000000000')
        ->and($batch->remaining_quantity)->toBe('400.000000000000');
});

test('non curing completion keeps direct finished goods behavior', function () {
    $this->finished->update(['requires_curing' => false]);
    $service = app(ProductionOrderService::class);
    $order = $service->createFromAssignment($this->assignment, [
        'planned_quantity' => 1000, 'raw_material_stock_location_id' => $this->raw->id,
        'finished_goods_stock_location_id' => $this->sellable->id,
    ], $this->admin);
    $order = $service->start($order, $this->admin);
    $lines = $order->materials->mapWithKeys(fn ($line) => [$line->id => ['actual_quantity' => '10', 'actual_cost' => '0']])->all();
    $order = $service->saveExecution($order, $lines, '900', '100', null, $this->admin);
    $order = $service->complete($service->submit($order, $this->admin), $this->admin);

    expect($order->curingBatch)->toBeNull()
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(900.0);
});

test('curing batch creation failure rolls back completion and every stock movement', function () {
    $service = app(ProductionOrderService::class);
    $order = $service->createFromAssignment($this->assignment, [
        'planned_quantity' => 1000, 'raw_material_stock_location_id' => $this->raw->id,
        'production_output_stock_location_id' => $this->curing->id,
        'final_finished_goods_stock_location_id' => $this->sellable->id,
    ], $this->admin);
    $order = $service->start($order, $this->admin);
    $lines = $order->materials->mapWithKeys(fn ($line) => [$line->id => ['actual_quantity' => '10', 'actual_cost' => '0']])->all();
    $order = $service->submit($service->saveExecution($order, $lines, '1000', '0', null, $this->admin), $this->admin);
    ProductionCuringBatch::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'production_order_id' => $order->id,
        'product_id' => $this->finished->id, 'machine_id' => $this->machine->id,
        'source_stock_location_id' => $this->curing->id, 'default_release_stock_location_id' => $this->sellable->id,
        'batch_number' => 'PREEXISTING-'.$order->id, 'production_date' => '2026-07-29',
        'curing_started_at' => now(), 'minimum_sellable_at' => now()->addDays(10), 'full_curing_at' => now()->addDays(14),
        'accepted_quantity' => 1000, 'remaining_quantity' => 1000, 'created_by' => $this->admin->id,
    ]);
    $before = StockMovement::count();

    expect(fn () => $service->complete($order, $this->admin))->toThrow(QueryException::class)
        ->and($order->refresh()->status)->toBe(ProductionOrder::STATUS_AWAITING_COMPLETION)
        ->and($order->posted_at)->toBeNull()
        ->and(StockMovement::count())->toBe($before);
});

test('release is age gated supports partial and full idempotent transfer', function () {
    $batch = completeCuringOrder($this)->curingBatch;
    $service = app(ProductionCuringService::class);
    expect(fn () => $service->release($batch, 600, $this->sellable->id, $this->admin, 'release-1'))->toThrow(ValidationException::class);

    Carbon::setTestNow('2026-08-08 00:00:00');
    $service->release($batch->refresh(), 600, $this->sellable->id, $this->admin, 'release-1');
    $movementCount = StockMovement::where('posting_reference', 'like', 'CUR-REL-%')->count();
    $service->release($batch->refresh(), 600, $this->sellable->id, $this->admin, 'release-1');
    expect($batch->refresh()->resolvedStatus())->toBe(ProductionCuringBatch::STATUS_PARTIALLY_RELEASED)
        ->and($batch->released_quantity)->toBe('600.000000000000')
        ->and($batch->remaining_quantity)->toBe('400.000000000000')
        ->and(StockMovement::where('posting_reference', 'like', 'CUR-REL-%')->count())->toBe($movementCount)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->curing->id, $this->branch->id))->toBe(400.0)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(600.0);

    $service->release($batch->refresh(), 400, $this->sellable->id, $this->admin, 'release-2');
    $finalRelease = $batch->releases()->latest('released_at')->firstOrFail();
    $postedMovementCount = StockMovement::where('posting_reference', 'like', 'CUR-REL-%')->count();
    expect($batch->refresh()->resolvedStatus())->toBe(ProductionCuringBatch::STATUS_RELEASED)
        ->and($batch->released_quantity)->toBe($batch->accepted_quantity)
        ->and($batch->remaining_quantity)->toBe('0.000000000000')
        ->and(fn () => $service->release($batch->refresh(), 1, $this->sellable->id, $this->admin, 'release-after-full'))
        ->toThrow(ValidationException::class, 'This curing batch has already been fully released.')
        ->and(StockMovement::where('posting_reference', 'like', 'CUR-REL-%')->count())->toBe($postedMovementCount);

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.fully_released'))
        ->assertSee(__('production.curing.details.released_to_finished_goods'))
        ->assertSee(__('production.curing.details.released_quantity'))
        ->assertSee(__('production.curing.details.released_by'))
        ->assertSee(__('production.curing.details.released_at'))
        ->assertSee($this->admin->name)
        ->assertSee($this->sellable->name)
        ->assertSee($finalRelease->posting_reference)
        ->assertSee(__('production.curing.details.completed'))
        ->assertSee(__('production.curing.details.finished_goods_store'))
        ->assertDontSee(__('production.curing.details.release_locked'))
        ->assertDontSee(__('production.curing.details.not_yet_eligible'))
        ->assertDontSee(__('production.curing.details.upcoming_milestone'))
        ->assertDontSeeHtml('wire:submit="release"')
        ->assertDontSeeHtml('aria-current="step"');
});

test('full release is visible in stock by location and POS without duplicate inventory', function () {
    $batch = completeCuringOrder($this, '1200', '0', '1200')->curingBatch;
    $this->admin->stockLocations()->syncWithoutDetaching([
        $this->curing->id => [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'can_view' => true,
            'can_sell' => false,
            'can_transfer' => true,
            'can_receive' => true,
            'can_adjust' => false,
            'is_default' => false,
            'assigned_by' => $this->admin->id,
        ],
        $this->sellable->id => [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'can_view' => true,
            'can_sell' => true,
            'can_transfer' => true,
            'can_receive' => true,
            'can_adjust' => false,
            'is_default' => false,
            'assigned_by' => $this->admin->id,
        ],
    ]);
    Carbon::setTestNow('2026-08-08 00:00:00');
    $service = app(ProductionCuringService::class);
    $release = $service->release($batch, 1200, $this->sellable->id, $this->admin, 'full-stock-release');
    $inventory = app(InventoryService::class);
    $movements = StockMovement::query()->where('production_curing_release_id', $release->id)->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->firstWhere('movement_type', 'curing_release_out')?->stock_location_id)->toBe($this->curing->id)
        ->and($movements->firstWhere('movement_type', 'curing_release_out')?->quantity_out)->toBe('1200.0000')
        ->and($movements->firstWhere('movement_type', 'curing_release_in')?->stock_location_id)->toBe($this->sellable->id)
        ->and($movements->firstWhere('movement_type', 'curing_release_in')?->quantity_in)->toBe('1200.0000')
        ->and($inventory->getProductStock($this->finished->id, $this->curing->id, $this->branch->id))->toBe(0.0)
        ->and($inventory->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(1200.0);

    Volt::test('store-stock.index')
        ->set('locationFilter', (string) $this->sellable->id)
        ->set('search', $this->finished->name)
        ->assertSee($this->finished->name)
        ->assertSee($this->sellable->name)
        ->assertSee('1,200');

    Volt::test('pos.index')
        ->set('stock_location_id', (string) $this->sellable->id)
        ->set('search', $this->finished->name)
        ->assertSee($this->finished->name)
        ->assertSee($this->sellable->name)
        ->assertSee('1,200')
        ->call('addProduct', $this->finished->id)
        ->assertSet('cart.0.product_id', $this->finished->id);

    $movementCount = StockMovement::query()->where('production_curing_batch_id', $batch->id)->count();
    expect($service->release($batch->refresh(), 1200, $this->sellable->id, $this->admin, 'full-stock-release')->id)->toBe($release->id)
        ->and(StockMovement::query()->where('production_curing_batch_id', $batch->id)->count())->toBe($movementCount)
        ->and($inventory->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(1200.0)
        ->and(fn () => $service->release($batch->refresh(), 1, $this->sellable->id, $this->admin, 'second-full-stock-release'))
        ->toThrow(ValidationException::class);

    $postedIn = $movements->firstWhere('movement_type', 'curing_release_in');
    expect(fn () => $postedIn->update(['quantity_in' => 1]))
        ->toThrow(LogicException::class, 'Posted curing release movements are immutable.')
        ->and(fn () => $postedIn->delete())
        ->toThrow(LogicException::class, 'Posted curing release movements cannot be deleted.');
});

test('quarantine blocks release and damage is separate idempotent stock loss', function () {
    $batch = completeCuringOrder($this)->curingBatch;
    Carbon::setTestNow('2026-08-09 00:00:00');
    $service = app(ProductionCuringService::class);
    $service->quarantine($batch, 'Cracking inspection', $this->admin, 'quarantine-1');
    expect(fn () => $service->release($batch->refresh(), 10, $this->sellable->id, $this->admin, 'blocked-release'))->toThrow(ValidationException::class);
    $service->removeQuarantine($batch->refresh(), 'Inspection passed', $this->admin, 'unquarantine-1');
    $service->recordDamage($batch->refresh(), 25, 'Handling breakage', $this->admin, 'damage-1');
    $service->recordDamage($batch->refresh(), 25, 'Handling breakage', $this->admin, 'damage-1');

    Volt::test('production.curing.show', ['batch' => $batch->refresh()])
        ->assertSee(__('production.curing.details.hold_released'))
        ->assertSee(__('production.curing.details.loss_event'))
        ->assertSee(__('production.curing.details.damage_recorded'));

    expect($batch->refresh()->damaged_quantity)->toBe('25.000000000000')
        ->and($batch->remaining_quantity)->toBe('975.000000000000')
        ->and(StockMovement::where('movement_type', 'curing_damage')->where('production_curing_batch_id', $batch->id)->count())->toBe(1)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->curing->id, $this->branch->id))->toBe(975.0)
        ->and(app(InventoryService::class)->getProductStock($this->finished->id, $this->sellable->id, $this->branch->id))->toBe(0.0);
});

test('curing location is excluded from sales and tenant and feature access are protected', function () {
    completeCuringOrder($this);
    expect($this->curing->refresh()->can_sell)->toBeFalse()
        ->and($this->curing->is_sellable)->toBeFalse()
        ->and(collect(InventorySettings::allowedSaleLocationsForUser($this->admin, $this->branch->id))->pluck('id')->contains($this->curing->id))->toBeFalse();

    expect(fn () => app(InventoryService::class)->completeSale(
        [['product_id' => $this->finished->id, 'quantity' => 1, 'sale_type' => 'retail']],
        [['payment_method' => 'cash', 'amount' => 1]], null, $this->curing->id, $this->branch->id, $this->admin->id
    ))->toThrow(ValidationException::class);

    $this->get(route('production.curing.index'))->assertOk();
    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('production.curing.index'))->assertForbidden();
});
