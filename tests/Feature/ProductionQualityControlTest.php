<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionQualityHold;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionQualityPlan;
use App\Models\ProductionQualityPlanCheck;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionCuringService;
use App\Services\ProductionQualityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $template = Product::query()->firstOrFail();
    $this->product = $template->replicate()->forceFill([
        'name' => 'QC Block', 'sku' => 'QC-BLOCK', 'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'requires_curing' => true, 'curing_days_required' => 14, 'sellable_after_days' => 1,
        'requires_quality_control' => true, 'requires_pre_release_inspection' => true,
    ]);
    $this->product->save();
    $this->machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'QC Press', 'code' => 'QC-PRESS', 'status' => 'active']);
    $this->curing = StockLocation::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'QC Curing', 'code' => 'QC-CUR', 'type' => 'curing', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true, 'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => false, 'is_sellable' => false]);
    $this->sellable = StockLocation::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'QC Finished', 'code' => 'QC-FG', 'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true, 'can_receive_stock' => true, 'can_transfer' => true, 'can_sell' => true, 'is_sellable' => true]);
    $this->order = ProductionOrder::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'raw_material_stock_location_id' => $this->curing->id, 'finished_goods_stock_location_id' => $this->curing->id,
        'production_output_stock_location_id' => $this->curing->id, 'final_finished_goods_stock_location_id' => $this->sellable->id,
        'machine_id' => $this->machine->id, 'product_id' => $this->product->id, 'order_number' => 'PO-QC-1',
        'production_date' => now()->toDateString(), 'planned_quantity' => 100, 'accepted_quantity' => 100,
        'rejected_quantity' => 0, 'total_produced_quantity' => 100, 'status' => ProductionOrder::STATUS_COMPLETED,
        'completed_at' => now(), 'created_by' => $this->admin->id,
    ]);
    $this->batch = ProductionCuringBatch::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'production_order_id' => $this->order->id,
        'product_id' => $this->product->id, 'machine_id' => $this->machine->id, 'source_stock_location_id' => $this->curing->id,
        'default_release_stock_location_id' => $this->sellable->id, 'batch_number' => 'CB-QC-1', 'production_date' => now()->toDateString(),
        'curing_started_at' => now()->subDays(2), 'minimum_sellable_at' => now()->subDay(), 'full_curing_at' => now()->addDays(12),
        'accepted_quantity' => 100, 'released_quantity' => 0, 'damaged_quantity' => 0, 'remaining_quantity' => 100,
        'status' => ProductionCuringBatch::STATUS_ELIGIBLE, 'created_by' => $this->admin->id,
    ]);
    StockMovement::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_location_id' => $this->curing->id, 'movement_type' => 'production_output', 'quantity' => 100, 'quantity_in' => 100, 'quantity_out' => 0, 'reference_type' => ProductionOrder::class, 'reference_id' => $this->order->id, 'movement_date' => now()->toDateString(), 'created_by' => $this->admin->id]);
});

function qcPlan(object $test, string $stage = 'pre_release', array $check = [], array $planOverrides = []): ProductionQualityPlan
{
    $plan = ProductionQualityPlan::query()->create(['company_id' => $test->company->id, 'product_id' => $test->product->id, 'name' => 'Release QC', 'code' => 'QC-REL', 'version' => '1', 'inspection_stage' => $stage, 'status' => 'draft', 'requires_approval' => true, 'created_by' => $test->admin->id, ...$planOverrides]);
    $plan->checks()->create(['company_id' => $test->company->id, 'name' => 'Strength', 'check_type' => 'numeric', 'minimum_value' => '10.00000000', 'required' => true, 'critical' => true, 'acceptance_rule' => 'minimum', 'sort_order' => 1, ...$check]);

    return app(ProductionQualityService::class)->activatePlan($plan, $test->admin);
}

function queuedQc(object $test): ProductionQualityInspection
{
    qcPlan($test);

    return app(ProductionQualityService::class)->queueCuringInspection($test->order->fresh('product'), $test->batch, $test->admin);
}

function recordQueuedQc(object $test, ProductionQualityInspection $inspection, string $accepted, string $rejected, array $overrides = []): ProductionQualityInspection
{
    $line = $inspection->results()->firstOrFail();

    return app(ProductionQualityService::class)->recordQueuedInspection($inspection, [
        'result' => 'passed',
        'accepted_quantity' => $accepted,
        'rejected_quantity' => $rejected,
        'inspector_id' => $test->admin->id,
        'disposition' => bccomp($rejected, '0', 12) > 0 ? 'scrap' : null,
        'check_answers' => [$line->id => ['numeric_value' => '12']],
        ...$overrides,
    ], $test->admin);
}

function qcInspect(object $test, array $answer = ['numeric_value' => '12'], array $data = []): ProductionQualityInspection
{
    $check = ProductionQualityPlan::query()->where('product_id', $test->product->id)->where('inspection_stage', 'pre_release')->where('status', 'active')->firstOrFail()->checks()->firstOrFail();

    return app(ProductionQualityService::class)->createInspection([
        'production_curing_batch_id' => $test->batch->id, 'inspection_stage' => 'pre_release',
        'sample_quantity' => 10, 'inspected_quantity' => 100, 'passed_quantity' => 100, 'failed_quantity' => 0,
        ...$data,
    ], [$check->id => $answer], $test->admin);
}

test('quality routes require feature and permission', function () {
    $this->get(route('production.quality.inspections.index'))->assertOk();
    $this->get(route('production.quality.inspections.create'))->assertOk();
    $this->get(route('production.quality.plans.index'))->assertOk();
    $this->get(route('production.quality.plans.create'))->assertOk();
    $this->get(route('production.quality.holds.index'))->assertOk();
    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('production.quality.inspections.index'))->assertForbidden();
    $this->company->update(['manufacturing_enabled' => true]);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);
    $this->get(route('production.quality.plans.index'))->assertForbidden();
    Volt::test('production.quality.inspections.index')->assertForbidden();
});

test('purchased products cannot retain quality configuration and existing defaults are false', function () {
    $existing = Product::query()->whereKeyNot($this->product->id)->firstOrFail();
    expect($existing->requires_quality_control)->toBeFalse()->and($existing->requires_pre_release_inspection)->toBeFalse();
    $this->product->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED, 'requires_quality_control' => true, 'requires_pre_release_inspection' => true, 'quality_notes' => 'bad']);
    expect($this->product->refresh()->requires_quality_control)->toBeFalse()->and($this->product->requires_pre_release_inspection)->toBeFalse()->and($this->product->quality_notes)->toBeNull();

    Volt::test('products.create')
        ->set('inventory_source', Product::INVENTORY_SOURCE_MANUFACTURED)
        ->assertSee(__('production.quality.requires_quality_control'))
        ->set('inventory_source', Product::INVENTORY_SOURCE_PURCHASED)
        ->assertDontSee(__('production.quality.requires_quality_control'));
});

test('plan activation deactivates prior plan and active plan and checks are immutable', function () {
    $first = qcPlan($this);
    $second = ProductionQualityPlan::query()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'name' => 'Release QC v2', 'inspection_stage' => 'pre_release', 'status' => 'draft', 'requires_approval' => true]);
    $second->checks()->create(['company_id' => $this->company->id, 'name' => 'Cracks', 'check_type' => 'yes_no', 'required' => true, 'critical' => true, 'acceptance_rule' => 'no_required']);
    app(ProductionQualityService::class)->activatePlan($second, $this->admin);
    expect($first->refresh()->status)->toBe('inactive')->and($second->refresh()->status)->toBe('active')
        ->and(fn () => $second->update(['name' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $second->checks()->first()->update(['name' => 'Changed']))->toThrow(LogicException::class);
    $this->get(route('production.quality.plans.show', $second))->assertOk();
});

test('objective checks use decimal-safe range minimum maximum equals and boolean evaluation', function () {
    $service = app(ProductionQualityService::class);
    $check = new ProductionQualityPlanCheck(['check_type' => 'numeric', 'acceptance_rule' => 'within_range', 'minimum_value' => '0.10000001', 'maximum_value' => '0.10000003']);
    expect($service->evaluateCheck($check, ['numeric_value' => '0.10000002']))->toBe('passed');
    $check->acceptance_rule = 'minimum';
    expect($service->evaluateCheck($check, ['numeric_value' => '0.10000000']))->toBe('failed');
    $check->acceptance_rule = 'maximum';
    expect($service->evaluateCheck($check, ['numeric_value' => '0.10000004']))->toBe('failed');
    $check->acceptance_rule = 'equals';
    $check->target_value = '0.10000002';
    expect($service->evaluateCheck($check, ['numeric_value' => '0.10000002']))->toBe('passed');
    $check->acceptance_rule = 'yes_required';
    expect($service->evaluateCheck($check, ['boolean_value' => true]))->toBe('passed');
    $check->acceptance_rule = 'manual_judgement';
    expect($service->evaluateCheck($check, ['manual_result' => 'failed']))->toBe('failed');
});

test('inspection snapshots plan and critical failure creates a stock-neutral hold', function () {
    $plan = qcPlan($this);
    $movementCount = StockMovement::count();
    $inspection = qcInspect($this, ['numeric_value' => '9'], ['passed_quantity' => 0, 'failed_quantity' => 100]);
    expect($inspection->result)->toBe('failed')->and($inspection->approval_status)->toBe('pending')
        ->and($inspection->results->first()->check_name)->toBe('Strength')
        ->and($inspection->results->first()->minimum_value)->toBe('10.00000000')
        ->and(ProductionQualityHold::query()->active()->where('production_quality_inspection_id', $inspection->id)->count())->toBe(1)
        ->and(StockMovement::count())->toBe($movementCount);
    $plan->update(['status' => 'inactive']);
    $plan->checks()->first()->update(['name' => 'New name', 'minimum_value' => 99]);
    expect($inspection->fresh('results')->results->first()->check_name)->toBe('Strength')->and($inspection->results->first()->minimum_value)->toBe('10.00000000');
    $this->get(route('production.quality.inspections.show', $inspection))->assertOk();
    $this->get(route('production.quality.holds.index'))->assertOk()->assertSee($inspection->inspection_number);
});

test('inspection route binding enforces company and branch isolation', function () {
    qcPlan($this);
    $inspection = qcInspect($this);
    $otherBranch = Branch::query()->create(['company_id' => $this->company->id, 'name' => 'Other QC Branch', 'code' => 'OQB', 'status' => 'active']);
    $branchInspector = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $otherBranch->id, 'status' => 'active']);
    $branchInspector->assignRole('Quality Inspector');
    $this->actingAs($branchInspector);
    $this->get(route('production.quality.inspections.show', $inspection->id))->assertNotFound();

    $otherCompany = Company::query()->create(['company_name' => 'Other Quality Co', 'business_type' => 'hardware', 'phone' => '0700000000', 'whatsapp_number' => '0700000000', 'manufacturing_enabled' => true]);
    $otherUser = User::withoutEvents(fn () => User::factory()->create(['company_id' => $otherCompany->id, 'branch_id' => null, 'status' => 'active']));
    $otherUser->givePermissionTo('production.view_quality');
    $this->actingAs($otherUser);
    $this->get(route('production.quality.inspections.show', $inspection->id))->assertNotFound();
});

test('approval is controlled and approved inspection is immutable', function () {
    qcPlan($this);
    $inspection = qcInspect($this);
    app(ProductionQualityService::class)->approve($inspection, $this->admin);
    expect($inspection->refresh()->approval_status)->toBe('approved')
        ->and(fn () => $inspection->update(['notes' => 'changed']))->toThrow(LogicException::class);
    $failed = qcInspect($this, ['numeric_value' => '1'], ['passed_quantity' => 0, 'failed_quantity' => 100]);
    expect(fn () => app(ProductionQualityService::class)->approve($failed, $this->admin))->toThrow(ValidationException::class);
});

test('required pre-release inspection and active holds gate curing release', function () {
    qcPlan($this);
    $service = app(ProductionCuringService::class);
    expect(fn () => $service->release($this->batch, 100, $this->sellable->id, $this->admin, 'qc-missing'))->toThrow(ValidationException::class, __('production.quality.pre_release_required'));
    $inspection = qcInspect($this);
    expect(fn () => $service->release($this->batch->refresh(), 100, $this->sellable->id, $this->admin, 'qc-unapproved'))->toThrow(ValidationException::class);
    app(ProductionQualityService::class)->approve($inspection, $this->admin);
    $hold = app(ProductionQualityService::class)->placeHold(['production_curing_batch_id' => $this->batch->id, 'reason' => 'Manual verification'], $this->admin);
    expect(fn () => $service->release($this->batch->refresh(), 100, $this->sellable->id, $this->admin, 'qc-held'))->toThrow(ValidationException::class, __('production.quality.active_hold_blocks_release'));
    app(ProductionQualityService::class)->releaseHold($hold, $this->admin, 'Verified by quality manager');
    $service->release($this->batch->refresh(), 100, $this->sellable->id, $this->admin, 'qc-approved');
    expect($this->batch->refresh()->remaining_quantity)->toBe('0.000000000000')->and(StockMovement::where('movement_type', 'curing_release_in')->count())->toBe(1);
});

test('retest preserves failed inspection and links a new numbered record', function () {
    qcPlan($this);
    $failed = qcInspect($this, ['numeric_value' => '1'], ['passed_quantity' => 0, 'failed_quantity' => 100]);
    $retest = qcInspect($this, ['numeric_value' => '12'], ['supersedes_inspection_id' => $failed->id]);
    expect($retest->id)->not->toBe($failed->id)->and($retest->inspection_number)->not->toBe($failed->inspection_number)
        ->and($retest->supersedes_inspection_id)->toBe($failed->id)->and($failed->refresh()->result)->toBe('failed');
});
