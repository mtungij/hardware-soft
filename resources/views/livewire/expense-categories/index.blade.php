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
    session()->flash('success', 'Expense category saved.');
};

$delete = function (int $id) {
    $category = ExpenseCategory::withCount('expenses')->findOrFail($id);
    if ($category->expenses_count > 0) {
        session()->flash('error', 'Cannot delete a category with expenses.');
        return;
    }
    $category->delete();
    session()->flash('success', 'Expense category deleted.');
};

?>

<div>
    <x-page-header title="Expense Categories" description="Manage operating cost groups." :breadcrumbs="['Dashboard' => route('dashboard'), 'Expenses' => route('expenses.index'), 'Categories' => null]" />

    <div class="grid gap-6 xl:grid-cols-[380px_1fr]">
        <x-card :title="$editingId ? 'Edit Category' : 'Create Category'">
            <form wire:submit="save" class="space-y-4">
                <x-form-input label="Name" name="name" wire:model="name" required />
                <label class="block text-sm font-bold">Description
                    <textarea wire:model="description" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
                <label class="block text-sm font-bold">Status
                    <select wire:model="status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
                <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Save Category</button>
            </form>
        </x-card>

        <x-card title="Categories">
            <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search categories">
            @php
                $categories = ExpenseCategory::query()
                    ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
                    ->withCount('expenses')
                    ->latest()
                    ->paginate(10);
            @endphp
            <x-table :headers="['Name', 'Expenses', 'Status', 'Actions']">
                @foreach ($categories as $category)
                    <tr>
                        <td class="px-4 py-3 font-bold">{{ $category->name }}</td>
                        <td class="px-4 py-3">{{ $category->expenses_count }}</td>
                        <td class="px-4 py-3">{{ ucfirst($category->status) }}</td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $category->id }})" class="text-sm font-bold text-build-orange">Edit</button>
                            <button wire:click="delete({{ $category->id }})" wire:confirm="Delete category?" class="ml-3 text-sm font-bold text-red-600">Delete</button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
            <div class="mt-4">{{ $categories->links() }}</div>
        </x-card>
    </div>
</div>
