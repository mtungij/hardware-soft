<?php

use App\Http\Controllers\CompletePosReceiptController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\RolePermissionCatalog;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Receipt company', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->otherCompany = Company::create(['company_name' => 'Other receipt company', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->otherBranch = Branch::create(['company_id' => $this->company->id, 'name' => 'Other', 'code' => 'OTHER']);
    $this->foreignBranch = Branch::create(['company_id' => $this->otherCompany->id, 'name' => 'Foreign', 'code' => 'FOREIGN']);
    Setting::create(['company_id' => $this->company->id, 'company_name' => 'Receipt company']);
    foreach (['sales.view', 'print receipt', 'roles.manage', 'sales.cancel', 'sales.discount', 'sales.edit', 'sales.refund'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->role = Role::create(['company_id' => $this->company->id, 'name' => 'Receipt Viewer', 'guard_name' => 'web', 'sales_scope' => 'own']);
    $this->role->syncPermissions(['sales.view']);
    $this->reader = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->reader->assignRole($this->role);
    $this->colleague = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->colleague->assignRole($this->role);
    $this->sale = receiptAuthorizationSale($this->branch, $this->reader);
    $this->colleagueSale = receiptAuthorizationSale($this->branch, $this->colleague);
    $this->otherBranchSale = receiptAuthorizationSale($this->otherBranch, $this->colleague);
    $foreignUser = User::factory()->create(['company_id' => $this->otherCompany->id, 'branch_id' => $this->foreignBranch->id]);
    $foreignUser->givePermissionTo('sales.view');
    $this->foreignUser = $foreignUser;
    $this->foreignSale = receiptAuthorizationSale($this->foreignBranch, $foreignUser);
    $this->actingAs($this->reader)->withSession(['staff_locale' => 'en']);
});

function receiptAuthorizationSale(Branch $branch, User $user): Sale
{
    $location = StockLocation::query()->firstOrCreate(['branch_id' => $branch->id, 'code' => 'RECEIPT'], [
        'company_id' => $branch->company_id, 'name' => 'Counter', 'type' => 'dispensing', 'status' => 'active',
    ]);

    return Sale::create([
        'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'stock_location_id' => $location->id,
        'sale_number' => 'RECEIPT-'.str()->uuid(), 'sold_by' => $user->id, 'created_by' => $user->id,
        'sale_date' => today(), 'sale_type' => 'retail', 'subtotal' => 100, 'discount_amount' => 0,
        'tax_amount' => 0, 'total_amount' => 100, 'paid_amount' => 100, 'balance_amount' => 0,
        'change_amount' => 0, 'payment_status' => 'paid', 'status' => 'completed',
    ]);
}

test('sale viewers can print both receipt widths without printing or sale mutation permissions', function (bool $automaticReceipt) {
    Setting::query()->where('company_id', $this->company->id)->firstOrFail()->update(['show_receipt_after_sale' => $automaticReceipt]);
    foreach (['print receipt', 'sales.cancel', 'sales.discount', 'sales.edit', 'sales.refund'] as $permission) {
        expect($this->reader->can($permission))->toBeFalse();
    }
    $this->get(route('sales.cancel', $this->sale))->assertForbidden();
    $this->get(route('sales.show', $this->sale))->assertOk()->assertSee(route('sales.receipt', $this->sale), false);
    $this->get(route('sales.index', ['view' => 'invoices']))->assertOk()->assertSee(route('sales.receipt', $this->sale), false);
    $snapshot = $this->sale->fresh()->getAttributes();
    foreach ([58, 80] as $paper) {
        $this->get(route('sales.receipt', ['sale' => $this->sale, 'paper' => $paper]))->assertOk()
            ->assertSee($this->sale->sale_number)->assertSee('onclick="printCustomerReceipt()"', false)
            ->assertDontSee('data-start-new-sale', false);
    }
    $this->get(route('sales.receipt.complete', $this->sale))->assertRedirect(route('sales.show', $this->sale));
    expect($this->sale->fresh()->getAttributes())->toBe($snapshot);
})->with([true, false]);

test('guests cannot access receipt routes or mount receipt components directly', function () {
    $this->app['auth']->forgetGuards();
    foreach (['sales.receipt', 'sales.receipt.complete'] as $route) {
        $this->get(route($route, $this->sale))->assertRedirect(route('login'));
    }
    Volt::test('sales.receipt', ['sale' => $this->sale])->assertForbidden();
});

test('a legacy printing grant cannot substitute for sale access', function () {
    $this->role->syncPermissions(['print receipt']);
    $this->actingAs($this->reader->fresh());
    $this->get(route('sales.receipt', $this->sale))->assertForbidden();
    $this->get(route('sales.receipt.complete', $this->sale))->assertForbidden();
    Volt::test('sales.receipt', ['sale' => $this->sale])->assertForbidden();
});

test('receipt and completion endpoints deny foreign companies branches and other cashiers own sales', function () {
    foreach ([$this->foreignSale, $this->otherBranchSale, $this->colleagueSale] as $sale) {
        foreach (['sales.receipt', 'sales.receipt.complete'] as $route) {
            $response = $this->get(route($route, $sale));
            expect($response->status())->toBeIn([403, 404]);
            $response->assertDontSee($sale->sale_number);
        }
        Volt::test('sales.receipt', ['sale' => $sale])->assertForbidden();
    }
});

test('even company scope and super admin cannot expose receipts from another company', function () {
    $this->role->update(['sales_scope' => 'company']);
    $super = Role::namedForCompany('Super Admin', $this->company->id) ?: Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);
    $this->reader->assignRole($super);
    $this->actingAs($this->reader->fresh());
    $this->get(route('sales.receipt', $this->foreignSale))->assertNotFound();
    $this->get(route('sales.receipt.complete', $this->foreignSale))->assertNotFound();
    Volt::test('sales.receipt', ['sale' => $this->foreignSale])->assertForbidden();
});

test('receipt hydration rechecks access after permission revocation', function () {
    $component = Volt::test('sales.receipt', ['sale' => $this->sale])->assertSee($this->sale->sale_number);
    $this->role->revokePermissionTo('sales.view');
    $this->actingAs($this->reader->fresh());
    $component->call('$refresh')->assertForbidden();
});

test('receipt model state is locked against sale and settings replacement', function () {
    $component = Volt::test('sales.receipt', ['sale' => $this->sale]);
    expect(fn () => $component->set('sale', $this->foreignSale))->toThrow(CannotUpdateLockedPropertyException::class);
    $component = Volt::test('sales.receipt', ['sale' => $this->sale]);
    expect(fn () => $component->set('settings', null))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('obsolete printing permission is not selectable and its historical grants are retained', function () {
    $permission = Permission::findByName('print receipt', 'web');
    $this->role->givePermissionTo($permission);
    $this->reader->givePermissionTo('roles.manage');
    $this->actingAs($this->reader->fresh());
    expect(RolePermissionCatalog::groups()->flatten()->pluck('name'))->not->toContain('print receipt');
    Volt::test('roles.index')->call('editRole', $this->role->id)
        ->assertDontSeeHtml('value="print receipt"')->call('save')->assertHasNoErrors();
    expect(Permission::findByName('print receipt', 'web')->id)->toBe($permission->id)
        ->and($this->role->fresh()->hasPermissionTo('print receipt'))->toBeTrue();
    foreach (['sales.cancel', 'sales.discount', 'sales.edit', 'sales.refund'] as $name) {
        expect(RolePermissionCatalog::groups()->flatten()->pluck('name'))->toContain($name);
    }
});

test('receipt and sale details hydration reject a different tenant or an unauthorized owner', function () {
    foreach (['sales.receipt', 'sales.show'] as $componentName) {
        foreach ([$this->foreignUser, $this->colleague] as $unauthorizedUser) {
            $this->actingAs($this->reader->fresh());
            $component = Volt::test($componentName, ['sale' => $this->sale]);
            $this->actingAs($unauthorizedUser->fresh());
            $component->call('$refresh')->assertForbidden();
        }
    }
});

test('completion controller independently rejects inaccessible sale models', function () {
    request()->setUserResolver(fn () => auth()->user());
    foreach ([$this->foreignSale, $this->otherBranchSale, $this->colleagueSale] as $sale) {
        expect(fn () => app(CompletePosReceiptController::class)->__invoke($sale))
            ->toThrow(HttpException::class);
    }
    $this->app['auth']->forgetGuards();
    expect(fn () => app(CompletePosReceiptController::class)->__invoke($this->sale))
        ->toThrow(HttpException::class);
});

test('manual receipts use company width and temporary overrides never change the preference', function (string $width) {
    $setting = Setting::where('company_id', $this->company->id)->firstOrFail();
    $setting->update(['default_receipt_width' => $width]);
    $snapshot = $setting->fresh()->getAttributes();
    expect($this->reader->can('print receipt'))->toBeFalse();
    $this->get(route('sales.receipt', $this->sale))->assertOk()
        ->assertSee('data-paper-size="'.$width.'"', false)->assertSee('size: '.$width.'mm auto', false);
    foreach (['58', '80'] as $override) {
        $this->get(route('sales.receipt', ['sale' => $this->sale, 'paper' => $override]))->assertOk()
            ->assertSee('data-paper-size="'.$override.'"', false)->assertSee('size: '.$override.'mm auto', false);
    }
    foreach (['invalid', ['58']] as $invalid) {
        $this->get(route('sales.receipt', ['sale' => $this->sale, 'paper' => $invalid]))->assertOk()
            ->assertSee('data-paper-size="'.$width.'"', false);
    }
    expect($setting->fresh()->getAttributes())->toBe($snapshot);
    $this->get(route('sales.receipt', $this->sale))->assertOk()->assertSee('data-paper-size="'.$width.'"', false);
    $otherWidth = $width === '58' ? '80' : '58';
    $this->actingAs($this->foreignUser);
    Setting::create(['company_id' => $this->otherCompany->id, 'company_name' => 'Other', 'default_receipt_width' => $otherWidth]);
    $this->get(route('sales.receipt', $this->foreignSale))->assertOk()->assertSee('data-paper-size="'.$otherWidth.'"', false);
    $this->get(route('sales.receipt', $this->sale))->assertNotFound();
})->with(['58', '80']);
