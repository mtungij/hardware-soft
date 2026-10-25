<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Receipt settings A', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->otherCompany = Company::create(['company_name' => 'Receipt settings B', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->otherBranch = Branch::create(['company_id' => $this->otherCompany->id, 'name' => 'Other', 'code' => 'OTHER']);
    $this->setting = Setting::create(['company_id' => $this->company->id, 'company_name' => $this->company->company_name]);
    $this->otherSetting = Setting::create(['company_id' => $this->otherCompany->id, 'company_name' => $this->otherCompany->company_name]);
    Permission::findOrCreate('settings.manage', 'web');
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active', 'is_system_owner' => true]);
    $this->admin->givePermissionTo('settings.manage');
    $this->otherAdmin = User::factory()->create(['company_id' => $this->otherCompany->id, 'branch_id' => $this->otherBranch->id, 'status' => 'active']);
    $this->otherAdmin->givePermissionTo('settings.manage');
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
});

test('administrators can change receipt preference without changing stock settings or locations', function () {
    $location = StockLocation::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => 'MAIN-STORE', 'name' => 'Existing counter', 'type' => 'dispensing']);
    $snapshot = $location->fresh()->getAttributes();
    $before = $this->setting->fresh()->getAttributes();
    Volt::test('settings.inventory')->assertSet('show_receipt_after_sale', true)
        ->set('show_receipt_after_sale', false)->call('saveReceiptSettings')->assertHasNoErrors();
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeFalse()
        ->and($this->otherSetting->fresh()->show_receipt_after_sale)->toBeTrue()
        ->and($location->fresh()->getAttributes())->toBe($snapshot)
        ->and(StockLocation::count())->toBe(1)->and(StockMovement::count())->toBe(0);
    $after = $this->setting->fresh()->getAttributes();
    unset($before['updated_at'], $before['show_receipt_after_sale'], $after['updated_at'], $after['show_receipt_after_sale']);
    expect($after)->toBe($before);
    Volt::test('settings.inventory')->assertSet('show_receipt_after_sale', false)
        ->set('show_receipt_after_sale', true)->call('saveReceiptSettings')->assertHasNoErrors();
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeTrue();
});

test('receipt settings and settings lookup stay in the authenticated company', function () {
    $this->actingAs($this->otherAdmin);
    Volt::test('settings.inventory')->set('show_receipt_after_sale', false)->call('saveReceiptSettings')->assertHasNoErrors();
    expect(InventorySettings::current()->show_receipt_after_sale)->toBeFalse()
        ->and($this->setting->fresh()->show_receipt_after_sale)->toBeTrue();
    $this->actingAs($this->admin);
    expect(InventorySettings::current()->id)->toBe($this->setting->id)
        ->and(InventorySettings::current()->show_receipt_after_sale)->toBeTrue();
    $component = Volt::test('settings.inventory');
    expect(fn () => $component->set('setting', $this->otherSetting))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('unauthorized users and guests cannot modify receipt settings', function () {
    $this->admin->revokePermissionTo('settings.manage');
    $this->actingAs($this->admin->fresh());
    $this->get(route('settings.inventory'))->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->get(route('settings.inventory'))->assertRedirect(route('login'));
    Volt::test('settings.inventory')->assertForbidden();
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeTrue();
});

test('settings save actions check authorization again after access is revoked', function () {
    $receipt = Volt::test('settings.inventory')->set('show_receipt_after_sale', false)->set('default_receipt_width', '58');
    $inventory = Volt::test('settings.inventory');
    $this->admin->revokePermissionTo('settings.manage');
    $this->actingAs($this->admin->fresh());
    $receipt->call('saveReceiptSettings')->assertForbidden();
    $inventory->call('save')->assertForbidden();
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeTrue()
        ->and($this->setting->fresh()->default_receipt_width)->toBe('80');
});

test('receipt preference validates boolean input', function () {
    Volt::test('settings.inventory')->set('show_receipt_after_sale', 'invalid')->call('saveReceiptSettings')->assertHasErrors('show_receipt_after_sale');
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeTrue();
});

test('new company settings default enabled at model and database level', function () {
    expect((new Setting)->default_receipt_width)->toBe('80')
        ->and($this->otherSetting->fresh()->default_receipt_width)->toBe('80')
        ->and((new Setting)->show_receipt_after_sale)->toBeTrue()
        ->and($this->otherSetting->fresh()->show_receipt_after_sale)->toBeTrue();
    $company = Company::create(['company_name' => 'New company', 'business_type' => 'hardware', 'phone' => '255700000003', 'whatsapp_number' => '255700000003']);
    $id = DB::table('settings')->insertGetId(['company_id' => $company->id, 'company_name' => $company->company_name, 'created_at' => now(), 'updated_at' => now()]);
    expect((bool) DB::table('settings')->where('id', $id)->value('show_receipt_after_sale'))->toBeTrue()
        ->and(DB::table('settings')->where('id', $id)->value('default_receipt_width'))->toBe('80');
});

test('receipt settings labels are translated in English and Swahili', function () {
    $this->withSession(['staff_locale' => 'en'])->get(route('settings.inventory'))->assertOk()
        ->assertSee('POS & Receipt Settings')->assertSee('Show Receipt After Sale')
        ->assertSee('Default Receipt Width')->assertSee('58mm — Small Thermal Printer')->assertSee('80mm — Standard Thermal Printer')
        ->assertSee('Automatically open the receipt preview after completing a sale.');
    $this->withSession(['staff_locale' => 'sw'])->get(route('settings.inventory'))->assertOk()
        ->assertSee('Onyesha Risiti Baada ya Mauzo')->assertSee('Upana Chaguo-msingi wa Risiti')
        ->assertSee('Fungua ukurasa wa risiti moja kwa moja baada ya kukamilisha mauzo.');
});

test('administrators without a company cannot fall back to another company settings', function () {
    $this->app['auth']->forgetGuards();
    $user = User::factory()->create(['company_id' => null, 'branch_id' => null, 'is_system_owner' => true, 'status' => 'active']);
    $user->givePermissionTo('settings.manage');
    $this->actingAs($user)->get(route('settings.inventory'))->assertForbidden();
    expect($this->setting->fresh()->show_receipt_after_sale)->toBeTrue()
        ->and($this->otherSetting->fresh()->show_receipt_after_sale)->toBeTrue();
});

test('administrators can save either receipt width repeatedly without affecting another company', function (string $width) {
    $before = $this->setting->fresh()->getAttributes();
    $component = Volt::test('settings.inventory')->assertSet('default_receipt_width', '80')
        ->set('default_receipt_width', $width)->call('saveReceiptSettings')->assertHasNoErrors()
        ->call('saveReceiptSettings')->assertHasNoErrors()->assertSet('default_receipt_width', $width);
    expect($this->setting->fresh()->default_receipt_width)->toBe($width)
        ->and($this->otherSetting->fresh()->default_receipt_width)->toBe('80');
    $after = $this->setting->fresh()->getAttributes();
    unset($before['updated_at'], $before['default_receipt_width'], $after['updated_at'], $after['default_receipt_width']);
    expect($after)->toBe($before)->and(StockMovement::count())->toBe(0);
    $this->actingAs($this->otherAdmin);
    Volt::test('settings.inventory')->assertSet('default_receipt_width', '80');
})->with(['58', '80']);

test('invalid receipt widths cannot be persisted', function ($width) {
    Volt::test('settings.inventory')->set('default_receipt_width', $width)->call('saveReceiptSettings')
        ->assertHasErrors('default_receipt_width');
    expect($this->setting->fresh()->default_receipt_width)->toBe('80');
})->with(['57', 'A4', '', null, [['58']]]);
