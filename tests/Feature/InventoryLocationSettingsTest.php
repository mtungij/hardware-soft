<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Parts company', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Parts branch', 'code' => 'PARTS', 'status' => 'active']);
    $this->setting = Setting::create(['company_id' => $this->company->id, 'company_name' => 'Parts company', 'enable_warehouse' => false, 'inventory_mode' => 'single_location']);
    $this->user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
    Permission::findOrCreate('settings.manage', 'web');
    $this->user->givePermissionTo('settings.manage');
    $this->actingAs($this->user);
    $this->inventory = app(InventoryService::class);
});

function inventoryTestLocation($test, array $attributes = []): StockLocation
{
    return StockLocation::create($attributes + [
        'company_id' => $test->company->id, 'branch_id' => $test->branch->id,
        'code' => 'CUSTOM', 'name' => 'Custom warehouse', 'type' => 'warehouse',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
        'can_sell' => true, 'is_sellable' => true, 'is_dispensing_location' => false,
    ]);
}

test('reserved warehouse code on a dispensing location requires explicit resolution and mount is read only', function () {
    $conflict = inventoryTestLocation($this, ['code' => 'MAIN-STORE', 'type' => 'dispensing', 'name' => 'SPARE PARTS 02', 'is_dispensing_location' => true]);
    $before = $conflict->fresh()->getAttributes();
    Volt::test('settings.inventory')->set('inventory_mode', 'multi_location')->call('save')->assertHasErrors('default_stock_location_id');
    expect($conflict->fresh()->getAttributes())->toBe($before)
        ->and(StockLocation::count())->toBe(1)
        ->and($this->setting->fresh()->enable_warehouse)->toBeFalse()
        ->and(StockMovement::count())->toBe(0);
});

test('custom warehouse can be selected and repeated saves preserve all locations', function () {
    $conflict = inventoryTestLocation($this, ['code' => 'MAIN-STORE', 'type' => 'dispensing', 'is_dispensing_location' => true]);
    $warehouse = inventoryTestLocation($this);
    $before = StockLocation::orderBy('id')->get()->map->getAttributes()->all();
    $component = Volt::test('settings.inventory')->set('inventory_mode', 'multi_location')->set('default_stock_location_id', (string) $warehouse->id);
    $component->call('save')->assertHasNoErrors()->call('save')->assertHasNoErrors();
    expect($this->setting->fresh()->default_stock_location_id)->toBe($warehouse->id)
        ->and($this->inventory->getMainStoreLocation($this->branch->id)->id)->toBe($warehouse->id)
        ->and(StockLocation::orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(StockMovement::count())->toBe(0);
});

test('dispensing collision preserves warehouse and uses an existing custom counter', function () {
    $conflict = inventoryTestLocation($this, ['code' => 'DISPENSING']);
    expect(fn () => $this->inventory->getDispensingLocation($this->branch->id))->toThrow(ValidationException::class);
    $counter = inventoryTestLocation($this, ['code' => 'COUNTER', 'type' => 'dispensing', 'is_dispensing_location' => true]);
    expect($this->inventory->getDispensingLocation($this->branch->id)->id)->toBe($counter->id)
        ->and($conflict->fresh()->type)->toBe('warehouse');
});

test('inactive and soft deleted reserved locations are never reactivated or duplicated', function () {
    $location = inventoryTestLocation($this, ['code' => 'MAIN-STORE', 'status' => 'inactive', 'is_active' => false]);
    expect(fn () => $this->inventory->getMainStoreLocation($this->branch->id))->toThrow(ValidationException::class);
    $location->delete();
    expect(fn () => $this->inventory->getMainStoreLocation($this->branch->id))->toThrow(ValidationException::class);
    expect(StockLocation::withTrashed()->count())->toBe(1);
});

test('default creation is idempotent and branch isolated', function () {
    $first = $this->inventory->getMainStoreLocation($this->branch->id);
    $other = Branch::create(['company_id' => $this->company->id, 'name' => 'Other branch', 'code' => 'OTHER']);
    expect($this->inventory->getMainStoreLocation($this->branch->id)->id)->toBe($first->id);
    expect(fn () => $this->inventory->getMainStoreLocation($other->id, $first->id))->toThrow(ModelNotFoundException::class);
    $this->app['auth']->forgetGuards();
    $otherUser = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $other->id]);
    $this->actingAs($otherUser);
    $second = $this->inventory->getMainStoreLocation($other->id);
    expect($second->code)->toBe('MAIN-STORE')->and($second->id)->not->toBe($first->id);
    expect(fn () => $this->inventory->getMainStoreLocation($other->id, $first->id))->toThrow(ValidationException::class);
});

test('foreign company locations cannot be selected or resolved by another tenant', function () {
    $this->app['auth']->forgetGuards();
    $company = Company::create(['company_name' => 'Other company', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Other', 'code' => 'OTHER']);
    $location = StockLocation::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Other warehouse', 'code' => 'MAIN-STORE', 'type' => 'store', 'status' => 'active', 'is_active' => true, 'can_receive_stock' => true]);
    $this->actingAs($this->user);
    Volt::test('settings.inventory')->set('inventory_mode', 'multi_location')->set('default_stock_location_id', (string) $location->id)->call('save')->assertHasErrors('default_stock_location_id');
    expect($this->setting->fresh()->enable_warehouse)->toBeFalse();
    expect(fn () => $this->inventory->getMainStoreLocation($branch->id))->toThrow(ModelNotFoundException::class);
    $this->app['auth']->forgetGuards();
    $otherUser = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $this->actingAs($otherUser);
    expect($this->inventory->getMainStoreLocation($branch->id)->id)->toBe($location->id);
    expect(fn () => $this->inventory->getMainStoreLocation($this->branch->id))->toThrow(ModelNotFoundException::class);
});

test('settings failure rolls back newly created default locations', function () {
    $component = Volt::test('settings.inventory')->set('inventory_mode', 'multi_location');
    Setting::updating(function () {
        throw new RuntimeException('Simulated settings write failure');
    });
    expect(fn () => $component->call('save'))->toThrow(RuntimeException::class);
    expect(StockLocation::count())->toBe(0)
        ->and($this->setting->fresh()->enable_warehouse)->toBeFalse()
        ->and(StockMovement::count())->toBe(0);
});
