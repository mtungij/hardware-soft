<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ProductionLocationService;
use App\Services\ProductionMouldService;
use App\Services\ProductionOrderService;
use App\Services\ProductionRecipeCalculator;
use App\Services\ProductionRecipeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->actingAs($this->admin);
    $this->branch = Branch::query()->whereKey($this->admin->branch_id)->firstOrFail();
    $template = Product::query()->firstOrFail();
    $this->finished = $template->replicate();
    $this->finished->forceFill(['name' => 'Execution Block', 'sku' => 'EXEC-BLOCK', 'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED])->save();
    $this->material = Product::query()->whereKeyNot($this->finished->id)->firstOrFail();
    $this->material->update(['inventory_source' => Product::INVENTORY_SOURCE_PURCHASED]);
    $this->unit = Unit::query()->findOrFail($this->material->purchase_unit_id ?: $this->material->unit_id);
    $this->machine = Machine::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Execution Press', 'code' => 'EXEC-PRESS', 'status' => 'active']);
    $this->assignment = ProductionMachineAssignment::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'machine_id' => $this->machine->id, 'product_id' => $this->finished->id,
        'production_date' => '2026-07-29', 'target_quantity' => 1440, 'status' => 'confirmed',
    ]);
    $this->location = StockLocation::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Production Test Raw Materials',
        'code' => 'PRODUCTION-TEST-RAW',
        'type' => 'warehouse',
        'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'is_sellable' => false, 'can_sell' => false,
    ]);
    $this->curingLocation = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'name' => 'Production Test Curing Yard', 'code' => 'PRODUCTION-TEST-CURING', 'type' => 'curing',
        'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'is_sellable' => false, 'can_sell' => false,
    ]);
    $this->finishedLocation = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'name' => 'Production Test Finished Goods', 'code' => 'PRODUCTION-TEST-FINISHED', 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_issue_stock' => true,
        'can_receive_stock' => true, 'can_transfer' => true, 'is_sellable' => true, 'can_sell' => true,
    ]);
    Setting::query()->firstOrFail()->update([
        'default_raw_material_location_id' => $this->location->id,
        'default_curing_location_id' => $this->curingLocation->id,
        'default_finished_goods_location_id' => $this->finishedLocation->id,
    ]);
    $this->recipe = app(ProductionRecipeService::class)->save([
        'name' => 'Execution Recipe', 'code' => 'EXEC-R1', 'version' => '1',
        'product_id' => $this->finished->id, 'output_quantity' => 1,
        'output_unit_id' => $this->finished->unit_id, 'status' => ProductionRecipe::STATUS_ACTIVE,
        'effective_from' => '', 'effective_to' => '', 'notes' => '',
    ], [
        [
            'cost_type' => ProductionRecipeItem::TYPE_INVENTORY, 'material_product_id' => $this->material->id,
            'material_unit_id' => $this->unit->id, 'entry_mode' => 'yield',
            'source_quantity' => 1, 'yield_quantity' => 80, 'notes' => '',
        ],
        [
            'cost_type' => ProductionRecipeItem::TYPE_NON_INVENTORY, 'cost_name' => 'Electricity',
            'source_quantity' => '', 'material_unit_id' => '', 'unit_cost' => 20, 'notes' => '',
        ],
    ], $this->admin);
    $this->assignment->update(['production_recipe_id' => $this->recipe->id]);
});

function createProductionOrderForTest(object $test, array $overrides = []): ProductionOrder
{
    return app(ProductionOrderService::class)->createFromAssignment($test->assignment, [
        'planned_quantity' => 1440,
        'raw_material_stock_location_id' => $test->location->id,
        'production_output_stock_location_id' => $test->curingLocation->id,
        'final_finished_goods_stock_location_id' => $test->finishedLocation->id,
        'notes' => 'Execution plan',
        ...$overrides,
    ], $test->admin);
}

function stockProductionMaterialForTest(object $test, string $quantity = '100'): void
{
    app(InventoryService::class)->directStockIn([
        'branch_id' => $test->branch->id, 'product_id' => $test->material->id,
        'stock_location_id' => $test->location->id, 'quantity' => $quantity,
        'cost_price' => 10, 'selling_price' => 20, 'reason' => 'Production lifecycle test',
        'notes' => '', 'movement_date' => '2026-07-29',
    ], $test->admin->id);
}

function createProductionLocationForTest(object $test, string $code, string $purpose, ?int $branchId = null): StockLocation
{
    $attributes = match ($purpose) {
        'raw' => ['type' => 'warehouse', 'is_sellable' => false, 'can_sell' => false],
        'curing' => ['type' => 'curing', 'is_sellable' => false, 'can_sell' => false],
        'finished' => ['type' => 'store', 'is_sellable' => true, 'can_sell' => true],
    };

    return StockLocation::query()->create([
        'company_id' => $test->company->id,
        'branch_id' => $branchId,
        'name' => str($code)->headline()->toString(),
        'code' => $code,
        'status' => 'active',
        'is_active' => true,
        'can_issue_stock' => true,
        'can_receive_stock' => true,
        'can_transfer' => true,
        ...$attributes,
    ]);
}

function executeProductionOrderForTest(object $test, ProductionOrder $order, string $actual = '18', string $accepted = '1400', string $rejected = '40'): ProductionOrder
{
    $order = app(ProductionOrderService::class)->start($order, $test->admin);
    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => $line->line_type === ProductionOrderMaterial::TYPE_INVENTORY ? $actual : $line->actual_quantity,
        'actual_cost' => $line->actual_cost,
    ]])->all();
    $order = app(ProductionOrderService::class)->saveExecution($order, $materials, $accepted, $rejected, 'Actual execution', $test->admin);

    return app(ProductionOrderService::class)->submit($order, $test->admin);
}

test('precision is hardened to twelve decimals with practically exact yield multiplication', function () {
    $calculator = app(ProductionRecipeCalculator::class);

    expect($calculator->normalizeYield(1, 1200))->toBe('0.000833333333')
        ->and(bcmul($calculator->normalizeYield(1, 1200), '1200', 12))->toBe('0.999999999600')
        ->and($calculator->normalizeYield(1, 230))->toBe('0.004347826086')
        ->and($calculator->normalizeYield(1, 125))->toBe('0.008000000000');
});

test('order routes and menu require manufacturing and order permissions', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(__('production.orders.title'));
    $this->get(route('production.orders.index'))->assertOk();
    $this->company->update(['manufacturing_enabled' => false]);
    $this->get(route('production.orders.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee(__('production.orders.title'));
    $this->company->update(['manufacturing_enabled' => true]);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'is_system_owner' => false]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier);
    Volt::test('production.orders.index')->assertForbidden();
});

test('production order assignment dropdown includes global planned and confirmed assignments until linked', function () {
    $mould = ProductionMould::query()->create([
        'company_id' => $this->company->id,
        'product_family_id' => $this->finished->product_family_id,
        'code' => 'ORDER-DROPDOWN-MOULD',
        'name' => 'Order Dropdown Mould',
        'active' => true,
    ]);
    $mould->compatibleMachines()->syncWithPivotValues([$this->machine->id], ['company_id' => $this->company->id]);
    $installation = app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin);
    $this->assignment->update([
        'branch_id' => null,
        'status' => ProductionMachineAssignment::STATUS_CONFIRMED,
        'production_mould_id' => $mould->id,
        'production_mould_installation_id' => $installation->id,
        'production_recipe_id' => $this->recipe->id,
    ]);
    $planned = $this->assignment->replicate()->forceFill([
        'branch_id' => null,
        'production_date' => '2026-07-30',
        'status' => ProductionMachineAssignment::STATUS_PLANNED,
    ]);
    $planned->save();

    $component = Volt::test('production.orders.create');
    preg_match('/<select data-testid="production-order-assignment-select".*?<\/select>/s', $component->html(), $assignmentSelect);
    expect($assignmentSelect[0] ?? '')
        ->toContain('value="'.$this->assignment->id.'"')
        ->toContain('value="'.$planned->id.'"');

    createProductionOrderForTest($this);

    $component = Volt::test('production.orders.create');
    preg_match('/<select data-testid="production-order-assignment-select".*?<\/select>/s', $component->html(), $assignmentSelectAfterOrder);
    expect($assignmentSelectAfterOrder[0] ?? '')
        ->not->toContain('value="'.$this->assignment->id.'"')
        ->toContain('value="'.$planned->id.'"');
});

test('valid assignment creates planned order frozen snapshot and requirements without stock', function () {
    $movements = StockMovement::withoutGlobalScopes()->count();
    $order = createProductionOrderForTest($this);
    $inventory = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_INVENTORY);
    $cost = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_NON_INVENTORY_COST);

    expect($order->status)->toBe(ProductionOrder::STATUS_PLANNED)
        ->and($order->order_number)->toStartWith('PRD-20260729-')
        ->and($order->snapshot->recipe_name)->toBe('Execution Recipe')
        ->and($inventory->normalized_quantity_per_output)->toBe('0.012500000000')
        ->and($inventory->planned_quantity)->toBe('18.000000000000')
        ->and($cost->planned_cost)->toBe('28800.0000')
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($movements);

    $this->recipe->items()->first()->update(['normalized_quantity' => '9']);
    expect($order->refresh()->materials()->first()->normalized_quantity_per_output)->toBe('0.012500000000');
});

test('admin configures company production defaults and invalid locations are rejected', function () {
    $alternateRaw = createProductionLocationForTest($this, 'SETTINGS-RAW', 'raw', $this->branch->id);
    $alternateCuring = createProductionLocationForTest($this, 'SETTINGS-CURING', 'curing', $this->branch->id);
    $alternateFinished = createProductionLocationForTest($this, 'SETTINGS-FINISHED', 'finished', $this->branch->id);

    Volt::test('settings.company')
        ->set('default_raw_material_location_id', (string) $alternateRaw->id)
        ->set('default_curing_location_id', (string) $alternateCuring->id)
        ->set('default_finished_goods_location_id', (string) $alternateFinished->id)
        ->call('save')
        ->assertHasNoErrors();

    $setting = Setting::query()->firstOrFail();
    expect($setting->default_raw_material_location_id)->toBe($alternateRaw->id)
        ->and($setting->default_curing_location_id)->toBe($alternateCuring->id)
        ->and($setting->default_finished_goods_location_id)->toBe($alternateFinished->id);

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Defaults Company', 'business_type' => 'Factory',
        'phone' => '+255700844444', 'whatsapp_number' => '+255700844444',
    ]);
    $crossCompany = StockLocation::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id, 'name' => 'Other Raw', 'code' => 'OTHER-RAW',
        'type' => 'warehouse', 'status' => 'active', 'is_active' => true,
        'can_receive_stock' => true, 'can_issue_stock' => true, 'is_sellable' => false,
    ]);
    Volt::test('settings.company')
        ->set('default_raw_material_location_id', (string) $crossCompany->id)
        ->call('save')
        ->assertHasErrors(['default_raw_material_location_id']);

    $alternateCuring->update(['status' => 'inactive', 'is_active' => false]);
    Volt::test('settings.company')
        ->set('default_curing_location_id', (string) $alternateCuring->id)
        ->call('save')
        ->assertHasErrors(['default_curing_location_id']);

    Volt::test('settings.company')
        ->set('default_raw_material_location_id', (string) $alternateFinished->id)
        ->call('save')
        ->assertHasErrors(['default_raw_material_location_id']);
});

test('production location permissions follow the intended role matrix', function () {
    $operator = Role::findByName('Production Operator');
    $manager = Role::findByName('Production Manager');
    $admin = Role::findByName('Admin');
    $superAdmin = Role::findByName('Super Admin');

    expect($operator->hasPermissionTo('production.override_default_locations'))->toBeFalse()
        ->and($operator->hasPermissionTo('production.manage_location_defaults'))->toBeFalse()
        ->and($manager->hasPermissionTo('production.override_default_locations'))->toBeTrue()
        ->and($manager->hasPermissionTo('production.manage_location_defaults'))->toBeFalse()
        ->and($admin->hasPermissionTo('production.override_default_locations'))->toBeTrue()
        ->and($admin->hasPermissionTo('production.manage_location_defaults'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('production.override_default_locations'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('production.manage_location_defaults'))->toBeTrue();
});

test('company settings access alone cannot manipulate production defaults', function () {
    $alternateRaw = createProductionLocationForTest($this, 'UNAUTHORISED-SETTINGS-RAW', 'raw', $this->branch->id);
    $settingsBefore = Setting::query()->firstOrFail()->only([
        'default_raw_material_location_id', 'default_curing_location_id', 'default_finished_goods_location_id',
    ]);
    $manager = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'status' => 'active', 'is_system_owner' => false,
    ]);
    $manager->assignRole('Manager');
    $manager->givePermissionTo('company-settings.update');
    $this->actingAs($manager);

    Volt::test('settings.company')
        ->set('default_raw_material_location_id', (string) $alternateRaw->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->firstOrFail()->only(array_keys($settingsBefore)))->toBe($settingsBefore);
});

test('production operator receives locked defaults and manipulated locations are ignored', function () {
    $this->finished->update(['requires_curing' => true, 'curing_days_required' => 7, 'sellable_after_days' => 7]);
    $alternateRaw = createProductionLocationForTest($this, 'OPERATOR-RAW', 'raw', $this->branch->id);
    $alternateCuring = createProductionLocationForTest($this, 'OPERATOR-CURING', 'curing', $this->branch->id);
    $alternateFinished = createProductionLocationForTest($this, 'OPERATOR-FINISHED', 'finished', $this->branch->id);
    $operator = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'status' => 'active', 'is_system_owner' => false,
    ]);
    $operator->assignRole('Production Operator');
    $settingsBefore = Setting::query()->firstOrFail()->only([
        'default_raw_material_location_id', 'default_curing_location_id', 'default_finished_goods_location_id',
    ]);
    $movementsBefore = StockMovement::withoutGlobalScopes()->count();
    $this->actingAs($operator);

    $component = Volt::test('production.orders.create')
        ->set('assignment_id', (string) $this->assignment->id)
        ->assertSet('raw_location_id', (string) $this->location->id)
        ->assertSet('output_location_id', (string) $this->curingLocation->id)
        ->assertSet('finished_location_id', (string) $this->finishedLocation->id);

    foreach (['production-raw-location', 'production-curing-location', 'production-finished-location'] as $testId) {
        preg_match('/<select data-testid="'.$testId.'".*?<\/select>/s', $component->html(), $select);
        expect($select[0] ?? '')->toMatch('/\sdisabled(?:\s|>)/');
    }

    $component
        ->set('raw_location_id', (string) $alternateRaw->id)
        ->set('output_location_id', (string) $alternateCuring->id)
        ->set('finished_location_id', (string) $alternateFinished->id)
        ->call('save')
        ->assertHasNoErrors();

    $order = ProductionOrder::query()->where('production_machine_assignment_id', $this->assignment->id)->firstOrFail();
    expect($order->raw_material_stock_location_id)->toBe($this->location->id)
        ->and($order->production_output_stock_location_id)->toBe($this->curingLocation->id)
        ->and($order->final_finished_goods_stock_location_id)->toBe($this->finishedLocation->id)
        ->and(Setting::query()->firstOrFail()->only(array_keys($settingsBefore)))->toBe($settingsBefore)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($movementsBefore);
});

test('authorised production location override is validated and does not change company defaults', function () {
    $this->finished->update(['requires_curing' => true, 'curing_days_required' => 7, 'sellable_after_days' => 7]);
    $alternateRaw = createProductionLocationForTest($this, 'MANAGER-RAW', 'raw', $this->branch->id);
    $alternateCuring = createProductionLocationForTest($this, 'MANAGER-CURING', 'curing', $this->branch->id);
    $alternateFinished = createProductionLocationForTest($this, 'MANAGER-FINISHED', 'finished', $this->branch->id);
    $settingsBefore = Setting::query()->firstOrFail()->getAttributes();

    expect(fn () => app(ProductionOrderService::class)->createFromAssignment($this->assignment, [
        'planned_quantity' => 1440,
        'raw_material_stock_location_id' => $alternateFinished->id,
        'production_output_stock_location_id' => $alternateCuring->id,
        'final_finished_goods_stock_location_id' => $alternateFinished->id,
    ], $this->admin))->toThrow(ValidationException::class);
    expect(ProductionOrder::query()->where('production_machine_assignment_id', $this->assignment->id)->doesntExist())->toBeTrue();

    Volt::test('production.orders.create')
        ->set('assignment_id', (string) $this->assignment->id)
        ->assertSet('raw_location_id', (string) $this->location->id)
        ->set('raw_location_id', (string) $alternateRaw->id)
        ->set('output_location_id', (string) $alternateCuring->id)
        ->set('finished_location_id', (string) $alternateFinished->id)
        ->call('save')
        ->assertHasNoErrors();

    $order = ProductionOrder::query()->where('production_machine_assignment_id', $this->assignment->id)->firstOrFail();
    expect($order->raw_material_stock_location_id)->toBe($alternateRaw->id)
        ->and($order->production_output_stock_location_id)->toBe($alternateCuring->id)
        ->and($order->final_finished_goods_stock_location_id)->toBe($alternateFinished->id)
        ->and(Setting::query()->firstOrFail()->getAttributes())->toBe($settingsBefore);
});

test('missing and branch-incompatible production defaults block order creation', function () {
    Setting::query()->firstOrFail()->update(['default_curing_location_id' => null]);
    Volt::test('production.orders.create')
        ->set('assignment_id', (string) $this->assignment->id)
        ->assertSee(ProductionLocationService::CONFIGURATION_MESSAGE)
        ->call('save')
        ->assertHasErrors(['production_location_defaults']);
    expect(ProductionOrder::query()->where('production_machine_assignment_id', $this->assignment->id)->doesntExist())->toBeTrue();

    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Other Production Branch',
        'code' => 'OTHER-PRODUCTION-BRANCH', 'status' => 'active', 'is_default' => false,
    ]);
    $wrongBranchRaw = createProductionLocationForTest($this, 'WRONG-BRANCH-RAW-DEFAULT', 'raw', $otherBranch->id);
    Setting::query()->firstOrFail()->update([
        'default_raw_material_location_id' => $wrongBranchRaw->id,
        'default_curing_location_id' => $this->curingLocation->id,
    ]);

    expect(fn () => createProductionOrderForTest($this))->toThrow(ValidationException::class);
    expect(ProductionOrder::query()->where('production_machine_assignment_id', $this->assignment->id)->doesntExist())->toBeTrue();
});

test('company-wide defaults work and existing orders retain their selected locations', function () {
    $companyRaw = createProductionLocationForTest($this, 'COMPANY-WIDE-RAW', 'raw');
    $companyCuring = createProductionLocationForTest($this, 'COMPANY-WIDE-CURING', 'curing');
    $companyFinished = createProductionLocationForTest($this, 'COMPANY-WIDE-FINISHED', 'finished');
    Setting::query()->firstOrFail()->update([
        'default_raw_material_location_id' => $companyRaw->id,
        'default_curing_location_id' => $companyCuring->id,
        'default_finished_goods_location_id' => $companyFinished->id,
    ]);

    $operator = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active',
    ]);
    $operator->assignRole('Production Operator');
    $order = app(ProductionOrderService::class)->createFromAssignment($this->assignment, [
        'planned_quantity' => 1440,
        'raw_material_stock_location_id' => $this->location->id,
        'final_finished_goods_stock_location_id' => $this->finishedLocation->id,
    ], $operator);

    expect($order->raw_material_stock_location_id)->toBe($companyRaw->id)
        ->and($order->final_finished_goods_stock_location_id)->toBe($companyFinished->id);

    Setting::query()->firstOrFail()->update([
        'default_raw_material_location_id' => $this->location->id,
        'default_finished_goods_location_id' => $this->finishedLocation->id,
    ]);
    expect($order->fresh()->raw_material_stock_location_id)->toBe($companyRaw->id)
        ->and($order->fresh()->final_finished_goods_stock_location_id)->toBe($companyFinished->id);
});

test('configured raw default drives live availability and start validation without planning stock movements', function () {
    stockProductionMaterialForTest($this, '20');
    $beforeCreate = StockMovement::withoutGlobalScopes()->count();
    $order = createProductionOrderForTest($this, ['planned_quantity' => 10]);
    $inventoryLine = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_INVENTORY);

    expect(app(ProductionOrderService::class)->availability($order)[$inventoryLine->id])->toBe('20.0000')
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($beforeCreate);

    $started = app(ProductionOrderService::class)->start($order, $this->admin);
    expect($started->status)->toBe(ProductionOrder::STATUS_IN_PROGRESS)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($beforeCreate);
});

test('availability stays scoped to the selected raw location when stock exists at another location', function () {
    $diagnosticMaterial = $this->material->replicate();
    $diagnosticMaterial->forceFill([
        'name' => 'Location Diagnostic Cement',
        'sku' => 'LOCATION-DIAGNOSTIC-CEMENT',
        'inventory_source' => Product::INVENTORY_SOURCE_PURCHASED,
    ])->save();
    $this->recipe->items()->where('cost_type', ProductionRecipeItem::TYPE_INVENTORY)->firstOrFail()->update([
        'material_product_id' => $diagnosticMaterial->id,
    ]);

    $stockedLocation = StockLocation::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Raw Materials Store Diagnostic',
        'code' => 'RAW-MATERIALS-DIAGNOSTIC',
        'type' => 'warehouse',
        'status' => 'active',
        'is_active' => true,
        'can_issue_stock' => true,
        'can_receive_stock' => true,
    ]);
    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->branch->id,
        'product_id' => $diagnosticMaterial->id,
        'stock_location_id' => $stockedLocation->id,
        'quantity' => 20,
        'cost_price' => 10,
        'selling_price' => 20,
        'reason' => 'Location mismatch regression fixture',
        'notes' => '',
        'movement_date' => '2026-07-29',
    ], $this->admin->id);

    $order = createProductionOrderForTest($this, [
        'raw_material_stock_location_id' => $this->location->id,
    ]);
    $materialLine = $order->materials()->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY)->firstOrFail();
    $availability = app(ProductionOrderService::class)->availability($order);

    expect($order->raw_material_stock_location_id)->toBe($this->location->id)
        ->and($availability[$materialLine->id])->toBe('0.0000')
        ->and(app(InventoryService::class)->getProductStock(
            $diagnosticMaterial->id,
            $stockedLocation->id,
            $this->branch->id,
        ))->toBe(20.0);

    Volt::test('production.orders.show', ['order' => $order])
        ->assertSee('Location Diagnostic Cement')
        ->assertSee('0.0000')
        ->assertSee('Shortage');

    Volt::test('store-stock.index')
        ->set('locationFilter', (string) $stockedLocation->id)
        ->set('search', 'Location Diagnostic Cement')
        ->assertSee('Raw Materials Store Diagnostic')
        ->assertSee('20');
});

test('duplicate order inactive recipe and missing active recipe are rejected', function () {
    createProductionOrderForTest($this);
    expect(fn () => createProductionOrderForTest($this))->toThrow(ValidationException::class);

    $this->recipe->update(['status' => ProductionRecipe::STATUS_INACTIVE]);
    $second = ProductionMachineAssignment::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'machine_id' => $this->machine->id,
        'product_id' => $this->finished->id, 'production_date' => '2026-07-30', 'target_quantity' => 10, 'status' => 'confirmed',
    ]);
    expect(fn () => app(ProductionOrderService::class)->createFromAssignment($second, [
        'planned_quantity' => 10, 'raw_material_stock_location_id' => $this->location->id,
        'finished_goods_stock_location_id' => $this->location->id,
    ], $this->admin))->toThrow(ValidationException::class);
});

test('purchased products and inactive machines cannot enter execution', function () {
    $purchasedAssignmentId = DB::table('production_machine_assignments')->insertGetId([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'machine_id' => $this->machine->id, 'product_id' => $this->material->id,
        'production_date' => '2026-08-02', 'target_quantity' => 10, 'status' => 'confirmed',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => app(ProductionOrderService::class)->createFromAssignment(
        ProductionMachineAssignment::query()->findOrFail($purchasedAssignmentId),
        ['planned_quantity' => 10], $this->admin
    ))->toThrow(ValidationException::class);

    $order = createProductionOrderForTest($this);
    $this->machine->update(['status' => Machine::STATUS_MAINTENANCE]);
    expect(fn () => app(ProductionOrderService::class)->start($order, $this->admin))
        ->toThrow(ValidationException::class);
});

test('starting and saving progress have no stock impact and validate output', function () {
    stockProductionMaterialForTest($this);
    $order = createProductionOrderForTest($this);
    $before = StockMovement::withoutGlobalScopes()->count();
    $order = app(ProductionOrderService::class)->start($order, $this->admin);
    expect($order->status)->toBe(ProductionOrder::STATUS_IN_PROGRESS)
        ->and($order->materials->firstWhere('line_type', 'inventory')->actual_quantity)->toBe('18.000000000000')
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($before);

    expect(fn () => app(ProductionOrderService::class)->saveExecution($order, [], '-1', '0', '', $this->admin))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ProductionOrderService::class)->submit($order, $this->admin))
        ->toThrow(ValidationException::class);
});

test('planned start requires snapshot and sufficient raw inventory and records starter', function () {
    $missingSnapshot = createProductionOrderForTest($this);
    $missingSnapshot->snapshot()->delete();
    expect(fn () => app(ProductionOrderService::class)->start($missingSnapshot, $this->admin))
        ->toThrow(ValidationException::class);
    expect($missingSnapshot->refresh()->status)->toBe(ProductionOrder::STATUS_PLANNED)
        ->and($missingSnapshot->started_at)->toBeNull();

    $missingSnapshot->delete();
    $this->assignment->productionOrder()->delete();
    $order = createProductionOrderForTest($this);
    expect(fn () => app(ProductionOrderService::class)->start($order, $this->admin))
        ->toThrow(ValidationException::class);

    stockProductionMaterialForTest($this);
    $order = app(ProductionOrderService::class)->start($order, $this->admin);

    expect($order->status)->toBe(ProductionOrder::STATUS_IN_PROGRESS)
        ->and($order->started_by)->toBe($this->admin->id)
        ->and($order->started_at)->not->toBeNull();
});

test('execution rejects impractical output and inventory usage above available stock', function () {
    stockProductionMaterialForTest($this, '20');
    $order = app(ProductionOrderService::class)->start(createProductionOrderForTest($this), $this->admin);
    $inventoryLine = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_INVENTORY);
    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => $line->line_type === ProductionOrderMaterial::TYPE_INVENTORY ? '21' : $line->actual_quantity,
        'actual_cost' => $line->actual_cost,
    ]])->all();

    expect(fn () => app(ProductionOrderService::class)->saveExecution(
        $order, $materials, '1400', '41', null, $this->admin
    ))->toThrow(ValidationException::class);

    $materials[$inventoryLine->id]['actual_quantity'] = '21';
    expect(fn () => app(ProductionOrderService::class)->saveExecution(
        $order, $materials, '1400', '40', null, $this->admin
    ))->toThrow(ValidationException::class);

    expect($order->refresh()->total_produced_quantity)->toBe('0.0000')
        ->and($inventoryLine->refresh()->actual_quantity)->toBe('18.000000000000');
});

test('production order UI exposes lifecycle actions only in their valid states', function () {
    stockProductionMaterialForTest($this);
    $order = createProductionOrderForTest($this);

    expect($order->snapshot)->not->toBeNull();
    $this->get(route('production.orders.show', $order))
        ->assertOk()
        ->assertSee('Start Production')
        ->assertSee('wire:click="startProduction"', escape: false);

    Volt::test('production.orders.show', ['order' => $order])
        ->assertSee('Production Progress')
        ->assertSee('Next Production Action')
        ->assertSee('Start Production')
        ->assertSeeHtml('wire:click="startProduction"')
        ->assertSeeHtml('wire:target="startProduction"')
        ->assertSeeHtml('border-cyan-600 bg-cyan-500')
        ->assertSeeHtml('text-white')
        ->assertSeeHtml('hover:border-cyan-700 hover:bg-cyan-600 active:bg-cyan-700')
        ->assertSeeHtml('focus:ring-cyan-500 focus:ring-offset-2')
        ->assertSeeHtml('dark:border-cyan-400 dark:bg-cyan-500 dark:text-slate-950')
        ->assertSeeHtml('dark:hover:border-cyan-300 dark:hover:bg-cyan-400 dark:active:bg-cyan-300')
        ->assertSeeHtml('disabled:cursor-not-allowed disabled:opacity-50')
        ->assertSee(__('production.orders.actions.starting_production'))
        ->assertSeeInOrder(['Next Production Action', 'Start Production', 'Cancel Order'])
        ->assertSee('Cancel Order')
        ->assertDontSee('Record Actual Consumption')
        ->assertDontSee('Print Production Order');

    $order = app(ProductionOrderService::class)->start($order, $this->admin);
    $inventoryLine = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_INVENTORY);
    $costLine = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_NON_INVENTORY_COST);

    Volt::test('production.orders.show', ['order' => $order])
        ->assertDontSee('Start Production')
        ->assertSee('Record Actual Consumption')
        ->assertSee('Complete Production')
        ->assertSee('Cancel Order');

    Volt::test('production.orders.execute', ['order' => $order])
        ->assertSee('Record Actual Consumption')
        ->assertSeeHtml('id="materials.'.$inventoryLine->id.'.actual_quantity"')
        ->assertDontSeeHtml('id="materials.'.$inventoryLine->id.'.actual_cost"')
        ->assertSeeHtml('id="materials.'.$costLine->id.'.actual_cost"')
        ->assertDontSeeHtml('id="materials.'.$costLine->id.'.actual_quantity"')
        ->assertSee('Accepted Products')
        ->assertSee('Rejected Products')
        ->assertSee('Complete Production');

    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => $line->line_type === ProductionOrderMaterial::TYPE_INVENTORY ? '18' : $line->actual_quantity,
        'actual_cost' => $line->actual_cost,
    ]])->all();
    $order = app(ProductionOrderService::class)->saveExecution($order, $materials, '1400', '40', null, $this->admin);
    $order = app(ProductionOrderService::class)->submit($order, $this->admin);
    $order = app(ProductionOrderService::class)->complete($order, $this->admin);
    Volt::test('production.orders.show', ['order' => $order])
        ->assertDontSee('Start Production')
        ->assertDontSee('Cancel Order')
        ->assertSee('View Costing')
        ->assertSee('View QC')
        ->assertSee('Print Production Order');
});

test('complete production Livewire action reports validation and completes after valid output', function () {
    stockProductionMaterialForTest($this);
    $order = app(ProductionOrderService::class)->start(createProductionOrderForTest($this), $this->admin);

    $component = Volt::test('production.orders.execute', ['order' => $order])
        ->assertSeeHtml('wire:click="completeProduction"')
        ->assertSeeHtml('wire:confirm="Post actual material consumption and accepted finished output? This cannot be edited afterward."')
        ->call('completeProduction')
        ->assertHasErrors(['output'])
        ->assertSee('Production could not be completed:')
        ->assertSee('Record accepted or rejected output before submission.');

    expect($order->refresh()->status)->toBe(ProductionOrder::STATUS_IN_PROGRESS)
        ->and($order->posted_at)->toBeNull();

    $component
        ->set('accepted', '1400')
        ->set('rejected', '40')
        ->call('completeProduction')
        ->assertHasNoErrors()
        ->assertRedirect(route('production.orders.show', $order));

    expect($order->refresh()->status)->toBe(ProductionOrder::STATUS_COMPLETED)
        ->and($order->posted_at)->not->toBeNull();
});

test('start production action uses the translated Kiswahili labels', function () {
    app()->setLocale('sw');
    stockProductionMaterialForTest($this);
    $order = createProductionOrderForTest($this);

    Volt::test('production.orders.show', ['order' => $order])
        ->assertSee('Anza Uzalishaji')
        ->assertSee('Inaandaa Uzalishaji...')
        ->assertSeeHtml('wire:click="startProduction"');
});

test('start production remains visible and reports validation failures on the planned order page', function () {
    $order = createProductionOrderForTest($this);
    $inventoryLine = $order->materials->firstWhere('line_type', ProductionOrderMaterial::TYPE_INVENTORY);

    Volt::test('production.orders.show', ['order' => $order])
        ->assertSee('Start Production')
        ->call('startProduction')
        ->assertHasErrors(["materials.{$inventoryLine->id}.actual_quantity"])
        ->assertSee('Start Production')
        ->assertSee('is short')
        ->assertSet('order.status', ProductionOrder::STATUS_PLANNED);
});

test('production lifecycle actions enforce execution and completion permissions', function () {
    stockProductionMaterialForTest($this);
    $order = createProductionOrderForTest($this);
    $viewer = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'status' => 'active', 'is_system_owner' => false,
    ]);
    $viewer->givePermissionTo(['production.view', 'production.view_orders']);

    expect(fn () => app(ProductionOrderService::class)->start($order, $viewer))
        ->toThrow(HttpException::class);

    $this->actingAs($viewer);
    Volt::test('production.orders.show', ['order' => $order])
        ->assertDontSee('Start Production')
        ->assertDontSee('Cancel Order');
});

test('cancelling an unposted order records reason and creates no movement', function () {
    $order = createProductionOrderForTest($this);
    $before = StockMovement::withoutGlobalScopes()->count();
    $order = app(ProductionOrderService::class)->cancel($order, 'Schedule changed', $this->admin);
    expect($order->status)->toBe(ProductionOrder::STATUS_CANCELLED)
        ->and($order->cancellation_reason)->toBe('Schedule changed')
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($before);

    $replacement = createProductionOrderForTest($this);
    expect($replacement->id)->toBe($order->id)
        ->and($replacement->status)->toBe(ProductionOrder::STATUS_PLANNED);
});

test('insufficient raw stock blocks completion and rolls back all posting', function () {
    stockProductionMaterialForTest($this, '18');
    $order = executeProductionOrderForTest($this, createProductionOrderForTest($this));
    StockMovement::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->material->id, 'stock_location_id' => $this->location->id,
        'source_location_id' => $this->location->id, 'movement_type' => 'adjustment_out',
        'quantity' => 18, 'quantity_in' => 0, 'quantity_out' => 18,
        'notes' => 'Stock used by another process', 'created_by' => $this->admin->id,
        'movement_date' => '2026-07-29',
    ]);
    $before = StockMovement::withoutGlobalScopes()->count();
    expect(fn () => app(ProductionOrderService::class)->complete($order, $this->admin))
        ->toThrow(ValidationException::class);
    expect($order->refresh()->status)->toBe(ProductionOrder::STATUS_AWAITING_COMPLETION)
        ->and($order->posted_at)->toBeNull()
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe($before);
});

test('completion atomically consumes actual material adds accepted output excludes rejected and is idempotent', function () {
    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->branch->id, 'product_id' => $this->material->id,
        'stock_location_id' => $this->location->id, 'quantity' => 100,
        'cost_price' => 10, 'selling_price' => 20, 'reason' => 'Production test',
        'notes' => '', 'movement_date' => '2026-07-29',
    ], $this->admin->id);
    $inventory = app(InventoryService::class);
    $rawBefore = $inventory->getProductStock($this->material->id, $this->location->id, $this->branch->id);
    $finishedBefore = $inventory->getProductStock($this->finished->id, $this->finishedLocation->id, $this->branch->id);
    $order = executeProductionOrderForTest($this, createProductionOrderForTest($this));
    $order = app(ProductionOrderService::class)->complete($order, $this->admin);
    $movementCount = StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->count();

    expect($order->status)->toBe(ProductionOrder::STATUS_COMPLETED)
        ->and($inventory->getProductStock($this->material->id, $this->location->id, $this->branch->id))->toBe($rawBefore - 18)
        ->and($inventory->getProductStock($this->finished->id, $this->finishedLocation->id, $this->branch->id))->toBe($finishedBefore + 1400)
        ->and($movementCount)->toBe(2)
        ->and(StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_output')->value('quantity_in'))->toBe('1400.0000');

    app(ProductionOrderService::class)->complete($order->refresh(), $this->admin);
    expect(StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->count())->toBe($movementCount);
});

test('authorized execution can record actuals and complete an in progress order atomically', function () {
    stockProductionMaterialForTest($this);
    $order = app(ProductionOrderService::class)->start(createProductionOrderForTest($this), $this->admin);
    $materials = $order->materials->mapWithKeys(fn ($line) => [$line->id => [
        'actual_quantity' => $line->line_type === ProductionOrderMaterial::TYPE_INVENTORY ? '18' : $line->actual_quantity,
        'actual_cost' => $line->line_type === ProductionOrderMaterial::TYPE_NON_INVENTORY_COST ? '28000' : $line->actual_cost,
    ]])->all();

    $order = app(ProductionOrderService::class)->completeExecution(
        $order, $materials, '1400', '40', 'Single-step authorized completion', $this->admin
    );

    expect($order->status)->toBe(ProductionOrder::STATUS_COMPLETED)
        ->and($order->completed_by)->toBe($this->admin->id)
        ->and($order->completed_at)->not->toBeNull()
        ->and($order->posted_at)->not->toBeNull()
        ->and($order->materials()->where('line_type', ProductionOrderMaterial::TYPE_NON_INVENTORY_COST)->firstOrFail()->actual_cost)->toBe('28000.0000')
        ->and(StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_consumption')->exists())->toBeTrue()
        ->and(StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->where('movement_type', 'production_output')->exists())->toBeTrue();
});

test('cross company assignment and stock location are rejected', function () {
    $other = Company::query()->create(['company_name' => 'Other Production', 'business_type' => 'Factory', 'phone' => '+255700822222', 'whatsapp_number' => '+255700822222', 'manufacturing_enabled' => true]);
    $otherLocation = StockLocation::withoutGlobalScopes()->create([
        'company_id' => $other->id, 'branch_id' => null, 'name' => 'Other Stock', 'code' => 'OTHER-STOCK',
        'type' => 'other', 'status' => 'active', 'is_active' => true, 'can_issue_stock' => true, 'can_receive_stock' => true,
    ]);
    expect(fn () => createProductionOrderForTest($this, ['raw_material_stock_location_id' => $otherLocation->id]))
        ->toThrow(ValidationException::class);

    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Other Factory Branch',
        'code' => 'OTHER-FACTORY', 'status' => 'active', 'is_default' => false,
    ]);
    $wrongBranchLocation = StockLocation::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $otherBranch->id,
        'name' => 'Wrong Branch Stock', 'code' => 'WRONG-BRANCH-STOCK', 'type' => 'other',
        'status' => 'active', 'is_active' => true, 'can_issue_stock' => true, 'can_receive_stock' => true,
    ]);
    expect(fn () => createProductionOrderForTest($this, ['raw_material_stock_location_id' => $wrongBranchLocation->id]))
        ->toThrow(ValidationException::class);

    $otherAssignmentId = DB::table('production_machine_assignments')->insertGetId([
        'company_id' => $other->id, 'machine_id' => $this->machine->id, 'product_id' => $this->finished->id,
        'production_date' => '2026-08-01', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherAssignment = ProductionMachineAssignment::withoutGlobalScopes()->findOrFail($otherAssignmentId);
    expect(fn () => app(ProductionOrderService::class)->createFromAssignment($otherAssignment, [], $this->admin))
        ->toThrow(NotFoundHttpException::class);
});

test('completed order is read only and production movements reference it', function () {
    app(InventoryService::class)->directStockIn([
        'branch_id' => $this->branch->id, 'product_id' => $this->material->id, 'stock_location_id' => $this->location->id,
        'quantity' => 100, 'cost_price' => 10, 'selling_price' => 20, 'reason' => 'Test', 'notes' => '', 'movement_date' => '2026-07-29',
    ], $this->admin->id);
    $order = app(ProductionOrderService::class)->complete(executeProductionOrderForTest($this, createProductionOrderForTest($this)), $this->admin);
    expect(fn () => $order->update(['accepted_quantity' => 1]))->toThrow(LogicException::class)
        ->and(StockMovement::query()->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->count())->toBe(2);
});
