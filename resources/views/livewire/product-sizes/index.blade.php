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
    session()->flash('success', __('application.product_size_saved_successfully_d3532988'));
};

$toggleStatus = function (int $sizeId) {
    abort_unless($this->canManage(), 403);

    $size = ProductSize::query()->findOrFail($sizeId);
    $size->update(['status' => $size->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.product_size_status_updated_e2623d8c'));
};

$deleteSize = function (int $sizeId) {
    abort_unless($this->canManage(), 403);

    $size = ProductSize::query()->withCount(['products', 'saleItems', 'purchaseItems'])->findOrFail($sizeId);

    if ($size->products_count > 0 || $size->sale_items_count > 0 || $size->purchase_items_count > 0) {
        session()->flash('error', __('application.cannot_delete_a_size_used_by_products_or_historical_transactions_c382c7d7'));
        return;
    }

    $size->delete();
    session()->flash('success', __('application.product_size_deleted_9ec24d0f'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.product_sizes_efd44743') }}"
        description="{{ __('application.manage_reusable_hardware_dimensions_without_storing_them_inside_product_nam_2e7ebca3') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Product Sizes' => null]"
    />

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? __('terminology.edit_product_size_67eedd2a') : __('terminology.create_product_size_7c595dfe')" description="{{ __('application.use_symbols_such_as_2_4_2mm_fb8ae753') }}">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="{{ __('application.size_name_3a5456a9') }}" name="name" wire:model="name" required />
                    <x-form-input label="{{ __('application.size_symbol_e6320711') }}" name="symbol" wire:model="symbol" required />

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.status_bae7d5be') }}
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">{{ __('application.active_a733b809') }}</option>
                            <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.description_55f8ebc8') }}
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_size_22951066') }}</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.clear_719ea396') }}</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="{{ __('application.sizes_list_0bc4deca') }}" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-3">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_sizes_24d4a778') }}">
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                    <option value="active">{{ __('application.active_a733b809') }}</option>
                    <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
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

            <x-table :headers="[__('application.size_name_3a5456a9'), __('terminology.symbol_3f84ef53'), __('application.description_55f8ebc8'), __('application.products_fe0a091f'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
                @forelse ($sizes as $size)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $size->name }}</td>
                        <td class="px-4 py-3 font-mono text-sm">{{ $size->symbol }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">{{ $size->description ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $size->products_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $size->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($size->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editSize({{ $size->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</button>
                                    <button wire:click="toggleStatus({{ $size->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $size->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                    <button wire:click="deleteSize({{ $size->id }})" wire:confirm="{{ __('application.delete_this_product_size_36f3171d') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_product_sizes_found_3f516efb') }}</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $sizes->links() }}</div>
        </x-card>
    </div>
</div>
