<?php

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'statusFilter' => '',
    'branchFilter' => '',
    'editingId' => null,
    'branch_id' => '',
    'name' => '',
    'code' => '',
    'description' => '',
    'status' => 'active',
]);

rules(fn () => [
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'code' => ['required', 'string', 'max:50', Rule::unique('categories', 'code')->ignore($this->editingId)],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$resetForm = function () {
    $this->reset(['editingId', 'branch_id', 'name', 'code', 'description']);
    $this->status = 'active';
};

$editCategory = function (int $categoryId) {
    abort_unless($this->canManage(), 403);

    $category = Category::findOrFail($categoryId);

    $this->editingId = $category->id;
    $this->branch_id = (string) $category->branch_id;
    $this->name = $category->name;
    $this->code = $category->code;
    $this->description = $category->description;
    $this->status = $category->status;
};

$save = function () {
    abort_unless($this->canManage(), 403);

    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;

    Category::query()->updateOrCreate(['id' => $this->editingId], $validated);

    $this->resetForm();
    session()->flash('success', 'Category saved successfully.');
};

$toggleStatus = function (int $categoryId) {
    abort_unless($this->canManage(), 403);

    $category = Category::findOrFail($categoryId);
    $category->update(['status' => $category->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Category status updated.');
};

$deleteCategory = function (int $categoryId) {
    abort_unless($this->canManage(), 403);

    $category = Category::withCount('products')->findOrFail($categoryId);

    if ($category->products_count > 0) {
        session()->flash('error', 'Cannot delete a category with attached products.');
        return;
    }

    $category->delete();
    session()->flash('success', 'Category deleted.');
};

?>

<div>
    <x-page-header
        title="Categories"
        description="Classify products such as Cement, Mabati, Nondo, Rangi, Plumbing, and Tools."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Categories' => null]"
    />

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? 'Edit Category' : 'Create Category'" description="Category codes must be unique.">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="Category Name" name="name" wire:model="name" required />
                    <x-form-input label="Category Code" name="code" wire:model="code" required />

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Branch
                        <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Global category</option>
                            @foreach (Branch::orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Status
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        Description
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Category</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Clear</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="Categories List" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search categories...">
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All branches</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>

            @php
                $categories = Category::query()
                    ->with(['branch'])
                    ->withCount('products')
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                    ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                    ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                    ->latest()
                    ->paginate(10);
            @endphp

            <x-table :headers="['Name', 'Code', 'Branch', 'Products', 'Status', 'Actions']">
                @forelse ($categories as $category)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3"><p class="font-black">{{ $category->name }}</p><p class="text-xs text-slate-500">{{ $category->description }}</p></td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $category->code }}</td>
                        <td class="px-4 py-3">{{ $category->branch?->name ?? 'Global' }}</td>
                        <td class="px-4 py-3">{{ $category->products_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $category->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($category->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editCategory({{ $category->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</button>
                                    <button wire:click="toggleStatus({{ $category->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $category->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    <button wire:click="deleteCategory({{ $category->id }})" wire:confirm="Delete this category?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No categories found.</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $categories->links() }}</div>
        </x-card>
    </div>
</div>
