<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\hydrate;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['editingId' => null])->locked();
state(['search' => '', 'name' => '', 'description' => '', 'status' => 'active']);

$companyId = function (): int {
    $user = auth()->user();
    abort_unless($user && $user->company_id && $user->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Accountant']), 403);

    return (int) $user->company_id;
};

mount(fn () => $this->companyId());
hydrate(function () {
    $companyId = $this->companyId();
    if ($this->editingId) {
        ExpenseCategory::where('company_id', $companyId)->findOrFail($this->editingId);
    }
});

rules(fn () => [
    'name' => ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
        $duplicate = ExpenseCategory::query()->where('company_id', $this->companyId())
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->pluck('name')->contains(fn ($name) => mb_strtolower(Str::squish($name)) === mb_strtolower(Str::squish($value)));
        if ($duplicate) {
            $fail(__('validation.unique', ['attribute' => __('application.name_709a2322')]));
        }
    }],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
]);

$edit = function (int $id) {
    $category = ExpenseCategory::where('company_id', $this->companyId())->findOrFail($id);
    $this->editingId = $category->id;
    $this->name = $category->name;
    $this->description = $category->description;
    $this->status = $category->status;
};

$save = function () {
    $companyId = $this->companyId();
    // Resolve the owned record before allowing its ID to be ignored during validation.
    $category = $this->editingId
        ? ExpenseCategory::where('company_id', $companyId)->findOrFail($this->editingId)
        : new ExpenseCategory(['company_id' => $companyId]);
    if (is_string($this->name)) {
        $this->name = Str::squish($this->name);
    }
    $data = $this->validate();
    try {
        $category->fill($data)->save();
    } catch (UniqueConstraintViolationException $exception) {
        // A concurrent save may win after validation; return the usual field error.
        throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => __('application.name_709a2322')])]);
    }
    $this->reset(['editingId', 'name', 'description']);
    $this->status = 'active';
    session()->flash('success', __('application.expense_category_saved_75745a2d'));
};

$delete = function (int $id) {
    $category = ExpenseCategory::where('company_id', $this->companyId())->findOrFail($id);
    // A company-wide category may also be referenced by expenses in another branch.
    if ($category->expenses()->withoutGlobalScopes()->exists()) {
        session()->flash('error', __('application.cannot_delete_a_category_with_expenses_9244feca'));
        return;
    }
    $category->delete();
    session()->flash('success', __('application.expense_category_deleted_e2534bbb'));
};

?>

<div>
    <x-page-header title="{{ __('application.expense_categories_444fa32c') }}" description="{{ __('application.manage_operating_cost_groups_9fb293b9') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Expenses' => route('expenses.index'), 'Categories' => null]" />

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        <x-card :title="$editingId ? __('application.edit_category_71b97a73') : __('application.create_category_9096c2ac')">
            <form wire:submit="save" class="space-y-4">
                <x-form-input label="{{ __('application.name_709a2322') }}" name="name" wire:model="name" required />
                <label class="block text-sm font-bold">{{ __('application.description_55f8ebc8') }}
                    <textarea wire:model="description" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
                <label class="block text-sm font-bold">{{ __('application.status_bae7d5be') }}
                    <select wire:model="status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="active">{{ __('application.active_a733b809') }}</option>
                        <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                    </select>
                </label>
                <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.save_category_a1f54af4') }}</button>
            </form>
        </x-card>

        <x-card title="{{ __('application.categories_6ccb6007') }}">
            <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_categories_259c54d9') }}">
            @php
                $categories = ExpenseCategory::query()->where('company_id', $this->companyId())
                    ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
                    ->withCount('expenses')
                    ->latest()
                    ->paginate(10);
            @endphp
            <x-table :headers="[__('application.name_709a2322'), __('application.expenses_8d437dbf'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
                @foreach ($categories as $category)
                    <tr>
                        <td class="px-4 py-3 font-bold">{{ $category->name }}</td>
                        <td class="px-4 py-3">{{ $category->expenses_count }}</td>
                        <td class="px-4 py-3">{{ \App\Support\UiText::status($category->status) }}</td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $category->id }})" class="text-sm font-bold text-build-orange">{{ __('application.edit_5301648d') }}</button>
                            <button wire:click="delete({{ $category->id }})" wire:confirm="{{ __('application.delete_category_bb2879a4') }}" class="ml-3 text-sm font-bold text-red-600">{{ __('application.delete_f6fdbe48') }}</button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
            <div class="mt-4">{{ $categories->links() }}</div>
        </x-card>
    </div>
</div>
