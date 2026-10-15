<?php

use App\Models\CustomerReceipt;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['status' => 'pending', 'search' => '']);

$receipts = computed(function () {
    return CustomerReceipt::with(['customer', 'sale', 'account'])
        ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
        ->when($this->search, fn ($query) => $query->where(fn ($q) => $q->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$this->search.'%'))->orWhere('reference_number', 'like', '%'.$this->search.'%')))
        ->latest()
        ->paginate(12);
});

?>

<div>
    <x-page-header title="{{ __('application.customer_receipts_310ee45e') }}" description="{{ __('application.review_uploaded_customer_debt_payment_receipts_6148c130') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Receipts' => null]" />
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="{{ __('application.search_customer_reference_56d6de1b') }}">
            <select wire:model.live="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="pending">{{ __('application.pending_96f608c1') }}</option><option value="approved">{{ __('application.approved_41b81eb8') }}</option><option value="rejected">{{ __('application.rejected_27eeb7a2') }}</option><option value="all">{{ __('application.all_6a720856') }}</option>
            </select>
        </div>
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.customer_0e85749a'), __('application.sale_0028d743'), __('application.reference_db1c7845'), __('application.amount_43dc8532'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($this->receipts as $receipt)
                <tr>
                    <td class="px-4 py-3">{{ $receipt->created_at->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $receipt->customer?->displayName() }}</td>
                    <td class="px-4 py-3">{{ $receipt->sale?->sale_number ?: __('terminology.general_9239ee2c') }}</td>
                    <td class="px-4 py-3">{{ $receipt->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($receipt->amount) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $receipt->status === 'approved' ? 'bg-emerald-100 text-emerald-700' : ($receipt->status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ \App\Support\UiText::label($receipt->status) }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('admin.customer-receipts.show', $receipt) }}" wire:navigate class="text-sm font-black text-build-orange">{{ __('application.review_e29a79fe') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('application.no_receipts_found_a619228e') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->receipts->links() }}</div>
    </x-card>
</div>
