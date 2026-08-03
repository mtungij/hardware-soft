<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionCuringRelease;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionMouldInstallation;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionQualityPlan;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionReportService;
use App\Services\ProductionTraceabilityService;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::query()->findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true, 'timezone' => 'Africa/Dar_es_Salaam']);
    $this->actingAs($this->admin);
});

test('production report foundation registers and renders every report without mutating source data', function () {
    $before = [ProductionOrder::count(), StockMovement::count()];
    $routes = ['index', 'summary', 'material_consumption', 'costing', 'yield_loss', 'curing', 'quality', 'releases', 'machine_performance', 'mould_performance', 'batch_traceability'];

    foreach ($routes as $route) {
        $this->get(route('production.reports.'.$route))->assertOk();
    }

    expect([ProductionOrder::count(), StockMovement::count()])->toBe($before);
});

test('report routes enforce manufacturing and independent report permissions', function () {
    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('production.reports.index'))->assertForbidden();

    $this->company->update(['manufacturing_enabled' => true]);
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->admin->branch_id, 'status' => 'active', 'is_system_owner' => false]);
    $this->actingAs($user);
    $this->get(route('production.reports.index'))->assertForbidden();
    $user->givePermissionTo('production.view_reports');
    $this->get(route('production.reports.index'))->assertOk();
    $this->get(route('production.reports.costing'))->assertForbidden();
    $this->get(route('production.reports.batch_traceability'))->assertForbidden();
    $service = app(ProductionReportService::class);
    expect($service->report('material-consumption', [], export: true)['headers'])->not->toContain('Planned Unit Cost')
        ->and(collect($service->dashboard([]))->where('label', 'Production Cost')->first()['value'])->toBe('Restricted');
});

test('summary is tenant and branch scoped and uses historical order values', function () {
    $branch = Branch::query()->findOrFail($this->admin->branch_id);
    $product = Product::query()->where('company_id', $this->company->id)->firstOrFail();
    $machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'name' => 'Report Press', 'code' => 'RPT-PRESS', 'status' => 'active']);
    $location = StockLocation::query()->where('company_id', $this->company->id)->firstOrFail();
    ProductionOrder::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'product_id' => $product->id,
        'raw_material_stock_location_id' => $location->id, 'finished_goods_stock_location_id' => $location->id,
        'order_number' => 'PRD-REPORT-001', 'production_date' => '2026-08-02', 'planned_quantity' => '100', 'total_produced_quantity' => '95',
        'accepted_quantity' => '90', 'rejected_quantity' => '5', 'status' => 'completed', 'created_by' => $this->admin->id,
    ]);

    $data = app(ProductionReportService::class)->report('summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'], export: true);
    expect(collect($data['rows'])->flatten()->contains('PRD-REPORT-001'))->toBeTrue();

    $other = Company::query()->create(['company_name' => 'Other Report Co', 'business_type' => 'Factory', 'phone' => '+255700100200', 'whatsapp_number' => '+255700100200', 'manufacturing_enabled' => true]);
    $otherBranch = Branch::query()->create(['company_id' => $other->id, 'name' => 'Other Site', 'code' => 'OTHER-RPT', 'status' => 'active']);
    $otherUser = User::factory()->create(['company_id' => $other->id, 'branch_id' => $otherBranch->id, 'status' => 'active', 'is_system_owner' => false]);
    $otherUser->givePermissionTo('production.view_reports');
    $this->actingAs($otherUser);
    $otherData = app(ProductionReportService::class)->report('summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'], export: true);
    expect(collect($otherData['rows'])->flatten()->contains('PRD-REPORT-001'))->toBeFalse();
});

test('exports preserve filters and require export and cost permissions', function () {
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->admin->branch_id, 'status' => 'active', 'is_system_owner' => false]);
    $user->givePermissionTo(['production.view_reports', 'production.export_reports']);
    $this->actingAs($user);
    $this->get(route('production.reports.export', ['report' => 'summary', 'format' => 'excel', 'date_from' => '2026-08-01', 'date_to' => '2026-08-31']))->assertOk()->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
    $this->get(route('production.reports.export', ['report' => 'summary', 'format' => 'pdf', 'date_from' => '2026-08-01', 'date_to' => '2026-08-31']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('production.reports.export', ['report' => 'costing', 'format' => 'excel']))->assertForbidden();
});

test('every report reads the persisted historical snapshots quantities and ledger links', function () {
    $branch = Branch::query()->findOrFail($this->admin->branch_id);
    $product = Product::query()->where('company_id', $this->company->id)->firstOrFail();
    ProductFamily::ensureDefaultsForCompany($this->company->id);
    $family = ProductFamily::query()->where('company_id', $this->company->id)->firstOrFail();
    $product->update(['product_family_id' => $family->id, 'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED]);
    $machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'name' => 'Historical Report Press', 'code' => 'HIST-RPT', 'daily_capacity' => 120, 'status' => 'active']);
    $assignedMould = ProductionMould::query()->create(['company_id' => $this->company->id, 'product_family_id' => $family->id, 'name' => 'Historical Assigned Mould', 'code' => 'HIST-MOULD', 'active' => true, 'expected_output_per_day' => 100]);
    $currentMould = ProductionMould::query()->create(['company_id' => $this->company->id, 'product_family_id' => $family->id, 'name' => 'Different Current Mould', 'code' => 'CURRENT-MOULD', 'active' => true]);
    $installation = ProductionMouldInstallation::query()->create(['company_id' => $this->company->id, 'machine_id' => $machine->id, 'production_mould_id' => $currentMould->id, 'current_machine_id' => $machine->id, 'current_mould_id' => $currentMould->id, 'installed_at' => '2026-08-02 08:00:00', 'installed_by' => $this->admin->id]);
    $assignment = ProductionMachineAssignment::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'production_mould_id' => $assignedMould->id, 'product_id' => $product->id, 'production_date' => '2026-08-02', 'target_quantity' => 100, 'status' => 'completed']);
    $locations = StockLocation::query()->where('company_id', $this->company->id)->take(2)->get();
    $source = $locations->first();
    $destination = $locations->last();
    $order = ProductionOrder::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'production_machine_assignment_id' => $assignment->id, 'machine_id' => $machine->id, 'product_id' => $product->id, 'raw_material_stock_location_id' => $source->id, 'finished_goods_stock_location_id' => $destination->id, 'order_number' => 'PRD-HISTORY-REPORT', 'production_date' => '2026-08-02', 'planned_quantity' => 100, 'total_produced_quantity' => 95, 'accepted_quantity' => 90, 'rejected_quantity' => 5, 'status' => 'completed', 'completed_at' => '2026-08-02 10:00:00', 'completed_by' => $this->admin->id]);
    ProductionOrderMaterial::query()->create(['company_id' => $this->company->id, 'production_order_id' => $order->id, 'line_type' => 'inventory', 'material_product_id' => $product->id, 'name' => 'Historical Material', 'unit_id' => $product->unit_id, 'planned_quantity' => 10, 'actual_quantity' => 12, 'unit_cost' => 20, 'planned_cost' => 200, 'actual_cost' => 240]);
    ProductionOrderCosting::query()->create(['company_id' => $this->company->id, 'production_order_id' => $order->id, 'costing_number' => 'CST-HISTORY', 'currency_code' => 'TZS', 'planned_inventory_material_cost' => 200, 'actual_inventory_material_cost' => 240, 'total_planned_cost' => 200, 'total_actual_cost' => 240, 'planned_quantity' => 100, 'total_produced_quantity' => 95, 'accepted_quantity' => 90, 'rejected_quantity' => 5, 'curing_damaged_quantity' => 2, 'released_quantity' => 80, 'cost_per_accepted_unit' => '2.66666667', 'cost_per_released_unit' => 3, 'rejected_loss_cost' => 12, 'curing_damage_loss_cost' => 6, 'total_loss_cost' => 18, 'cost_variance' => 40, 'variance_percentage' => 20, 'output_variance' => -5, 'yield_variance' => -10, 'status' => 'finalized', 'finalized_at' => '2026-08-02 11:00:00', 'finalized_by' => $this->admin->id]);
    $batch = ProductionCuringBatch::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'production_order_id' => $order->id, 'product_id' => $product->id, 'machine_id' => $machine->id, 'source_stock_location_id' => $source->id, 'default_release_stock_location_id' => $destination->id, 'batch_number' => 'BATCH-HISTORY', 'production_date' => '2026-08-02', 'curing_started_at' => '2026-08-02 10:00:00', 'minimum_sellable_at' => '2026-08-03 10:00:00', 'full_curing_at' => '2026-08-05 10:00:00', 'accepted_quantity' => 90, 'released_quantity' => 80, 'damaged_quantity' => 2, 'remaining_quantity' => 8, 'status' => 'partially_released', 'qc_approved_at' => '2026-08-02 11:00:00', 'approved_by' => $this->admin->id]);
    $plan = ProductionQualityPlan::query()->create(['company_id' => $this->company->id, 'product_id' => $product->id, 'name' => 'Historical QC', 'code' => 'HIST-QC', 'version' => '1', 'inspection_stage' => 'pre_release', 'status' => 'active', 'requires_approval' => true]);
    ProductionQualityInspection::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'production_quality_plan_id' => $plan->id, 'production_order_id' => $order->id, 'production_curing_batch_id' => $batch->id, 'product_id' => $product->id, 'machine_id' => $machine->id, 'inspection_number' => 'QC-HISTORY', 'inspection_stage' => 'pre_release', 'applicable_quantity' => 90, 'inspected_quantity' => 90, 'passed_quantity' => 88, 'failed_quantity' => 2, 'result' => 'passed', 'approval_status' => 'approved', 'inspected_at' => '2026-08-02 11:00:00', 'inspected_by' => $this->admin->id, 'approved_at' => '2026-08-02 11:05:00', 'approved_by' => $this->admin->id]);
    $release = ProductionCuringRelease::query()->create(['company_id' => $this->company->id, 'production_curing_batch_id' => $batch->id, 'release_number' => 'REL-HISTORY', 'released_quantity' => 80, 'source_stock_location_id' => $source->id, 'destination_stock_location_id' => $destination->id, 'released_at' => '2026-08-02 12:00:00', 'released_by' => $this->admin->id, 'posting_reference' => 'POST-HISTORY', 'idempotency_key' => 'REPORT-REL-1']);
    foreach ([['curing_release_out', $source->id, 0, 80], ['curing_release_in', $destination->id, 80, 0]] as [$type,$location,$in,$out]) {
        StockMovement::query()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_location_id' => $location, 'movement_type' => $type, 'quantity' => 80, 'quantity_in' => $in, 'quantity_out' => $out, 'reference_type' => ProductionOrder::class, 'reference_id' => $order->id, 'production_curing_batch_id' => $batch->id, 'production_curing_release_id' => $release->id, 'posting_reference' => 'POST-HISTORY', 'movement_date' => '2026-08-02', 'created_by' => $this->admin->id]);
    }

    $service = app(ProductionReportService::class);
    $filters = ['date_from' => '2026-08-02', 'date_to' => '2026-08-02'];
    expect(collect($service->report('summary', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('Historical Assigned Mould')->not->toContain('Different Current Mould')
        ->and(collect($service->report('material-consumption', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('20.00%')->toContain('TZS 40.00')
        ->and(collect($service->report('costing', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('CST-HISTORY')->toContain('TZS 240.00')
        ->and(collect($service->report('yield-loss', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('5')->toContain('2')
        ->and(collect($service->report('curing', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('BATCH-HISTORY')->toContain('80')
        ->and(collect($service->report('quality', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('QC-HISTORY')->toContain('Approved')
        ->and(collect($service->report('releases', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('REL-HISTORY')->toContain('Verified')
        ->and(collect($service->report('machine-performance', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('Historical Report Press')
        ->and(collect($service->report('mould-performance', $filters, export: true)['rows'])->flatten()->join(' '))->toContain('Historical Assigned Mould')
        ->and(app(ProductionTraceabilityService::class)->find('BATCH-HISTORY')?->id)->toBe($batch->id);
});
