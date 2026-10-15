<?php

use App\Models\CustomerDeposit;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['status' => 'pending', 'search' => '']);

$deposits = computed(function () {
    return CustomerDeposit::with(['customer', 'account'])
        ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))->orWhere('reference_number', 'like', '%'.$this->search.'%')))
        ->latest()
        ->paginate(12);
});

?>

<div>
    <x-page-header title="{{ __('application.customer_deposits_2cddaa3d') }}" description="{{ __('application.approve_advance_deposit_receipts_93f07412') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Deposits' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('application.search_customer_reference_56d6de1b') }}">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="pending">{{ __('application.pending_96f608c1') }}</option><option value="approved">{{ __('application.approved_41b81eb8') }}</option><option value="partial">{{ __('application.partial_65de2e2a') }}</option><option value="used">{{ __('application.used_02c0e4a1') }}</option><option value="rejected">{{ __('application.rejected_27eeb7a2') }}</option><option value="all">{{ __('application.all_6a720856') }}</option>
            </select>
        </div>
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.customer_0e85749a'), __('application.reference_db1c7845'), __('application.amount_43dc8532'), __('application.balance_90eef613'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($this->deposits as $deposit)
                <tr>
                    <td class="px-4 py-3">{{ $deposit->created_at->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $deposit->customer?->displayName() }}</td>
                    <td class="px-4 py-3">{{ $deposit->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($deposit->amount) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($deposit->balance_amount) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ in_array($deposit->status, ['approved', 'partial']) ? 'bg-emerald-100 text-emerald-700' : ($deposit->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ \App\Support\UiText::label($deposit->status) }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('admin.customer-deposits.show', $deposit) }}" wire:navigate class="text-sm font-black text-build-orange">{{ __('application.review_e29a79fe') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_deposits_found_40c3c7a4') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->deposits->links() }}</div>
    </x-card>
</div>
