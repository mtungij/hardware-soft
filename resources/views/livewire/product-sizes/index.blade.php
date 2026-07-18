<?php

use App\Models\ProductSize;
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
    'editingId' => null,
    'name' => '',
    'symbol' => '',
    'description' => '',
    'status' => 'active',
]);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'symbol' => [
        'required',
        'string',
        'max:100',
        Rule::unique('product_sizes', 'symbol')
            ->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id))
            ->ignore($this->editingId),
    ],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$resetForm = function () {
    $this->reset(['editingId', 'name', 'symbol', 'description']);
    $this->status = 'active';
};

$editSize = function (int $sizeId) {
    abort_unless($this->canManage(), 403);

    $size = ProductSize::query()->findOrFail($sizeId);

    $this->editingId = $size->id;
    $this->name = $size->name;
    $this->symbol = $size->symbol;
    $this->description = $size->description;
    $this->status = $size->status;
};

$save = function () {
    abort_unless($this->canManage(), 403);

    ProductSize::query()->updateOrCreate(['id' => $this->editingId], $this->validate());

    $this->resetForm();
    session()->flash('success', 'Product size saved successfully.');
};

$toggleStatus = function (int $sizeId) {
    abort_unless($this->canManage(), 403);

    $size = ProductSize::query()->findOrFail($sizeId);
    $size->update(['status' => $size->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Product size status updated.');
};

$deleteSize = function (int $sizeId) {
    abort_unless($this->canManage(), 403);

    $size = ProductSize::query()->withCount('products')->findOrFail($sizeId);

    if ($size->products_count > 0) {
        session()->flash('error', 'Cannot delete a size used by products.');
        return;
    }

    $size->delete();
    session()->flash('success', 'Product size deleted.');
};

?>

<div>
    <x-page-header
        title="Product Sizes"
        description="Manage reusable hardware dimensions without storing them inside product names."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Product Sizes' => null]"
    />

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? 'Edit Product Size' : 'Create Product Size'" description="Use symbols such as 2 × 4 (2mm).">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="Size Name" name="name" wire:model="name" required />
                    <x-form-input label="Size Symbol" name="symbol" wire:model="symbol" required />

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
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Size</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Clear</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="Sizes List" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search sizes...">
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            @php
                $sizes = ProductSize::query()
                    ->withCount('products')
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('symbol', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")))
                    ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                    ->orderBy('symbol')
                    ->paginate(10);
            @endphp

            <x-table :headers="['Size Name', 'Symbol', 'Description', 'Products', 'Status', 'Actions']">
                @forelse ($sizes as $size)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $size->name }}</td>
                        <td class="px-4 py-3 font-mono text-sm">{{ $size->symbol }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">{{ $size->description ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $size->products_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $size->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($size->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editSize({{ $size->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</button>
                                    <button wire:click="toggleStatus({{ $size->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $size->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    <button wire:click="deleteSize({{ $size->id }})" wire:confirm="Delete this product size?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No product sizes found.</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $sizes->links() }}</div>
        </x-card>
    </div>
</div>
