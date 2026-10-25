<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ExpenseAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Expense company A', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->foreignCompany = Company::create(['company_name' => 'Expense company B', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Expense Main', 'code' => 'MAIN']);
    $this->otherBranch = Branch::create(['company_id' => $this->company->id, 'name' => 'Hidden branch', 'code' => 'OTHER']);
    $this->foreignBranch = Branch::create(['company_id' => $this->foreignCompany->id, 'name' => 'Foreign branch', 'code' => 'FOREIGN']);
    Setting::create(['company_id' => $this->company->id, 'company_name' => 'Expense company A']);
    Setting::create(['company_id' => $this->foreignCompany->id, 'company_name' => 'Expense company B']);
    $this->category = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Local category', 'status' => 'active']);
    $this->foreignCategory = ExpenseCategory::create(['company_id' => $this->foreignCompany->id, 'name' => 'Foreign category', 'status' => 'active']);
    foreach (['view expenses', 'create expenses', 'edit expenses', 'approve expenses', 'delete expenses', 'export expenses', 'reports.expenses', 'reports.export', 'export pdf', 'export excel', 'roles.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->role = Role::create(['name' => 'Cashier', 'company_id' => $this->company->id, 'guard_name' => 'web']);
    $this->role->syncPermissions(['create expenses']);
    $this->cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->cashier->assignRole($this->role);
    $this->payer = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Original expense payer', 'status' => 'active']);
    $this->foreignUser = User::factory()->create(['company_id' => $this->foreignCompany->id, 'branch_id' => $this->foreignBranch->id, 'status' => 'active']);
    $this->expense = Expense::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'expense_category_id' => $this->category->id, 'amount' => 111, 'payment_method' => 'cash', 'expense_date' => today(), 'paid_by' => $this->payer->id, 'reference_number' => 'UNCHANGED-MAIN', 'notes' => 'Existing financial record']);
    $this->otherExpense = Expense::create(['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id, 'expense_category_id' => $this->category->id, 'amount' => 222, 'payment_method' => 'bank', 'expense_date' => today(), 'paid_by' => $this->payer->id]);
    $this->foreignExpense = Expense::create(['company_id' => $this->foreignCompany->id, 'branch_id' => $this->foreignBranch->id, 'expense_category_id' => $this->foreignCategory->id, 'amount' => 333, 'payment_method' => 'cash', 'expense_date' => today(), 'paid_by' => $this->foreignUser->id]);
    $this->snapshot = DB::table('expenses')->orderBy('id')->get()->toJson();
    $this->actingAs($this->cashier)->withSession(['staff_locale' => 'en']);
});

function expenseForm($test)
{
    return Volt::test('expenses.index')->set('branch_id', (string) $test->branch->id)
        ->set('expense_category_id', (string) $test->category->id)->set('amount', '125.50')
        ->set('expense_date', today()->toDateString())->set('reference_number', 'NEW-EXPENSE');
}

test('cashiers and store keepers with create permission can open and submit only the form', function (string $roleName) {
    if ($roleName !== 'Cashier') {
        $role = Role::create(['name' => $roleName, 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $role->givePermissionTo('create expenses');
        $this->cashier->syncRoles([$role]);
        $this->actingAs($this->cashier->fresh());
    }
    $this->get(route('expenses.index'))->assertOk()->assertSeeHtml('wire:submit="save"')
        ->assertDontSee($this->payer->name)->assertDontSee('Filtered Expenses')->assertDontSee('Foreign category');
    expenseForm($this)->call('save')->assertHasNoErrors()->assertSet('amount', '');
    $created = Expense::where('reference_number', 'NEW-EXPENSE')->firstOrFail();
    expect($created->company_id)->toBe($this->company->id)->and($created->branch_id)->toBe($this->branch->id)
        ->and($created->paid_by)->toBe($this->cashier->id)->and($created->amount)->toBe('125.50')
        ->and(DB::table('expenses')->whereIn('id', [$this->expense->id, $this->otherExpense->id, $this->foreignExpense->id])->orderBy('id')->get()->toJson())->toBe($this->snapshot);
})->with(['Cashier', 'Store Keeper']);

test('create-only users cannot edit delete approve or export existing expenses', function () {
    Volt::test('expenses.index')->call('edit', $this->expense->id)->assertForbidden();
    Volt::test('expenses.index')->call('delete', $this->expense->id)->assertForbidden();
    expect(fn () => ExpenseAccess::authorize($this->cashier, 'approve expenses', $this->expense))->toThrow(HttpException::class);
    $component = expenseForm($this);
    expect(fn () => $component->set('editingId', $this->expense->id))->toThrow(CannotUpdateLockedPropertyException::class);
    // General report/export grants must not bypass the independent expense permissions.
    $this->role->givePermissionTo(['reports.expenses', 'reports.export', 'export pdf', 'export excel']);
    $this->actingAs($this->cashier->fresh());
    foreach (['tables.expenses', 'reports.expenses'] as $export) {
        $this->get(route('exports.download', ['export' => $export, 'format' => 'excel']))->assertForbidden();
    }
    $this->get(route('reports.export', ['report' => 'expenses', 'format' => 'pdf']))->assertForbidden();
    $this->get(route('reports.expenses'))->assertForbidden();
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('revoking create permission blocks a previously mounted form even with view access', function () {
    $this->role->givePermissionTo('view expenses');
    $this->actingAs($this->cashier->fresh());
    $component = expenseForm($this);
    $this->role->revokePermissionTo('create expenses');
    $this->actingAs($this->cashier->fresh());
    $component->call('save')->assertForbidden();
    $this->get(route('expenses.index'))->assertOk()->assertDontSeeHtml('wire:submit="save"');
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('users with neither permission and guests cannot mount or hydrate expenses', function () {
    $component = expenseForm($this);
    $this->role->syncPermissions([]);
    $this->actingAs($this->cashier->fresh());
    $this->get(route('expenses.index'))->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->get(route('expenses.index'))->assertRedirect(route('login'));
    $this->actingAs($this->cashier->fresh());
    $component->call('$refresh')->assertForbidden();
    Volt::test('expenses.index')->assertForbidden();
    $this->app['auth']->forgetGuards();
    Volt::test('expenses.index')->assertForbidden();
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('forged branch and category inputs cannot create expenses outside the accessible tenant branch', function () {
    foreach ([$this->otherBranch, $this->foreignBranch] as $branch) {
        expenseForm($this)->set('branch_id', (string) $branch->id)->call('save')->assertHasErrors('branch_id');
    }
    expenseForm($this)->set('expense_category_id', (string) $this->foreignCategory->id)->call('save')->assertHasErrors('expense_category_id');
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('view access scopes listings and forged edit delete IDs to the company and branch', function () {
    $this->role->givePermissionTo(['view expenses', 'edit expenses', 'delete expenses']);
    $this->actingAs($this->cashier->fresh());
    $this->get(route('expenses.index'))->assertOk()->assertSee($this->payer->name)->assertDontSee('Foreign category')
        ->assertSeeHtml('edit('.$this->expense->id.')')->assertDontSeeHtml('edit('.$this->otherExpense->id.')')->assertDontSeeHtml('edit('.$this->foreignExpense->id.')');
    foreach ([$this->otherExpense, $this->foreignExpense] as $expense) {
        foreach (['edit', 'delete'] as $action) {
            expect(fn () => Volt::test('expenses.index')->call($action, $expense->id))->toThrow(ModelNotFoundException::class);
        }
    }
    Volt::test('expenses.index')->set('branchFilter', (string) $this->foreignBranch->id)->call('$refresh')->assertDontSee($this->payer->name);
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('authorized editing preserves the original payer and edit revocation prevents stale saves', function () {
    $this->role->givePermissionTo(['view expenses', 'edit expenses']);
    $this->actingAs($this->cashier->fresh());
    $component = Volt::test('expenses.index')->call('edit', $this->expense->id)->set('amount', '150')->call('save')->assertHasNoErrors();
    expect($this->expense->fresh()->amount)->toBe('150.00')->and($this->expense->fresh()->paid_by)->toBe($this->payer->id);
    $component->call('edit', $this->expense->id)->set('amount', '999');
    $this->role->revokePermissionTo('edit expenses');
    $this->actingAs($this->cashier->fresh());
    $component->call('save')->assertForbidden();
    expect($this->expense->fresh()->amount)->toBe('150.00');
});

test('expense deletion requires its independent permission', function () {
    $this->role->givePermissionTo(['view expenses', 'delete expenses']);
    $this->actingAs($this->cashier->fresh());
    Volt::test('expenses.index')->call('delete', $this->expense->id)->assertHasNoErrors();
    expect(DB::table('expenses')->where('id', $this->expense->id)->exists())->toBeFalse()
        ->and(DB::table('expenses')->where('id', $this->otherExpense->id)->exists())->toBeTrue()
        ->and(DB::table('expenses')->where('id', $this->foreignExpense->id)->exists())->toBeTrue();
});

test('sidebar visibility follows view or create grants and not the role name', function () {
    foreach ([['create expenses'], ['view expenses'], []] as $permissions) {
        $this->role->syncPermissions($permissions);
        $this->actingAs($this->cashier->fresh());
        $html = view('layouts.app', ['slot' => ''])->render();
        if ($permissions) {
            expect($html)->toContain('href="'.route('expenses.index').'"');
        } else {
            expect($html)->not->toContain('href="'.route('expenses.index').'"');
        }
    }
});

test('expense exports require view and export permissions and restrict company and branch rows', function () {
    $this->role->givePermissionTo('view expenses');
    $this->actingAs($this->cashier->fresh());
    $this->get(route('exports.download', ['export' => 'tables.expenses', 'format' => 'excel']))->assertForbidden();
    $this->role->givePermissionTo('export expenses');
    $this->actingAs($this->cashier->fresh());
    foreach (['tables.expenses', 'reports.expenses'] as $export) {
        $response = $this->get(route('exports.download', ['export' => $export, 'format' => 'excel']))->assertOk();
        expect($response->streamedContent())->toContain('Expense Main')->not->toContain('Hidden branch', 'Foreign branch', 'Expense company B');
    }
    $this->get(route('reports.export', ['report' => 'expenses', 'format' => 'excel']))->assertOk();
    $this->get(route('exports.download', ['export' => 'tables.expenses', 'format' => 'excel', 'branchFilter' => $this->foreignBranch->id]))->assertSessionHasErrors('branch_id');
    $this->get(route('exports.download', ['export' => 'tables.users', 'format' => 'excel']))->assertForbidden();
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('system owners and hydrated tenant switches cannot bypass expense company isolation', function () {
    $this->role->givePermissionTo(['view expenses', 'edit expenses', 'approve expenses', 'export expenses']);
    $this->cashier->update(['is_system_owner' => true]);
    $this->actingAs($this->cashier->fresh());
    $component = Volt::test('expenses.index')->call('edit', $this->expense->id)->set('amount', '999');
    expect(ExpenseAccess::query($this->cashier)->pluck('id')->all())->not->toContain($this->foreignExpense->id);
    expect(fn () => ExpenseAccess::authorize($this->cashier, 'approve expenses', $this->foreignExpense))->toThrow(HttpException::class);
    $this->foreignUser->givePermissionTo(['create expenses', 'edit expenses']);
    $this->actingAs($this->foreignUser);
    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});

test('expense role grants and revocations stay in their company and preserve financial records', function () {
    $otherRole = Role::create(['company_id' => $this->foreignCompany->id, 'name' => 'Cashier', 'guard_name' => 'web']);
    $otherRole->givePermissionTo('create expenses');
    $this->foreignUser->assignRole($otherRole);
    $this->cashier->givePermissionTo('roles.manage');
    $this->role->givePermissionTo('view expenses');
    $this->actingAs($this->cashier->fresh());
    Volt::test('roles.index')->call('editRole', $this->role->id)
        ->assertSee('Expenses')->assertSeeHtml('value="create expenses"')
        ->set('permissions', ['view expenses'])->call('save')->assertHasNoErrors();
    expect($this->cashier->fresh()->can('create expenses'))->toBeFalse()
        ->and($this->cashier->fresh()->can('view expenses'))->toBeTrue()
        ->and($this->foreignUser->fresh()->can('create expenses'))->toBeTrue();
    expenseForm($this)->call('save')->assertForbidden();
    Volt::test('roles.index')->call('editRole', $this->role->id)
        ->set('permissions', ['view expenses', 'create expenses'])->call('save')->assertHasNoErrors();
    expect($this->cashier->fresh()->can('create expenses'))->toBeTrue()
        ->and(DB::table('expenses')->orderBy('id')->get()->toJson())->toBe($this->snapshot);
});
