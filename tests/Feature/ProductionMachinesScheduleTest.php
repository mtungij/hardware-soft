<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionMouldService;
use App\Services\ProductionScheduleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->actingAs($this->admin);
    $this->branch = Branch::query()->whereKey($this->admin->branch_id)->firstOrFail();

    $template = Product::query()->firstOrFail();
    $this->manufactured = $template->replicate();
    $this->manufactured->name = 'Heavy Block 6';
    $this->manufactured->sku = 'MFG-HEAVY-6';
    $this->manufactured->inventory_source = Product::INVENTORY_SOURCE_MANUFACTURED;
    $this->manufactured->save();

    $this->purchased = Product::query()->whereKeyNot($this->manufactured->id)->firstOrFail();
    $this->purchased->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED]);

    $this->machine = Machine::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Block Press A',
        'code' => 'BP-A',
        'daily_capacity' => 1000,
        'capacity_unit' => 'pcs_per_day',
        'status' => Machine::STATUS_ACTIVE,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);

    ProductFamily::ensureDefaultsForCompany($this->company->id);
    $this->manufactured->refresh();
    $this->recipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id,
        'product_id' => $this->manufactured->id,
        'name' => 'Heavy Block Active Recipe',
        'code' => 'HEAVY-BLOCK-ACTIVE',
        'version' => '1',
        'output_quantity' => 1,
        'output_unit_id' => $this->manufactured->unit_id,
        'status' => ProductionRecipe::STATUS_ACTIVE,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    $this->mould = installScheduleTestMould($this, $this->machine, 'SCHEDULE-MOULD-A');
});

function installScheduleTestMould(object $test, Machine $machine, string $code): ProductionMould
{
    $mould = ProductionMould::query()->create([
        'company_id' => $test->company->id,
        'product_family_id' => $test->manufactured->product_family_id,
        'code' => $code,
        'name' => $code,
        'active' => true,
    ]);
    $mould->compatibleMachines()->syncWithPivotValues([$machine->id], ['company_id' => $test->company->id]);
    app(ProductionMouldService::class)->install($machine, $mould, $test->admin);

    return $mould;
}

function productionAssignmentData(object $test, array $overrides = []): array
{
    return [
        'machine_id' => $test->machine->id,
        'product_id' => $test->manufactured->id,
        'production_recipe_id' => $test->recipe->id,
        'branch_id' => $test->branch->id,
        'production_date' => '2026-07-29',
        'target_quantity' => 500,
        'planned_start_time' => '08:00',
        'planned_end_time' => '16:00',
        'status' => ProductionMachineAssignment::STATUS_PLANNED,
        'notes' => 'Day plan',
        ...$overrides,
    ];
}

test('production is hidden and routes are blocked when manufacturing is disabled', function () {
    $this->company->update(['manufacturing_enabled' => false]);

    $this->get(route('dashboard'))->assertOk()->assertDontSee(__('production.title'));
    $this->get(route('production.index'))->assertForbidden();
    Volt::test('production.overview')->assertForbidden();
});

test('production menu is visible when enabled and permission is granted', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('production.title'))
        ->assertSee(__('production.machines'))
        ->assertSee(__('production.daily_schedule'));
});

test('user without production permission cannot access production', function () {
    $cashier = User::factory()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'is_system_owner' => false,
    ]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);

    $this->get(route('production.index'))->assertForbidden();
    Volt::test('production.machines.index')->assertForbidden();
});

test('admin can create and update machine statuses for own company', function () {
    Volt::test('production.machines.index')
        ->set('name', 'Mixer One')
        ->set('code', 'MIX-1')
        ->set('branch_id', (string) $this->branch->id)
        ->set('daily_capacity', '50')
        ->set('status', Machine::STATUS_MAINTENANCE)
        ->call('save')
        ->assertHasNoErrors();

    $machine = Machine::query()->where('code', 'MIX-1')->firstOrFail();
    expect($machine->company_id)->toBe($this->company->id)
        ->and($machine->status)->toBe(Machine::STATUS_MAINTENANCE);

    Volt::test('production.machines.index')
        ->call('setStatus', $machine->id, Machine::STATUS_INACTIVE);

    expect($machine->refresh()->status)->toBe(Machine::STATUS_INACTIVE);
});

test('machine name and code uniqueness is company scoped and invalid branches are rejected', function () {
    Volt::test('production.machines.index')
        ->set('name', $this->machine->name)
        ->set('code', 'OTHER-CODE')
        ->call('save')
        ->assertHasErrors(['name']);

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Hardware',
        'business_type' => 'Hardware Store',
        'phone' => '+255700900001',
        'whatsapp_number' => '+255700900001',
        'manufacturing_enabled' => true,
    ]);
    $otherBranchId = Branch::withoutGlobalScopes()->insertGetId([
        'company_id' => $otherCompany->id,
        'name' => 'Other Branch',
        'code' => 'OTH',
        'status' => 'active',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Volt::test('production.machines.index')
        ->set('name', 'Invalid Branch Machine')
        ->set('branch_id', (string) $otherBranchId)
        ->call('save')
        ->assertHasErrors(['branch_id']);
});

test('manufactured product can be assigned without creating stock movement', function () {
    $before = StockMovement::withoutGlobalScopes()->count();

    $assignment = app(ProductionScheduleService::class)
        ->save(productionAssignmentData($this), $this->admin);

    expect($assignment->product_id)->toBe($this->manufactured->id)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($before);
});

test('purchased product cannot be assigned', function () {
    app(ProductionScheduleService::class)
        ->save(productionAssignmentData($this, ['product_id' => $this->purchased->id]), $this->admin);
})->throws(ValidationException::class);

test('inactive and maintenance machines cannot be assigned', function (string $status) {
    $this->machine->update(['status' => $status]);

    app(ProductionScheduleService::class)
        ->save(productionAssignmentData($this), $this->admin);
})->with([Machine::STATUS_INACTIVE, Machine::STATUS_MAINTENANCE])
    ->throws(ValidationException::class);

test('target quantity must be positive in schedule component', function () {
    Volt::test('production.schedule.index')
        ->set('machine_id', (string) $this->machine->id)
        ->set('product_id', (string) $this->manufactured->id)
        ->set('branch_id', (string) $this->branch->id)
        ->set('target_quantity', '0')
        ->call('save')
        ->assertHasErrors(['target_quantity']);
});

test('concurrent creation attempts cannot produce two active assignments for one machine and date', function () {
    $first = app(ProductionScheduleService::class)->save(productionAssignmentData($this), $this->admin);

    expect(fn () => app(ProductionScheduleService::class)
        ->save(productionAssignmentData($this), $this->admin))
        ->toThrow(ValidationException::class);

    expect(fn () => ProductionMachineAssignment::withoutGlobalScopes()->create([
        ...productionAssignmentData($this),
        'company_id' => $this->company->id,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]))->toThrow(QueryException::class);

    expect($first->active_slot_key)->toBe(ProductionMachineAssignment::activeSlotKey(
        $this->company->id,
        $this->machine->id,
        '2026-07-29',
        ProductionMachineAssignment::STATUS_PLANNED,
    ));
});

test('an existing assignment never removes an active machine from the daily assignment dropdown', function () {
    $this->machine->update(['name' => 'Block Machine A']);
    $assignment = app(ProductionScheduleService::class)->save(productionAssignmentData($this), $this->admin);

    Volt::test('production.machines.index')
        ->set('statusFilter', Machine::STATUS_ACTIVE)
        ->assertSee('Block Machine A');

    $schedule = Volt::test('production.schedule.index')
        ->set('selectedDate', $assignment->production_date->toDateString());

    preg_match('/<select data-testid="assignment-machine-select".*?<\/select>/s', $schedule->html(), $machineSelect);
    expect(Machine::query()->forCurrentCompany()->active()->count())->toBe(1)
        ->and($machineSelect[0] ?? '')->toContain('Block Machine A');

    $schedule->set('machine_id', (string) $this->machine->id)
        ->assertSee('Assignment already exists.')
        ->assertSee('Edit existing assignment');

    preg_match('/<select data-testid="assignment-machine-select".*?<\/select>/s', $schedule->html(), $machineSelectAfterSelection);
    expect($machineSelectAfterSelection[0] ?? '')->toContain('Block Machine A');
});

test('same machine can be scheduled on another date and different machines on the same date', function () {
    app(ProductionScheduleService::class)->save(productionAssignmentData($this), $this->admin);
    app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['production_date' => '2026-07-30']),
        $this->admin
    );

    $secondMachine = Machine::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Block Press B',
        'code' => 'BP-B',
        'status' => Machine::STATUS_ACTIVE,
    ]);
    installScheduleTestMould($this, $secondMachine, 'SCHEDULE-MOULD-B');
    app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['machine_id' => $secondMachine->id]),
        $this->admin
    );

    expect(ProductionMachineAssignment::query()->count())->toBe(3);
});

test('cancelled assignment keeps its history and allows a new assignment in the same slot', function () {
    $assignment = app(ProductionScheduleService::class)->save(productionAssignmentData($this), $this->admin);
    $location = StockLocation::query()->where('branch_id', $this->branch->id)->firstOrFail();
    $cancelledOrder = ProductionOrder::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'production_machine_assignment_id' => $assignment->id,
        'machine_id' => $this->machine->id,
        'product_id' => $this->manufactured->id,
        'production_recipe_id' => $this->recipe->id,
        'raw_material_stock_location_id' => $location->id,
        'finished_goods_stock_location_id' => $location->id,
        'order_number' => 'PRD-CANCELLED-SCHEDULE-HISTORY',
        'production_date' => $assignment->production_date,
        'planned_quantity' => 500,
        'status' => ProductionOrder::STATUS_CANCELLED,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Schedule replacement fixture',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
        'cancelled_by' => $this->admin->id,
    ]);
    $assignment->update([
        'status' => ProductionMachineAssignment::STATUS_CANCELLED,
        'updated_by' => $this->admin->id,
    ]);
    $assignmentHistory = $assignment->fresh()->getAttributes();
    $orderHistory = $cancelledOrder->fresh()->getAttributes();
    $movementCount = StockMovement::withoutGlobalScopes()->count();

    $schedule = Volt::test('production.schedule.index')
        ->set('selectedDate', '2026-07-29')
        ->set('branch_id', (string) $this->branch->id)
        ->set('machine_id', (string) $this->machine->id)
        ->assertDontSee('Assignment already exists.')
        ->assertSet('availableProducts.0.id', $this->manufactured->id)
        ->assertSee('SCHEDULE-MOULD-A')
        ->assertSee('Create Replacement')
        ->assertSee('Reactivate');

    preg_match('/<select data-testid="assignment-product-select".*?<\/select>/s', $schedule->html(), $productSelect);
    expect($productSelect[0] ?? '')
        ->toContain('value="'.$this->manufactured->id.'"')
        ->not->toMatch('/\sdisabled(?:\s|>)/');

    $schedule->set('product_id', (string) $this->manufactured->id)
        ->assertSet('production_recipe_id', (string) $this->recipe->id)
        ->set('target_quantity', '600')
        ->set('planned_start_time', '08:00')
        ->set('planned_end_time', '16:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Cancelled')
        ->assertSee('Planned');

    $newAssignment = ProductionMachineAssignment::query()
        ->whereKeyNot($assignment->id)
        ->whereDate('production_date', '2026-07-29')
        ->firstOrFail();

    expect($newAssignment->id)->not->toBe($assignment->id)
        ->and($newAssignment->status)->toBe(ProductionMachineAssignment::STATUS_PLANNED)
        ->and($assignment->fresh()->getAttributes())->toBe($assignmentHistory)
        ->and($cancelledOrder->fresh()->getAttributes())->toBe($orderHistory)
        ->and(ProductionMachineAssignment::query()->count())->toBe(2)
        ->and(ProductionMachineAssignment::query()->eligibleForProductionOrder()->pluck('id')->all())
        ->toBe([$newAssignment->id])
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($movementCount);

    Volt::test('production.schedule.index')
        ->call('setAssignmentStatus', $assignment->id, ProductionMachineAssignment::STATUS_PLANNED)
        ->assertHasErrors(['machine_id']);

    expect($assignment->refresh()->status)->toBe(ProductionMachineAssignment::STATUS_CANCELLED)
        ->and($cancelledOrder->fresh()->getAttributes())->toBe($orderHistory);
});

test('create replacement prepares a new row while reactivate intentionally edits the cancelled row', function () {
    $cancelled = app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['status' => ProductionMachineAssignment::STATUS_CANCELLED]),
        $this->admin,
    );

    $component = Volt::test('production.schedule.index')
        ->call('createNewAssignmentFrom', $cancelled->id)
        ->assertSet('editingId', null)
        ->assertSet('selectedDate', '2026-07-29')
        ->assertSet('machine_id', (string) $this->machine->id)
        ->assertSet('availableProducts.0.id', $this->manufactured->id)
        ->set('product_id', (string) $this->manufactured->id)
        ->assertSet('production_recipe_id', (string) $this->recipe->id)
        ->set('target_quantity', '500')
        ->call('save')
        ->assertHasNoErrors();

    $replacement = ProductionMachineAssignment::query()->whereKeyNot($cancelled->id)->firstOrFail();
    expect($replacement->id)->not->toBe($cancelled->id)
        ->and($cancelled->refresh()->status)->toBe(ProductionMachineAssignment::STATUS_CANCELLED);

    $replacement->update(['status' => ProductionMachineAssignment::STATUS_CANCELLED]);
    Volt::test('production.schedule.index')
        ->call('editAssignment', $cancelled->id)
        ->assertSet('editingId', $cancelled->id)
        ->assertSet('status', ProductionMachineAssignment::STATUS_CANCELLED);
});

test('completed assignment is read only and completion creates no stock movement', function () {
    $assignment = app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['status' => ProductionMachineAssignment::STATUS_CONFIRMED]),
        $this->admin
    );
    $before = StockMovement::withoutGlobalScopes()->count();

    Volt::test('production.schedule.index')
        ->call('setAssignmentStatus', $assignment->id, ProductionMachineAssignment::STATUS_COMPLETED);

    expect($assignment->refresh()->status)->toBe(ProductionMachineAssignment::STATUS_COMPLETED)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($before);

    expect(fn () => app(ProductionScheduleService::class)
        ->save(productionAssignmentData($this, ['target_quantity' => 900]), $this->admin, $assignment->refresh()))
        ->toThrow(ValidationException::class);
});

test('machine with assignment cannot be archived', function () {
    app(ProductionScheduleService::class)->save(productionAssignmentData($this), $this->admin);

    Volt::test('production.machines.index')->call('archiveMachine', $this->machine->id);

    expect(Machine::query()->whereKey($this->machine->id)->exists())->toBeTrue();
});

test('other company machine product branch and schedule data are rejected and hidden', function () {
    $otherCompany = Company::query()->create([
        'company_name' => 'Isolated Hardware',
        'business_type' => 'Hardware Store',
        'phone' => '+255700900002',
        'whatsapp_number' => '+255700900002',
        'manufacturing_enabled' => true,
    ]);
    $otherBranchId = Branch::withoutGlobalScopes()->insertGetId([
        'company_id' => $otherCompany->id,
        'name' => 'Secret Production Site',
        'code' => 'SEC',
        'status' => 'active',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherMachineId = Machine::withoutGlobalScopes()->insertGetId([
        'company_id' => $otherCompany->id,
        'branch_id' => $otherBranchId,
        'name' => 'Secret Machine',
        'code' => $this->machine->code,
        'status' => Machine::STATUS_ACTIVE,
        'capacity_unit' => 'pcs_per_day',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherProductAttributes = $this->manufactured->getAttributes();
    unset($otherProductAttributes['id']);
    $otherProductAttributes['company_id'] = $otherCompany->id;
    $otherProductAttributes['branch_id'] = $otherBranchId;
    $otherProductAttributes['name'] = 'Secret Manufactured Product';
    $otherProductAttributes['sku'] = 'OTHER-MFG-001';
    $otherProductAttributes['created_at'] = now();
    $otherProductAttributes['updated_at'] = now();
    $otherProductId = DB::table('products')->insertGetId($otherProductAttributes);
    DB::table('production_machine_assignments')->insert([
        'company_id' => $otherCompany->id,
        'branch_id' => $otherBranchId,
        'machine_id' => $otherMachineId,
        'product_id' => $otherProductId,
        'production_date' => '2026-07-29',
        'status' => ProductionMachineAssignment::STATUS_PLANNED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Volt::test('production.machines.index')->assertDontSee('Secret Machine');
    Volt::test('production.schedule.index')
        ->set('selectedDate', '2026-07-29')
        ->assertDontSee('Secret Manufactured Product');

    expect(fn () => app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['machine_id' => $otherMachineId]),
        $this->admin
    ))->toThrow(ValidationException::class);

    expect(fn () => app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['product_id' => $otherProductId]),
        $this->admin
    ))->toThrow(ValidationException::class);

    expect(fn () => app(ProductionScheduleService::class)->save(
        productionAssignmentData($this, ['branch_id' => $otherBranchId]),
        $this->admin
    ))->toThrow(ValidationException::class);

    expect(fn () => Volt::test('production.machines.index')->call('editMachine', $otherMachineId))
        ->toThrow(ModelNotFoundException::class);
});
