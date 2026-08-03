<?php

use App\Models\Company;
use App\Models\Machine;
use App\Models\ProductionOrder;
use App\Models\ProductionQualityInspection;
use App\Models\ProductionRecipe;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function setupWizard(string $suffix, bool $manufacturing = false)
{
    return Volt::test('setup.index')
        ->set('company_name', 'Setup Company '.$suffix)
        ->set('business_type', 'Hardware Store')
        ->set('phone', '0711000000')
        ->set('whatsapp_number', '0711000000')
        ->set('country', 'Tanzania')
        ->set('currency', 'TZS')
        ->set('timezone', 'Africa/Dar_es_Salaam')
        ->set('language', 'en')
        ->set('inventory_stock_mode', 'warehouse')
        ->set('manufacturing_enabled', $manufacturing)
        ->set('admin_name', 'Setup Owner '.$suffix)
        ->set('admin_phone', '0722000000')
        ->set('admin_email', 'setup-'.strtolower($suffix).'@example.test')
        ->set('admin_password', 'Password123!')
        ->set('admin_password_confirmation', 'Password123!')
        ->set('branch_name', 'Setup Main '.$suffix)
        ->set('branch_code', 'S'.strtoupper(substr($suffix, 0, 5)))
        ->set('branch_status', 'active')
        ->set('branch_is_default', true);
}

test('manufacturing defaults off and no keeps the original four-step flow', function () {
    $component = Volt::test('setup.index')
        ->assertSet('manufacturing_enabled', false)
        ->assertSee(__('setup.business_operations'))
        ->assertSee(__('setup.manufacturing_question'))
        ->assertDontSee(__('setup.production_setup'));

    $component->set('step', 3)->call('next')->assertSet('step', 5);
    $component->assertSee(__('setup.progress', ['current' => 4, 'total' => 4]));
});

test('yes inserts production setup and changing back to no removes it safely', function () {
    $component = Volt::test('setup.index')->set('manufacturing_enabled', true)
        ->assertSee(__('setup.production_setup'))
        ->assertSee(__('setup.progress', ['current' => 1, 'total' => 5]));

    $component->set('step', 3)->call('next')->assertSet('step', 4)
        ->set('manufacturing_enabled', false)->assertSet('step', 5)
        ->assertDontSee(__('setup.production_setup'));
});

test('ordinary hardware setup saves disabled flag and creates no production records', function () {
    $beforeCompanies = Company::count();
    $beforeMachines = Machine::count();
    $beforeMovements = StockMovement::count();

    setupWizard('NoMfg')->call('complete')->assertRedirect(route('login'));

    $company = Company::query()->where('company_name', 'Setup Company NoMfg')->firstOrFail();
    expect($company->manufacturing_enabled)->toBeFalse()
        ->and(Company::count())->toBe($beforeCompanies + 1)
        ->and(Machine::count())->toBe($beforeMachines)
        ->and(StockLocation::query()->where('company_id', $company->id)->whereIn('code', ['RAW-MATERIALS', 'PRODUCTION-AREA', 'CURING-YARD', 'FINISHED-GOODS'])->exists())->toBeFalse()
        ->and(ProductionRecipe::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(ProductionOrder::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(ProductionQualityInspection::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(StockMovement::count())->toBe($beforeMovements);

    $user = User::query()->where('company_id', $company->id)->firstOrFail();
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee(__('production.title'));
});

test('manufacturing setup creates scoped locations and optional machine without stock', function () {
    $beforeMovements = StockMovement::count();
    setupWizard('Mfg', true)
        ->set('machine_section_open', true)
        ->set('machine_name', 'First Block Press')
        ->set('machine_code', 'FBP-1')
        ->set('machine_daily_capacity', '750.5')
        ->set('quality_control_preference', true)
        ->call('complete')
        ->assertRedirect(route('production.setup-checklist'));

    $company = Company::query()->where('company_name', 'Setup Company Mfg')->firstOrFail();
    $locations = StockLocation::query()->where('company_id', $company->id)->whereIn('code', ['RAW-MATERIALS', 'PRODUCTION-AREA', 'CURING-YARD', 'FINISHED-GOODS'])->get()->keyBy('code');
    $machine = Machine::query()->where('company_id', $company->id)->firstOrFail();

    expect($company->manufacturing_enabled)->toBeTrue()
        ->and($locations)->toHaveCount(4)
        ->and($locations['CURING-YARD']->is_sellable)->toBeFalse()
        ->and($locations['CURING-YARD']->can_sell)->toBeFalse()
        ->and($locations['FINISHED-GOODS']->is_sellable)->toBeTrue()
        ->and($machine->name)->toBe('First Block Press')
        ->and($machine->daily_capacity)->toBe('750.5000')
        ->and($machine->branch_id)->toBe($locations['CURING-YARD']->branch_id)
        ->and(StockMovement::count())->toBe($beforeMovements);

    $this->actingAs($machine->creator)->get(route('dashboard'))->assertOk()->assertSee(__('production.title'));
});

test('equivalent inventory location is reused instead of duplicated', function () {
    setupWizard('Reuse', true)
        ->set('finished_goods_store_name', 'Main Store')
        ->call('complete');

    $company = Company::query()->where('company_name', 'Setup Company Reuse')->firstOrFail();
    expect(StockLocation::query()->where('company_id', $company->id)->where('name', 'Main Store')->count())->toBe(1)
        ->and(StockLocation::query()->where('company_id', $company->id)->where('code', 'FINISHED-GOODS')->count())->toBe(1);
});

test('collapsed machine is skipped and shared locations require confirmation', function () {
    setupWizard('BlankMachine', true)->call('complete');
    $company = Company::query()->where('company_name', 'Setup Company BlankMachine')->firstOrFail();
    expect(Machine::query()->where('company_id', $company->id)->exists())->toBeFalse();

    $duplicate = setupWizard('Duplicate', true)
        ->set('production_area_name', 'Raw Materials Store')
        ->call('complete')
        ->assertHasErrors(['shared_location_confirmed']);
    expect(Company::query()->where('company_name', 'Setup Company Duplicate')->exists())->toBeFalse();

    $duplicate->set('shared_location_confirmed', true)
        ->call('complete')
        ->assertHasNoErrors()
        ->assertRedirect(route('production.setup-checklist'));
    expect(Company::query()->where('company_name', 'Setup Company Duplicate')->exists())->toBeTrue();
});

test('production branch machine and curing defaults are enforced server side', function () {
    setupWizard('InvalidBranch', true)
        ->set('production_branch', 'another-company-branch')
        ->call('complete')
        ->assertHasErrors(['production_branch']);
    expect(Company::query()->where('company_name', 'Setup Company InvalidBranch')->exists())->toBeFalse();

    setupWizard('PartialMachine', true)
        ->set('machine_section_open', true)
        ->set('machine_code', 'CODE-WITHOUT-NAME')
        ->call('complete')
        ->assertHasErrors(['machine_name']);

    setupWizard('InvalidCuring', true)
        ->set('default_sellable_after_days', 20)
        ->set('default_curing_days', 14)
        ->call('complete')
        ->assertHasErrors(['default_sellable_after_days']);
});

test('changing yes to no ignores malicious production payloads', function () {
    setupWizard('Changed', true)
        ->set('machine_name', 'Must Not Exist')
        ->set('manufacturing_enabled', false)
        ->call('complete');

    $company = Company::query()->where('company_name', 'Setup Company Changed')->firstOrFail();
    expect($company->manufacturing_enabled)->toBeFalse()
        ->and(Machine::query()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(StockLocation::query()->where('company_id', $company->id)->where('code', 'CURING-YARD')->exists())->toBeFalse();
});

test('setup transaction rolls back company settings locations and user on machine failure', function () {
    $before = ['companies' => Company::count(), 'settings' => Setting::count(), 'locations' => StockLocation::count(), 'users' => User::count()];
    Event::listen('eloquent.creating: '.Machine::class, fn () => throw new RuntimeException('Simulated machine failure'));
    try {
        expect(fn () => setupWizard('Rollback', true)->set('machine_section_open', true)->set('machine_name', 'Failing Machine')->call('complete'))
            ->toThrow(RuntimeException::class, 'Simulated machine failure');
    } finally {
        Event::forget('eloquent.creating: '.Machine::class);
    }

    expect(Company::count())->toBe($before['companies'])
        ->and(Setting::count())->toBe($before['settings'])
        ->and(StockLocation::count())->toBe($before['locations'])
        ->and(User::count())->toBe($before['users']);
});

test('existing company settings and default branch remain unchanged', function () {
    $existing = Company::query()->firstOrFail();
    $existingSetting = Setting::query()->where('company_id', $existing->id)->firstOrFail();
    $existingBranch = $existingSetting->defaultBranch;
    $snapshot = [$existing->manufacturing_enabled, $existingSetting->company_name, $existingSetting->default_branch_id, $existingBranch->is_default];

    setupWizard('Safety')->call('complete');

    expect([$existing->refresh()->manufacturing_enabled, $existingSetting->refresh()->company_name, $existingSetting->default_branch_id, $existingBranch->refresh()->is_default])->toBe($snapshot);
});

test('setup translations and responsive conditional markup are present', function () {
    expect(__('setup.business_operations', [], 'en'))->not->toBe('setup.business_operations')
        ->and(__('setup.business_operations', [], 'sw'))->not->toBe('setup.business_operations')
        ->and(__('setup.production_setup', [], 'en'))->not->toBe('setup.production_setup')
        ->and(__('setup.production_setup', [], 'sw'))->not->toBe('setup.production_setup');

    Volt::test('setup.index')->set('manufacturing_enabled', true)
        ->set('step', 4)
        ->assertSeeHtml('md:grid-cols-2')
        ->assertSee(__('setup.will_be_created'))
        ->assertSee(__('setup.use_existing_location'))
        ->assertSee(__('setup.earliest_selling_day'))
        ->assertSeeHtml('sm:p-6')
        ->assertSee(__('setup.quality_description'));
});

test('location cards use recommended defaults and support reuse and rename interactions', function () {
    $component = Volt::test('setup.index')
        ->set('manufacturing_enabled', true)
        ->set('step', 4)
        ->assertSet('raw_materials_store_name', 'Raw Materials Store')
        ->assertSet('production_area_name', 'Production Area')
        ->assertSet('curing_yard_name', 'Curing Yard')
        ->assertSet('finished_goods_store_name', 'Finished Goods Store')
        ->call('selectProductionLocation', 'finished_goods', 'main_store')
        ->assertSet('finished_goods_store_name', 'Main Store')
        ->assertSet('location_sources.finished_goods', 'main_store')
        ->assertSee(__('setup.existing_location'))
        ->call('toggleLocationRename', 'raw_materials')
        ->assertSet('location_rename_open.raw_materials', true)
        ->assertSet('location_sources.raw_materials', 'custom')
        ->assertSee(__('setup.location_name'));

    $component->set('inventory_stock_mode', 'direct')
        ->assertSet('finished_goods_store_name', 'Finished Goods Store')
        ->assertSet('location_sources.finished_goods', 'recommended');
});

test('machine fields are ignored while the optional section is collapsed', function () {
    setupWizard('CollapsedMachine', true)
        ->set('machine_name', 'Hidden Machine')
        ->set('machine_code', 'HIDDEN')
        ->call('complete');

    $company = Company::query()->where('company_name', 'Setup Company CollapsedMachine')->firstOrFail();
    expect(Machine::query()->where('company_id', $company->id)->exists())->toBeFalse();
});
