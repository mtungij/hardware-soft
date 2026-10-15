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
    'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'editingId' => null,
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'name' => '',
    'code' => '',
    'description' => '',
    'status' => 'active',
    'allow_fractional_sales' => false,
    'supports_product_sizes' => false,
]);

rules(fn () => [
    'branch_id' => ['nullable', 'exists:branches,id'],
    'name' => ['required', 'string', 'max:255'],
    'code' => ['required', 'string', 'max:50', Rule::unique('categories', 'code')->ignore($this->editingId)],
    'description' => ['nullable', 'string', 'max:1000'],
    'status' => ['required', 'in:active,inactive'],
    'allow_fractional_sales' => ['boolean'],
    'supports_product_sizes' => ['boolean'],
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$resetForm = function () {
    $this->reset(['editingId', 'branch_id', 'name', 'code', 'description']);
    $this->status = 'active';
    $this->allow_fractional_sales = false;
    $this->supports_product_sizes = false;
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
    $this->allow_fractional_sales = (bool) $category->allow_fractional_sales;
    $this->supports_product_sizes = (bool) $category->supports_product_sizes;
};

$save = function () {
    abort_unless($this->canManage(), 403);

    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;

    Category::query()->updateOrCreate(['id' => $this->editingId], $validated);

    $this->resetForm();
    session()->flash('success', __('application.category_saved_successfully_d90eb806'));
};

$toggleStatus = function (int $categoryId) {
    abort_unless($this->canManage(), 403);

    $category = Category::findOrFail($categoryId);
    $category->update(['status' => $category->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.category_status_updated_1f3b5707'));
};

$deleteCategory = function (int $categoryId) {
    abort_unless($this->canManage(), 403);

    $category = Category::withCount('products')->findOrFail($categoryId);

    if ($category->products_count > 0) {
        session()->flash('error', __('application.cannot_delete_a_category_with_attached_products_e5296baa'));
        return;
    }

    $category->delete();
    session()->flash('success', __('application.category_deleted_03d64399'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.categories_6ccb6007') }}"
        description="{{ __('application.classify_products_such_as_cement_mabati_nondo_rangi_plumbing_and_tools_c74f86ff') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Categories' => null]"
    >
        <x-export-actions export="tables.categories" :params="compact('search', 'statusFilter', 'branchFilter')" />
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        @if ($this->canManage())
            <x-card :title="$editingId ? __('application.edit_category_71b97a73') : __('application.create_category_9096c2ac')" description="{{ __('application.category_codes_must_be_unique_ce92ad41') }}">
                <form wire:submit="save" class="space-y-4">
                    <x-form-input label="{{ __('application.category_name_912f070c') }}" name="name" wire:model="name" required />
                    <x-form-input label="{{ __('application.category_code_3f72177b') }}" name="code" wire:model="code" required />

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.branch_1627510b') }}
                        <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                            <option value="">{{ __('application.global_category_195ce0c2') }}</option>
                            @foreach (Branch::orderBy('name')->get() as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.status_bae7d5be') }}
                        <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="active">{{ __('application.active_a733b809') }}</option>
                            <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                        </select>
                    </label>

                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                        <input type="checkbox" wire:model="allow_fractional_sales" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        {{ __('application.allow_fractional_sales_85f08638') }}
                    </label>

                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                        <input type="checkbox" wire:model="supports_product_sizes" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        {{ __('application.supports_product_sizes_ea34caa2') }}
                    </label>

                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                        {{ __('application.description_55f8ebc8') }}
                        <textarea wire:model="description" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_category_a1f54af4') }}</button>
                        <button type="button" wire:click="resetForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.clear_719ea396') }}</button>
                    </div>
                </form>
            </x-card>
        @endif

        <x-card title="{{ __('application.categories_list_7ef0d91d') }}" class="{{ $this->canManage() ? '' : 'xl:col-span-2' }}">
            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_categories_1bf5c01b') }}">
                <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                    <option value="active">{{ __('application.active_a733b809') }}</option>
                    <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                </select>
                <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                    <option value="">{{ __('application.all_branches_25c23746') }}</option>
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

            <x-table :headers="[__('application.name_709a2322'), __('application.code_adac6937'), __('application.branch_1627510b'), __('application.products_fe0a091f'), __('terminology.fractional_sales_42842cbf'), __('application.product_sizes_efd44743'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
                @forelse ($categories as $category)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3"><p class="font-black">{{ $category->name }}</p><p class="text-xs text-slate-500">{{ $category->description }}</p></td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $category->code }}</td>
                        <td class="px-4 py-3">{{ $category->branch?->name ?? __('terminology.global_5f1184f7') }}</td>
                        <td class="px-4 py-3">{{ $category->products_count }}</td>
                        <td class="px-4 py-3"><span class="{{ $category->allow_fractional_sales ? 'badge-success' : 'badge-warning' }}">{{ $category->allow_fractional_sales ? __('terminology.allowed_77c7b490') : __('terminology.off_e3de5ab0') }}</span></td>
                        <td class="px-4 py-3"><span class="{{ $category->supports_product_sizes ? 'badge-success' : 'badge-warning' }}">{{ $category->supports_product_sizes ? __('terminology.supported_0bf8509b') : __('terminology.off_e3de5ab0') }}</span></td>
                        <td class="px-4 py-3"><span class="{{ $category->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($category->status) }}</span></td>
                        <td class="px-4 py-3">
                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-2">
                                    <button wire:click="editCategory({{ $category->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</button>
                                    <button wire:click="toggleStatus({{ $category->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $category->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                    <button wire:click="deleteCategory({{ $category->id }})" wire:confirm="{{ __('application.delete_this_category_8f1e0280') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                                </div>
                            @else
                                <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_categories_found_e6c237ce') }}</td></tr>
                @endforelse
            </x-table>

            <div class="mt-4">{{ $categories->links() }}</div>
        </x-card>
    </div>
</div>
