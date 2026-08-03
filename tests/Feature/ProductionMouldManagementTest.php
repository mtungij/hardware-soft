<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionMould;
use App\Models\ProductionMouldInstallation;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductionMouldService;
use App\Services\ProductionOrderService;
use App\Services\ProductionScheduleService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::query()->where('email', 'admin@buildmart.test')->firstOrFail();
    $this->company = Company::query()->findOrFail($this->admin->company_id);
    $this->company->update(['manufacturing_enabled' => true]);
    $this->actingAs($this->admin);
    ProductFamily::ensureDefaultsForCompany($this->company->id);
    $this->concreteFamily = ProductFamily::query()->where('code', 'concrete-blocks')->firstOrFail();
    $this->pavingFamily = ProductFamily::query()->where('code', 'paving-blocks')->firstOrFail();
    $this->branch = Branch::query()->whereKey($this->admin->branch_id)->firstOrFail();
    $this->machine = Machine::query()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'name' => 'Mould Press A', 'code' => 'MP-A', 'status' => Machine::STATUS_ACTIVE,
    ]);

    $template = Product::query()->firstOrFail();
    $this->block = $template->replicate()->forceFill([
        'name' => 'Mould Block', 'sku' => 'MOULD-BLOCK',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => $this->concreteFamily->id,
    ]);
    $this->block->save();
    $this->paver = $template->replicate()->forceFill([
        'name' => 'Mould Paver', 'sku' => 'MOULD-PAVER',
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => $this->pavingFamily->id,
    ]);
    $this->paver->save();
    $this->blockRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->block->id,
        'name' => 'Block Active Recipe', 'code' => 'BLOCK-ACTIVE', 'version' => '1',
        'output_quantity' => 1, 'output_unit_id' => $this->block->unit_id,
        'status' => ProductionRecipe::STATUS_ACTIVE, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
});

function createMould(object $test, string $code, ProductFamily $family, array $machines, array $overrides = []): ProductionMould
{
    $mould = ProductionMould::query()->create([
        'company_id' => $test->company->id, 'product_family_id' => $family->id,
        'code' => $code, 'name' => $code.' Mould', 'active' => true,
        'expected_output_per_cycle' => 8, 'expected_output_per_day' => 800,
        'created_by' => $test->admin->id, 'updated_by' => $test->admin->id,
        ...$overrides,
    ]);
    $mould->compatibleMachines()->syncWithPivotValues(
        collect($machines)->pluck('id')->all(),
        ['company_id' => $test->company->id],
    );

    return $mould;
}

function mouldAssignmentData(object $test, array $overrides = []): array
{
    return [
        'machine_id' => $test->machine->id, 'product_id' => $test->block->id,
        'production_recipe_id' => $test->blockRecipe->id, 'branch_id' => $test->branch->id,
        'production_date' => '2026-08-10', 'target_quantity' => 500,
        'status' => ProductionMachineAssignment::STATUS_PLANNED, 'notes' => null,
        ...$overrides,
    ];
}

test('mould CRUD stores family compatibility and expected outputs', function () {
    Volt::test('production.moulds.index')
        ->set('code', 'HB-150')
        ->set('name', 'Hollow Block 150 mm')
        ->set('product_family_id', (string) $this->concreteFamily->id)
        ->set('compatible_machine_ids', [(string) $this->machine->id])
        ->set('expected_output_per_cycle', '8')
        ->set('expected_output_per_day', '800')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Hollow Block 150 mm');

    $mould = ProductionMould::query()->where('code', 'HB-150')->firstOrFail();
    expect($mould->product_family_id)->toBe($this->concreteFamily->id)
        ->and($mould->expected_output_per_cycle)->toBe('8.000000000000')
        ->and($mould->expected_output_per_day)->toBe('800.000000000000')
        ->and($mould->compatibleMachines()->whereKey($this->machine->id)->exists())->toBeTrue();

    Volt::test('production.moulds.index')->call('editMould', $mould->id)->set('name', 'Updated Hollow Mould')->call('save')->assertHasNoErrors();
    expect($mould->refresh()->name)->toBe('Updated Hollow Mould');
});

test('install remove and replace preserve mould history and current installation', function () {
    $first = createMould($this, 'MOULD-FIRST', $this->concreteFamily, [$this->machine]);
    $second = createMould($this, 'MOULD-SECOND', $this->concreteFamily, [$this->machine]);
    $service = app(ProductionMouldService::class);
    $beforeMovements = StockMovement::query()->count();

    $initial = $service->install($this->machine, $first, $this->admin, 'Initial setup');
    expect($this->machine->refresh()->currentMouldInstallation?->production_mould_id)->toBe($first->id)
        ->and($this->machine->currentMouldInstallation?->installed_at)->not->toBeNull();

    $replacement = $service->replace($this->machine, $second, $this->admin, 'Change product run');
    expect($initial->refresh()->removal_reason)->toBe(ProductionMouldInstallation::REASON_REPLACED)
        ->and($initial->current_machine_id)->toBeNull()
        ->and($replacement->current_machine_id)->toBe($this->machine->id)
        ->and($this->machine->refresh()->currentMouldInstallation?->production_mould_id)->toBe($second->id);

    $service->remove($this->machine, $this->admin, 'End of run');
    expect($this->machine->refresh()->currentMouldInstallation)->toBeNull()
        ->and($this->machine->latestMouldInstallation?->id)->toBe($replacement->id)
        ->and($this->machine->mouldInstallations()->count())->toBe(2)
        ->and(StockMovement::query()->count())->toBe($beforeMovements);
});

test('an installed mould cannot drop compatibility with its current machine', function () {
    $otherMachine = Machine::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Mould Press B',
        'code' => 'MP-B',
        'status' => Machine::STATUS_ACTIVE,
    ]);
    $mould = createMould($this, 'LOCKED-COMPATIBILITY', $this->concreteFamily, [$this->machine, $otherMachine]);
    app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin);

    Volt::test('production.moulds.index')
        ->call('editMould', $mould->id)
        ->set('compatible_machine_ids', [(string) $otherMachine->id])
        ->call('save')
        ->assertHasErrors(['compatible_machine_ids']);

    expect($mould->compatibleMachines()->whereKey($this->machine->id)->exists())->toBeTrue();
});

test('maintenance removes a mould and blocks installation until completed', function () {
    $mould = createMould($this, 'MAINT-MOULD', $this->concreteFamily, [$this->machine]);
    $service = app(ProductionMouldService::class);
    $service->install($this->machine, $mould, $this->admin);
    $service->startMaintenance($mould, $this->admin, 'Bearing inspection');

    expect($mould->refresh()->under_maintenance)->toBeTrue()
        ->and($this->machine->refresh()->currentMouldInstallation)->toBeNull()
        ->and($mould->installations()->latest()->first()->removal_reason)->toBe(ProductionMouldInstallation::REASON_MAINTENANCE);
    expect(fn () => $service->install($this->machine, $mould, $this->admin))->toThrow(ValidationException::class);

    $service->completeMaintenance($mould, $this->admin);
    $service->install($this->machine, $mould->refresh(), $this->admin);
    expect($this->machine->refresh()->currentMouldInstallation?->production_mould_id)->toBe($mould->id);
});

test('schedule requires installed mould compatible product family and active recipe', function () {
    $service = app(ProductionScheduleService::class);
    expect(fn () => $service->save(mouldAssignmentData($this), $this->admin))->toThrow(ValidationException::class);

    $mould = createMould($this, 'BLOCK-MOULD', $this->concreteFamily, [$this->machine]);
    $installation = app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin);

    expect(fn () => $service->save(mouldAssignmentData($this, ['product_id' => $this->paver->id]), $this->admin))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->save(mouldAssignmentData($this, ['production_recipe_id' => 999999]), $this->admin))
        ->toThrow(ValidationException::class);

    $assignment = $service->save(mouldAssignmentData($this), $this->admin);
    expect($assignment->production_mould_id)->toBe($mould->id)
        ->and($assignment->production_mould_installation_id)->toBe($installation->id)
        ->and($assignment->production_recipe_id)->toBe($this->blockRecipe->id);
});

test('schedule UI exposes only products available from the installed mould family', function () {
    $mould = createMould($this, 'FILTER-MOULD', $this->concreteFamily, [$this->machine]);
    app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin);

    Volt::test('production.schedule.index')
        ->set('machine_id', (string) $this->machine->id)
        ->assertSee($mould->name)
        ->assertSee($this->block->name)
        ->set('product_id', (string) $this->block->id)
        ->assertSet('production_recipe_id', (string) $this->blockRecipe->id)
        ->assertSee($this->blockRecipe->name);
});

test('mould management and daily assignment resolve the exact same current installation', function () {
    $this->machine->update(['name' => 'Block Machine A']);
    $mould = createMould($this, 'PAVING-BLOCK-1', $this->pavingFamily, [$this->machine], [
        'name' => 'Paving Block Mould 1',
    ]);
    $installation = app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin);
    $installation->update(['installed_at' => Carbon::parse('2026-08-02 14:10:00')]);

    $activeRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->paver->id,
        'name' => 'Paving Active Recipe', 'code' => 'PAVING-ACTIVE-1', 'version' => '1',
        'output_quantity' => 1, 'output_unit_id' => $this->paver->unit_id,
        'status' => ProductionRecipe::STATUS_ACTIVE, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
    $draftRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->paver->id,
        'name' => 'Paving Draft Recipe', 'code' => 'PAVING-DRAFT-1', 'version' => '2',
        'output_quantity' => 1, 'output_unit_id' => $this->paver->unit_id,
        'status' => ProductionRecipe::STATUS_DRAFT, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);

    $mouldPage = Volt::test('production.moulds.index')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->assertSee('Block Machine A')
        ->assertSee('Paving Block Mould 1')
        ->assertSee('Paving Blocks')
        ->assertSee('02 Aug 2026 14:10')
        ->assertSeeHtml('data-testid="mould-current-installation" data-installation-id="'.$installation->id.'"');

    $assignmentPage = Volt::test('production.schedule.index')
        ->set('machine_id', (string) $this->machine->id)
        ->assertSee('Installed Mould')
        ->assertSee('Paving Block Mould 1')
        ->assertSee('Family')
        ->assertSee('Paving Blocks')
        ->assertSee('Installation Date')
        ->assertSee('02 Aug 2026 14:10')
        ->assertSeeHtml('data-testid="assignment-current-installation" data-installation-id="'.$installation->id.'"');

    preg_match('/<select data-testid="assignment-product-select".*?<\/select>/s', $assignmentPage->html(), $productSelect);
    expect($productSelect[0] ?? '')->toContain($this->paver->name)->not->toContain($this->block->name);

    $assignmentPage->set('product_id', (string) $this->paver->id)
        ->assertSet('production_recipe_id', (string) $activeRecipe->id);
    preg_match('/<select data-testid="assignment-recipe-select".*?<\/select>/s', $assignmentPage->html(), $recipeSelect);
    expect($recipeSelect[0] ?? '')->toContain($activeRecipe->name)->not->toContain($draftRecipe->name)
        ->and($this->machine->currentMouldInstallation()->firstOrFail()->id)->toBe($installation->id);
});

test('schedule disables product and recipe controls when no mould is installed', function () {
    $component = Volt::test('production.schedule.index')
        ->set('machine_id', (string) $this->machine->id)
        ->assertSee(__('production.moulds.none_installed'));

    preg_match('/<select data-testid="assignment-product-select".*?>/s', $component->html(), $productSelect);
    preg_match('/<select data-testid="assignment-recipe-select".*?>/s', $component->html(), $recipeSelect);
    expect($productSelect[0] ?? '')->toMatch('/\sdisabled(?:=|\s|>)/')
        ->and($recipeSelect[0] ?? '')->toMatch('/\sdisabled(?:=|\s|>)/');
});

test('replacing the scheduled mould blocks future production order creation', function () {
    $first = createMould($this, 'ORDER-FIRST', $this->concreteFamily, [$this->machine]);
    $second = createMould($this, 'ORDER-SECOND', $this->concreteFamily, [$this->machine]);
    $mouldService = app(ProductionMouldService::class);
    $mouldService->install($this->machine, $first, $this->admin);
    $assignment = app(ProductionScheduleService::class)->save(mouldAssignmentData($this), $this->admin);
    $mouldService->replace($this->machine, $second, $this->admin);

    expect(fn () => app(ProductionOrderService::class)->createFromAssignment($assignment->refresh(), [], $this->admin))
        ->toThrow(ValidationException::class);
});

test('mould access is permission and tenant scoped', function () {
    $viewer = User::factory()->create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'status' => 'active', 'is_system_owner' => false,
    ]);
    $viewer->givePermissionTo(['production.view', 'production.view_moulds']);
    $this->actingAs($viewer);
    Volt::test('production.moulds.index')->assertDontSee(__('production.moulds.create'))->call('save')->assertForbidden();

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Mould Co', 'business_type' => 'Factory',
        'phone' => '+255700955555', 'whatsapp_number' => '+255700955555', 'manufacturing_enabled' => true,
    ]);
    ProductFamily::ensureDefaultsForCompany($otherCompany->id);
    $otherFamily = ProductFamily::query()->withoutGlobalScopes()->where('company_id', $otherCompany->id)->firstOrFail();
    $otherMouldId = ProductionMould::query()->withoutGlobalScopes()->insertGetId([
        'company_id' => $otherCompany->id, 'product_family_id' => $otherFamily->id,
        'code' => 'OTHER-MOULD', 'name' => 'Secret Mould', 'active' => true,
        'under_maintenance' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin);
    expect(fn () => Volt::test('production.moulds.index')->call('editMould', $otherMouldId))
        ->toThrow(ModelNotFoundException::class);
});

test('mould workspace renders tenant scoped summary statistics', function () {
    $installed = createMould($this, 'SUMMARY-INSTALLED', $this->concreteFamily, [$this->machine]);
    createMould($this, 'SUMMARY-AVAILABLE', $this->concreteFamily, [$this->machine]);
    createMould($this, 'SUMMARY-MAINTENANCE', $this->concreteFamily, [$this->machine], ['under_maintenance' => true]);
    app(ProductionMouldService::class)->install($this->machine, $installed, $this->admin);

    $otherCompany = Company::query()->create([
        'company_name' => 'Hidden Summary Co', 'business_type' => 'Factory',
        'phone' => '+255700966666', 'whatsapp_number' => '+255700966666', 'manufacturing_enabled' => true,
    ]);
    ProductFamily::ensureDefaultsForCompany($otherCompany->id);
    $otherFamily = ProductFamily::query()->withoutGlobalScopes()->where('company_id', $otherCompany->id)->firstOrFail();
    ProductionMould::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id, 'product_family_id' => $otherFamily->id,
        'code' => 'HIDDEN-SUMMARY', 'name' => 'Hidden Summary Mould', 'active' => true,
    ]);

    $html = Volt::test('production.moulds.index')->html();
    expect($html)
        ->toMatch('/data-testid="mould-stat-total".*?<p[^>]*>3<\/p>/s')
        ->toMatch('/data-testid="mould-stat-active".*?<p[^>]*>2<\/p>/s')
        ->toMatch('/data-testid="mould-stat-installed".*?<p[^>]*>1<\/p>/s')
        ->toMatch('/data-testid="mould-stat-available".*?<p[^>]*>1<\/p>/s')
        ->toMatch('/data-testid="mould-stat-maintenance".*?<p[^>]*>1<\/p>/s')
        ->not->toContain('Hidden Summary Mould');
});

test('mould create and edit workspace renders grouped accessible fields', function () {
    $mould = createMould($this, 'FORM-MOULD', $this->concreteFamily, [$this->machine]);

    Volt::test('production.moulds.index')
        ->assertSee(__('production.moulds.create_new'))
        ->assertSee(__('production.moulds.identification'))
        ->assertSee(__('production.moulds.production_capacity'))
        ->assertSee(__('production.moulds.compatibility'))
        ->assertSeeHtml('wire:model="code"')
        ->assertSeeHtml('wire:model="name"')
        ->assertSeeHtml('wire:model="product_family_id"')
        ->assertSeeHtml('wire:model="compatible_machine_ids"')
        ->assertSeeHtml('wire:model="expected_output_per_cycle"')
        ->assertSeeHtml('wire:model="expected_output_per_day"')
        ->call('editMould', $mould->id)
        ->assertSee(__('production.moulds.edit'))
        ->assertSee(__('production.moulds.save_changes'))
        ->assertSee(__('production.moulds.cancel_editing'));
});

test('catalog cards and installation panel show mould capability and current state', function () {
    $mould = createMould($this, 'CARD-MOULD', $this->concreteFamily, [$this->machine], [
        'expected_output_per_cycle' => 24,
        'expected_output_per_day' => 1440,
    ]);
    app(ProductionMouldService::class)->install($this->machine, $mould, $this->admin, 'Ready for run');

    Volt::test('production.moulds.index')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->assertSee($mould->name)
        ->assertSee($mould->code)
        ->assertSee($this->concreteFamily->name)
        ->assertSee(__('production.moulds.current_installation'))
        ->assertSee(__('production.moulds.installed_since'))
        ->assertSee(__('production.moulds.production_family'))
        ->assertSeeHtml('data-testid="mould-card"')
        ->assertSeeHtml('wire:click="replaceSelected"')
        ->assertSeeHtml('wire:click="removeInstalled('.$this->machine->id.')"');
});

test('installation workspace renders no mould and unchanged install action wiring', function () {
    createMould($this, 'READY-TO-INSTALL', $this->concreteFamily, [$this->machine]);

    Volt::test('production.moulds.index')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->assertSee(__('production.moulds.none_installed'))
        ->assertSee(__('production.moulds.select_compatible_mould'))
        ->assertSeeHtml('wire:click="installSelected"')
        ->assertSeeHtml('wire:confirm=');
});

test('maintenance workspace and timeline present existing maintenance history', function () {
    $mould = createMould($this, 'UI-MAINTENANCE', $this->concreteFamily, [$this->machine]);
    $service = app(ProductionMouldService::class);
    $service->install($this->machine, $mould, $this->admin, 'Initial installation');
    $service->startMaintenance($mould, $this->admin, 'Damaged cavity');

    Volt::test('production.moulds.index')
        ->assertSee(__('production.moulds.under_maintenance'))
        ->assertSee(__('production.moulds.maintenance_reason'))
        ->assertSee('Damaged cavity')
        ->assertSee(__('production.moulds.events.installed'))
        ->assertSee(__('production.moulds.events.maintenance_started'))
        ->assertSeeHtml('wire:click="completeMaintenance('.$mould->id.')"');
});

test('catalog filters remain tenant scoped and can be reset', function () {
    $blockMould = createMould($this, 'VISIBLE-BLOCK', $this->concreteFamily, [$this->machine]);
    $pavingMould = createMould($this, 'FILTERED-PAVER', $this->pavingFamily, [$this->machine]);

    Volt::test('production.moulds.index')
        ->set('familyFilter', (string) $this->concreteFamily->id)
        ->assertSee($blockMould->name)
        ->assertDontSee($pavingMould->name)
        ->set('search', 'no-match')
        ->assertSee(__('production.moulds.empty'))
        ->call('resetFilters')
        ->assertSet('familyFilter', '')
        ->assertSet('search', '')
        ->assertSee($pavingMould->name);
});

test('mould workspace has explicit theme responsive and accessible action classes', function () {
    $mould = createMould($this, 'THEME-MOULD', $this->concreteFamily, [$this->machine]);
    $html = Volt::test('production.moulds.index')->html();

    expect($html)
        ->toContain('bg-white')
        ->toContain('text-slate-900')
        ->toContain('dark:bg-slate-900')
        ->toContain('dark:text-white')
        ->toContain('focus:ring-2')
        ->toContain('md:grid-cols-2')
        ->toContain('xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]')
        ->toContain('wire:click="editMould('.$mould->id.')"')
        ->toContain('wire:click="startMaintenance('.$mould->id.')"')
        ->not->toContain('<table');
});

test('replacement eligibility reacts immediately to the selected mould', function () {
    $current = createMould($this, 'ELIGIBILITY-CURRENT', $this->concreteFamily, [$this->machine]);
    $valid = createMould($this, 'ELIGIBILITY-VALID', $this->pavingFamily, [$this->machine]);
    $inactive = createMould($this, 'ELIGIBILITY-INACTIVE', $this->pavingFamily, [$this->machine], ['active' => false]);
    $maintenance = createMould($this, 'ELIGIBILITY-MAINTENANCE', $this->pavingFamily, [$this->machine], ['under_maintenance' => true]);
    $otherMachine = Machine::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'name' => 'Incompatible Press',
        'code' => 'INCOMPATIBLE-PRESS',
        'status' => Machine::STATUS_ACTIVE,
    ]);
    $incompatible = createMould($this, 'ELIGIBILITY-INCOMPATIBLE', $this->pavingFamily, [$otherMachine]);
    app(ProductionMouldService::class)->install($this->machine, $current, $this->admin);

    $component = Volt::test('production.moulds.index')
        ->assertSeeHtml('wire:model.live="installation_machine_id"')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->assertSeeHtml('wire:model.live="installation_mould_id"')
        ->assertSet('installation_mould_id', '')
        ->assertSeeHtml('data-replace-eligible="false"')
        ->set('installation_mould_id', (string) $valid->id)
        ->assertSet('installation_mould_id', (string) $valid->id)
        ->assertSeeHtml('data-replace-eligible="true"');

    expect($component->html())->toMatch('/<button data-testid="replace-mould-button"(?![^>]*\sdisabled(?:=|\s|>))[^>]*>/');

    $component->set('installation_mould_id', (string) $current->id)->assertSeeHtml('data-replace-eligible="false"');
    $component->set('installation_mould_id', (string) $inactive->id)->assertSeeHtml('data-replace-eligible="false"');
    $component->set('installation_mould_id', (string) $maintenance->id)->assertSeeHtml('data-replace-eligible="false"');
    $component->set('installation_mould_id', (string) $incompatible->id)->assertSeeHtml('data-replace-eligible="false"');
});

test('successful Livewire replacement refreshes installation and immutable history', function () {
    $current = createMould($this, 'LIVEWIRE-CURRENT', $this->concreteFamily, [$this->machine]);
    $replacement = createMould($this, 'LIVEWIRE-REPLACEMENT', $this->pavingFamily, [$this->machine]);
    $initial = app(ProductionMouldService::class)->install($this->machine, $current, $this->admin, 'Initial mould');

    Volt::test('production.moulds.index')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->set('installation_mould_id', (string) $replacement->id)
        ->set('installation_notes', 'Switch product family')
        ->assertSeeHtml('data-replace-eligible="true"')
        ->call('replaceSelected')
        ->assertHasNoErrors()
        ->assertDispatched('mould-installation-updated')
        ->assertSet('installation_mould_id', '')
        ->assertSet('installation_notes', '')
        ->assertSee(__('production.moulds.replaced'))
        ->assertSee($replacement->name)
        ->assertSee(__('production.moulds.events.replaced'));

    expect($initial->refresh()->current_machine_id)->toBeNull()
        ->and($initial->removal_reason)->toBe(ProductionMouldInstallation::REASON_REPLACED)
        ->and($initial->notes)->toBe('Switch product family')
        ->and($this->machine->refresh()->currentMouldInstallation?->production_mould_id)->toBe($replacement->id)
        ->and(ProductionMouldInstallation::query()->count())->toBe(2);
});

test('cross company mould replacement is rejected by the Livewire action', function () {
    $current = createMould($this, 'TENANT-CURRENT', $this->concreteFamily, [$this->machine]);
    app(ProductionMouldService::class)->install($this->machine, $current, $this->admin);

    $otherCompany = Company::query()->create([
        'company_name' => 'Other Replacement Co', 'business_type' => 'Factory',
        'phone' => '+255700977777', 'whatsapp_number' => '+255700977777', 'manufacturing_enabled' => true,
    ]);
    ProductFamily::ensureDefaultsForCompany($otherCompany->id);
    $otherFamily = ProductFamily::query()->withoutGlobalScopes()->where('company_id', $otherCompany->id)->firstOrFail();
    $otherMould = ProductionMould::query()->withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id, 'product_family_id' => $otherFamily->id,
        'code' => 'OTHER-REPLACEMENT', 'name' => 'Other Company Replacement', 'active' => true,
    ]);

    expect(fn () => Volt::test('production.moulds.index')
        ->set('installation_machine_id', (string) $this->machine->id)
        ->set('installation_mould_id', (string) $otherMould->id)
        ->call('replaceSelected'))
        ->toThrow(ModelNotFoundException::class);

    expect($this->machine->refresh()->currentMouldInstallation?->production_mould_id)->toBe($current->id);
});

test('mould replacement invalidates and allows reassignment to the current mould product and recipe', function () {
    $this->paver->update([
        'name' => 'Paving Block 60mm',
        'branch_id' => null,
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => $this->pavingFamily->id,
        'status' => 'active',
        'buying_price' => 0,
    ]);
    $blockMould = createMould($this, 'ASSIGNMENT-BLOCK-MOULD', $this->concreteFamily, [$this->machine]);
    $pavingMould = createMould($this, 'ASSIGNMENT-PAVING-MOULD', $this->pavingFamily, [$this->machine]);
    $pavingRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->paver->id,
        'name' => 'Paving Block 60mm Recipe', 'code' => 'PAVER-60-ACTIVE', 'version' => '1',
        'output_quantity' => 1, 'output_unit_id' => $this->paver->unit_id,
        'status' => ProductionRecipe::STATUS_ACTIVE, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
    $draftRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->paver->id,
        'name' => 'Draft Paving Recipe', 'code' => 'PAVER-60-DRAFT', 'version' => '2',
        'output_quantity' => 1, 'output_unit_id' => $this->paver->unit_id,
        'status' => ProductionRecipe::STATUS_DRAFT, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
    $mouldService = app(ProductionMouldService::class);
    $mouldService->install($this->machine, $blockMould, $this->admin);
    $assignment = app(ProductionScheduleService::class)->save(mouldAssignmentData($this), $this->admin);
    $mouldService->replace($this->machine, $pavingMould, $this->admin, 'Switch to paving run');

    expect(StockMovement::query()->where('product_id', $this->paver->id)->count())->toBe(0);

    $component = Volt::test('production.schedule.index')
        ->set('selectedDate', $assignment->production_date->toDateString())
        ->assertSee($pavingMould->name)
        ->assertSee(__('production.schedule.requires_reassignment'))
        ->assertSee(__('production.schedule.assigned_mould', ['mould' => $blockMould->name]))
        ->assertDontSee('Create Production Order')
        ->call('editAssignment', $assignment->id)
        ->assertSet('machine_id', (string) $this->machine->id)
        ->assertSet('product_id', '')
        ->assertSet('production_recipe_id', '')
        ->assertSet('availableProducts.0.id', $this->paver->id)
        ->assertSet('availableProducts.0.name', 'Paving Block 60mm')
        ->assertSet('availableRecipes', [])
        ->assertSee($pavingMould->name);

    preg_match('/<select data-testid="assignment-product-select".*?<\/select>/s', $component->html(), $productSelect);
    expect($productSelect[0] ?? '')->toContain('Paving Block 60mm')->not->toContain($this->block->name);

    $component->set('product_id', (string) $this->paver->id)
        ->assertSet('production_recipe_id', (string) $pavingRecipe->id)
        ->assertSet('availableRecipes.0.id', $pavingRecipe->id);
    preg_match('/<select data-testid="assignment-recipe-select".*?<\/select>/s', $component->html(), $recipeSelect);
    expect($recipeSelect[0] ?? '')->toContain($pavingRecipe->name)->not->toContain($draftRecipe->name);
    preg_match('/<select data-testid="assignment-recipe-select".*?>/s', $component->html(), $recipeSelectTag);
    expect($recipeSelectTag[0] ?? '')->not->toMatch('/\sdisabled(?:=|\s|>)/');

    $component->call('save')->assertHasNoErrors();
    expect($assignment->refresh()->production_mould_id)->toBe($pavingMould->id)
        ->and($assignment->production_mould_installation_id)->toBe($this->machine->refresh()->currentMouldInstallation?->id)
        ->and($assignment->product_id)->toBe($this->paver->id)
        ->and($assignment->production_recipe_id)->toBe($pavingRecipe->id);
});

test('completed order assignment stays immutable while a new current-mould assignment becomes order eligible', function () {
    $this->paver->update([
        'name' => 'Paving Block 60mm',
        'branch_id' => null,
        'inventory_source' => Product::INVENTORY_SOURCE_MANUFACTURED,
        'product_family_id' => $this->pavingFamily->id,
        'status' => 'active',
    ]);
    $pavingRecipe = ProductionRecipe::query()->create([
        'company_id' => $this->company->id, 'product_id' => $this->paver->id,
        'name' => 'Paving Block 60mm Active Recipe', 'code' => 'PAVER-HISTORY-ACTIVE', 'version' => '1',
        'output_quantity' => 1, 'output_unit_id' => $this->paver->unit_id,
        'status' => ProductionRecipe::STATUS_ACTIVE, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
    ]);
    $blockMould = createMould($this, 'HISTORY-BLOCK-MOULD', $this->concreteFamily, [$this->machine]);
    $pavingMould = createMould($this, 'HISTORY-PAVING-MOULD', $this->pavingFamily, [$this->machine]);
    $mouldService = app(ProductionMouldService::class);
    $mouldService->install($this->machine, $blockMould, $this->admin);
    $historicalAssignment = app(ProductionScheduleService::class)->save(mouldAssignmentData($this), $this->admin);
    $location = StockLocation::query()->where('branch_id', $this->branch->id)->firstOrFail();
    $completedOrder = ProductionOrder::query()->create([
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'raw_material_stock_location_id' => $location->id,
        'finished_goods_stock_location_id' => $location->id,
        'production_output_stock_location_id' => $location->id,
        'final_finished_goods_stock_location_id' => $location->id,
        'production_machine_assignment_id' => $historicalAssignment->id,
        'machine_id' => $this->machine->id,
        'product_id' => $this->block->id,
        'production_recipe_id' => $this->blockRecipe->id,
        'order_number' => 'PRD-HISTORICAL-0001',
        'production_date' => $historicalAssignment->production_date,
        'planned_quantity' => 500,
        'status' => ProductionOrder::STATUS_COMPLETED,
        'completed_at' => now(),
        'posted_at' => now(),
        'posting_reference' => 'HISTORICAL-POST-0001',
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
        'completed_by' => $this->admin->id,
    ]);
    $orderHistory = $completedOrder->only([
        'production_machine_assignment_id', 'machine_id', 'product_id', 'production_recipe_id',
        'order_number', 'status', 'posting_reference',
    ]);
    $postedAt = $completedOrder->posted_at->toDateTimeString();
    $mouldService->replace($this->machine, $pavingMould, $this->admin, 'New paving run');

    $component = Volt::test('production.schedule.index')
        ->set('selectedDate', $historicalAssignment->production_date->toDateString())
        ->assertSee('Historical assignment — linked to completed Production Order PRD-HISTORICAL-0001. Create a new assignment.')
        ->assertSee('Create New Assignment From This')
        ->assertDontSee('Create Production Order');
    expect($component->html())
        ->not->toContain('wire:click="editAssignment('.$historicalAssignment->id.')"')
        ->not->toContain('wire:click="setAssignmentStatus('.$historicalAssignment->id.',');

    $component->call('editAssignment', $historicalAssignment->id)
        ->assertHasErrors(['status'])
        ->assertSet('editingId', null);
    expect(fn () => $historicalAssignment->update(['notes' => 'Forbidden historical edit']))
        ->toThrow(LogicException::class);
    expect(fn () => app(ProductionOrderService::class)->createFromAssignment($historicalAssignment, [
        'planned_quantity' => 500,
        'raw_material_stock_location_id' => $location->id,
        'finished_goods_stock_location_id' => $location->id,
    ], $this->admin))->toThrow(ValidationException::class);

    $component->call('createNewAssignmentFrom', $historicalAssignment->id)
        ->assertSet('editingId', null)
        ->assertSet('machine_id', (string) $this->machine->id)
        ->assertSet('branch_id', (string) $this->branch->id)
        ->assertSet('product_id', '')
        ->assertSet('production_recipe_id', '')
        ->assertSet('availableProducts.0.id', $this->paver->id)
        ->set('product_id', (string) $this->paver->id)
        ->assertSet('production_recipe_id', (string) $pavingRecipe->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Create Production Order');

    $newAssignment = ProductionMachineAssignment::query()->whereKeyNot($historicalAssignment->id)->latest('id')->firstOrFail();
    expect($newAssignment->id)->not->toBe($historicalAssignment->id)
        ->and($newAssignment->product_id)->toBe($this->paver->id)
        ->and($newAssignment->production_mould_id)->toBe($pavingMould->id)
        ->and($newAssignment->production_recipe_id)->toBe($pavingRecipe->id)
        ->and($completedOrder->fresh()->only(array_keys($orderHistory)))->toBe($orderHistory)
        ->and($completedOrder->fresh()->posted_at->toDateTimeString())->toBe($postedAt)
        ->and($completedOrder->fresh()->production_machine_assignment_id)->toBe($historicalAssignment->id);

    $orderCreate = Volt::test('production.orders.create');
    preg_match('/<select data-testid="production-order-assignment-select".*?<\/select>/s', $orderCreate->html(), $assignmentSelect);
    expect($assignmentSelect[0] ?? '')
        ->toContain('value="'.$newAssignment->id.'"')
        ->not->toContain('value="'.$historicalAssignment->id.'"');
});
