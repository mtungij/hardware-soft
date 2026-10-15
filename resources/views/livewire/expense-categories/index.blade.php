<?php

use App\Models\ExpenseCategory;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'editingId' => null, 'name' => '', 'description' => '', 'status' => 'active']);

rules(fn () => [
    'name' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')->ignore($this->editingId)],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
]);

$edit = function (int $id) {
    $category = ExpenseCategory::findOrFail($id);
    $this->editingId = $category->id;
    $this->name = $category->name;
    $this->description = $category->description;
    $this->status = $category->status;
};

$save = function () {
    ExpenseCategory::query()->updateOrCreate(['id' => $this->editingId], $this->validate());
    $this->reset(['editingId', 'name', 'description']);
    $this->status = 'active';
    session()->flash('success', __('application.expense_category_saved_75745a2d'));
};

$delete = function (int $id) {
    $category = ExpenseCategory::withCount('expenses')->findOrFail($id);
    if ($category->expenses_count > 0) {
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
                $categories = ExpenseCategory::query()
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
