<?php

use App\Models\Branch;
use App\Models\Customer;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'statusFilter' => '', 'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'typeFilter' => '', 'created_from' => '', 'created_to' => '']);

mount(function () {
    $this->search = request('search', $this->search);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
    $this->branchFilter = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branchFilter', $this->branchFilter) ? (int) request('branchFilter', $this->branchFilter) : null) ?? '');
    $this->typeFilter = request('typeFilter', $this->typeFilter);
    $this->created_from = request('created_from', $this->created_from);
    $this->created_to = request('created_to', $this->created_to);
});

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$toggleStatus = function (int $customerId) {
    abort_unless($this->canManage(), 403);

    $customer = Customer::findOrFail($customerId);
    abort_if($customer->is_system_customer, 403);

    $customer->update(['status' => $customer->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.customer_status_updated_3848f854'));
};

$deleteCustomer = function (int $customerId) {
    abort_unless($this->canManage(), 403);

    $customer = Customer::findOrFail($customerId);
    abort_if($customer->is_system_customer, 403);

    $customer->delete();
    session()->flash('success', __('application.customer_deleted_c9ce4d2d'));
};

?>

<div>
    <x-page-header
        title="{{ __('application.customers_035eae60') }}"
        description="{{ __('application.maintain_cash_credit_contractor_and_wholesale_customer_records_fa002441') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Customers' => null]"
    >
        <x-export-actions export="tables.customers" :params="compact('search', 'statusFilter', 'branchFilter', 'typeFilter', 'created_from', 'created_to')" />
        @if ($this->canManage())
            <a href="{{ route('customers.create') }}" wire:navigate data-tour="add-customer" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_customer_2bd83aec') }}</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_customers_8159bd4e') }}">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="active">{{ __('application.active_a733b809') }}</option>
                <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
            </select>
            <select wire:model.live="typeFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_types_30c8a0fc') }}</option>
                <option value="cash">{{ __('application.cash_758ec54e') }}</option>
                <option value="credit">{{ __('application.credit_8a45d339') }}</option>
                <option value="contractor">{{ __('application.contractor_76bb2328') }}</option>
                <option value="wholesale">{{ __('application.wholesale_21c1aef4') }}</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                <option value="">{{ __('application.all_branches_25c23746') }}</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $customers = Customer::query()
                ->with('branch')
                ->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($typeFilter, fn ($query) => $query->where('customer_type', $typeFilter))
                ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                ->when($created_from, fn ($query) => $query->whereDate('created_at', '>=', $created_from))
                ->when($created_to, fn ($query) => $query->whereDate('created_at', '<=', $created_to))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table data-tour="customers-list" :headers="[__('application.customer_0e85749a'), __('application.phone_77064d52'), __('application.type_3deb7456'), __('application.branch_1627510b'), __('application.opening_balance_1a1a6305'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($customers as $customer)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $customer->displayName() }}</p><p class="text-xs text-slate-500">{{ $customer->email ?? '-' }}</p></td>
                    <td class="px-4 py-3">{{ $customer->phone }}</td>
                    <td class="px-4 py-3 capitalize">{{ \App\Support\UiText::label($customer->customer_type) }}</td>
                    <td class="px-4 py-3">{{ $customer->branch?->name ?? __('terminology.global_5f1184f7') }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($customer->opening_balance) }}</td>
                    <td class="px-4 py-3"><span class="{{ $customer->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($customer->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('customers.edit', $customer) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                                <button wire:click="toggleStatus({{ $customer->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $customer->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                <button wire:click="deleteCustomer({{ $customer->id }})" wire:confirm="{{ __('application.delete_this_customer_02a7de5d') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_customers_found_99ff0325') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $customers->links() }}</div>
    </x-card>
</div>
