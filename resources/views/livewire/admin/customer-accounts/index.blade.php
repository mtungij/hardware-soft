<?php

use App\Models\CustomerAccount;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'status' => 'all']);

$accounts = computed(function () {
    return CustomerAccount::with(['customer.branch'])
        ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')->orWhere('phone', 'like', '%'.$this->search.'%')))
        ->latest()
        ->paginate(12);
});

$approve = function (CustomerAccount $account) {
    $account->update(['status' => 'active', 'approved_at' => now(), 'approved_by' => auth()->id()]);
    session()->flash('success', __('application.customer_account_approved_a47ac7ae'));
};

$suspend = function (CustomerAccount $account) {
    $account->update(['status' => 'suspended']);
    session()->flash('success', __('application.customer_account_suspended_7b29586b'));
};

?>

<div>
    <x-page-header title="{{ __('application.customer_accounts_4bb0aae9') }}" description="{{ __('application.approve_and_manage_customer_portal_accounts_6f380fb8') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Accounts' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('application.search_customers_1e014477') }}">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="all">{{ __('application.all_statuses_6405179d') }}</option><option value="pending">{{ __('application.pending_96f608c1') }}</option><option value="active">{{ __('application.active_a733b809') }}</option><option value="suspended">{{ __('application.suspended_794696a7') }}</option>
            </select>
        </div>
        <x-table :headers="[__('application.customer_0e85749a'), __('terminology.contact_b37456c4'), __('application.branch_1627510b'), __('application.status_bae7d5be'), __('application.created_accf40c8'), __('application.actions_c3cd636a')]">
            @forelse ($this->accounts as $account)
                <tr>
                    <td class="px-4 py-3"><p class="font-black">{{ $account->name }}</p><p class="text-xs text-slate-500">{{ $account->customer?->displayName() }}</p></td>
                    <td class="px-4 py-3">{{ $account->email }}<br><span class="text-xs text-slate-500">{{ $account->phone ?: '-' }}</span></td>
                    <td class="px-4 py-3">{{ $account->customer?->branch?->name ?: '-' }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-700' : ($account->status === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ \App\Support\UiText::label($account->status) }}</span></td>
                    <td class="px-4 py-3">{{ $account->created_at->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.customer-accounts.show', $account) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black dark:border-slate-700">{{ __('application.view_69bd4ef9') }}</a>
                            @if ($account->status !== 'active')<button wire:click="approve({{ $account->id }})" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-black text-white">{{ __('application.approve_7b2c7f14') }}</button>@endif
                            @if ($account->status !== 'suspended')<button wire:click="suspend({{ $account->id }})" wire:confirm="{{ __('application.suspend_this_account_482b3bf6') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-black text-white">{{ __('application.suspend_b24247fb') }}</button>@endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_customer_accounts_found_fdef1105') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->accounts->links() }}</div>
    </x-card>
</div>
