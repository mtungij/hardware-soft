<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->companies = collect(['Category A', 'Category B'])->map(fn ($name) => Company::create([
        'company_name' => $name, 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001',
    ]));
    $this->admins = $this->companies->map(function ($company) {
        Setting::create(['company_id' => $company->id, 'company_name' => $company->company_name]);
        $role = Role::create(['name' => 'Admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $user = User::factory()->create(['company_id' => $company->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    });
    $this->foreignCategory = ExpenseCategory::create(['company_id' => $this->companies[1]->id, 'name' => 'Foreign Transport']);
    $this->actingAs($this->admins[0])->withSession(['staff_locale' => 'en']);
});

test('companies can independently create the same category through the original form', function () {
    $this->get(route('expense-categories.index'))->assertOk()->assertSeeHtml('wire:submit="save"');
    foreach ($this->admins as $admin) {
        $this->actingAs($admin);
        Volt::test('expense-categories.index')->set('name', 'Transport')->call('save')->assertHasNoErrors()->assertSet('name', '');
    }
    expect(DB::table('expense_categories')->where('name', 'Transport')->count())->toBe(2);
});

test('duplicate names and simple case or whitespace variations are rejected', function ($name) {
    ExpenseCategory::create(['name' => 'Office Transport']);
    Volt::test('expense-categories.index')->set('name', $name)->call('save')->assertHasErrors('name');
    expect(ExpenseCategory::count())->toBe(1);
})->with(['Office Transport', 'office transport', '  Office   Transport  ', "Office\tTransport"]);

test('editing the same name succeeds but editing to a duplicate fails', function () {
    $category = ExpenseCategory::create(['name' => 'Transport']);
    ExpenseCategory::create(['name' => 'Rent']);
    Volt::test('expense-categories.index')->call('edit', $category->id)->call('save')->assertHasNoErrors();
    Volt::test('expense-categories.index')->call('edit', $category->id)->set('name', 'rent')->call('save')->assertHasErrors('name');
    expect($category->fresh()->name)->toBe('Transport');
});

test('foreign categories cannot be listed searched edited or deleted including by platform owners', function (bool $owner) {
    $this->admins[0]->forceFill(['is_system_owner' => $owner])->save();
    $this->actingAs($this->admins[0]->fresh());
    $snapshot = DB::table('expense_categories')->orderBy('id')->get()->toJson();
    Volt::test('expense-categories.index')->set('search', 'Foreign')->assertDontSee('Foreign Transport');
    expect(fn () => Volt::test('expense-categories.index')->call('edit', $this->foreignCategory->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => Volt::test('expense-categories.index')->call('delete', $this->foreignCategory->id))->toThrow(ModelNotFoundException::class);
    expect(DB::table('expense_categories')->orderBy('id')->get()->toJson())->toBe($snapshot);
})->with([false, true]);

test('browser cannot replace the editing ID or submit a company property', function () {
    expect(fn () => Volt::test('expense-categories.index')->set('editingId', $this->foreignCategory->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Volt::test('expense-categories.index')->set('company_id', $this->companies[1]->id))
        ->toThrow(PublicPropertyNotFoundException::class);
    Volt::test('expense-categories.index')->set('name', '  Local   Travel  ')->call('save')->assertHasNoErrors();
    expect(DB::table('expense_categories')->where('name', 'Local Travel')->value('company_id'))->toBe($this->companies[0]->id);
});

test('database enforces company uniqueness independently of validation', function () {
    ExpenseCategory::create(['name' => 'Transport']);
    DB::table('expense_categories')->insert(['company_id' => $this->companies[1]->id, 'name' => 'Transport']);
    expect(fn () => DB::table('expense_categories')->insert(['company_id' => $this->companies[0]->id, 'name' => 'Transport']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('revoked management role and guests cannot submit an already opened form', function () {
    $component = Volt::test('expense-categories.index')->set('name', 'Blocked');
    $this->admins[0]->syncRoles([]);
    $this->actingAs($this->admins[0]->fresh());
    $component->call('save')->assertForbidden();
    Volt::test('expense-categories.index')->assertForbidden();
});

test('guests cannot open or mount category management', function () {
    $this->app['auth']->forgetGuards();
    $this->get(route('expense-categories.index'))->assertRedirect(route('login'));
    Volt::test('expense-categories.index')->assertForbidden();
});

test('a company switch cannot save or refresh an editor opened in another company', function () {
    $category = ExpenseCategory::create(['name' => 'Original']);
    $component = Volt::test('expense-categories.index')->call('edit', $category->id);
    $this->actingAs($this->admins[1]);
    expect(fn () => $component->call('save'))->toThrow(ModelNotFoundException::class);
    expect(DB::table('expense_categories')->where('id', $category->id)->value('name'))->toBe('Original');
});

test('categories with expenses in another branch cannot be deleted and financial records remain intact', function () {
    $first = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $other = Branch::create(['name' => 'Other', 'code' => 'OTHER']);
    $category = ExpenseCategory::create(['name' => 'Historical']);
    Expense::create(['branch_id' => $other->id, 'expense_category_id' => $category->id, 'amount' => 1250.50,
        'payment_method' => 'cash', 'expense_date' => today(), 'paid_by' => $this->admins[0]->id]);
    $before = DB::table('expenses')->get()->toJson();
    $this->admins[0]->forceFill(['branch_id' => $first->id])->save();
    $this->actingAs($this->admins[0]->fresh());
    Volt::test('expense-categories.index')->call('delete', $category->id)->assertHasNoErrors();
    expect(DB::table('expense_categories')->where('id', $category->id)->exists())->toBeTrue()
        ->and(DB::table('expenses')->get()->toJson())->toBe($before);
});

test('authorized category editing status search and deletion remain functional', function () {
    $component = Volt::test('expense-categories.index')->set('name', 'Travel')->set('description', 'Local travel')
        ->call('save')->assertHasNoErrors();
    $category = ExpenseCategory::where('name', 'Travel')->firstOrFail();
    $component->call('edit', $category->id)->set('status', 'inactive')->call('save')->assertHasNoErrors()
        ->set('search', 'Travel')->assertSee('Travel');
    expect($category->fresh()->status)->toBe('inactive');
    $component->call('delete', $category->id)->assertHasNoErrors();
    expect(ExpenseCategory::whereKey($category->id)->exists())->toBeFalse();
});

test('invalid category names return validation errors without writing records', function ($name) {
    $before = DB::table('expense_categories')->get()->toJson();
    Volt::test('expense-categories.index')->set('name', $name)->call('save')->assertHasErrors('name');
    expect(DB::table('expense_categories')->get()->toJson())->toBe($before);
})->with(['blank' => '   ', 'array' => [['Transport']], 'too long' => str_repeat('x', 256)]);
