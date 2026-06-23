<?php

use App\Models\CustomerReceipt;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

$receipts = computed(fn () => CustomerReceipt::with('sale')->where('customer_account_id', auth('customer')->id())->latest()->paginate(10));
$statusLabel = fn (string $status) => __("messages.status.$status") === "messages.status.$status" ? str($status)->replace('_', ' ')->title() : __("messages.status.$status");
$methodLabel = fn (string $method) => __("messages.methods.$method") === "messages.methods.$method" ? str($method)->replace('_', ' ')->title() : __("messages.methods.$method");

?>

<div>
    <x-page-header :title="__('messages.receipts.title')" :description="__('messages.receipts.description')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.receipts.title') => null]">
        <a href="{{ route('customer.receipts.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">{{ __('messages.receipts.upload') }}</a>
    </x-page-header>
    <x-card>
        <x-table :headers="[__('messages.table.date'), __('messages.debts.invoice_number'), __('messages.table.reference'), __('messages.table.method'), __('messages.table.amount'), __('messages.table.status'), __('messages.receipts.file')]">
            @forelse ($this->receipts as $receipt)
                <tr>
                    <td class="px-4 py-3">{{ $receipt->created_at->format('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $receipt->sale?->sale_number ?: __('messages.receipts.general_payment') }}</td>
                    <td class="px-4 py-3">{{ $receipt->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $this->methodLabel($receipt->payment_method) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $receipt->amount, 2) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $receipt->status === 'approved' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($receipt->status === 'rejected' ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200') }}">{{ $this->statusLabel($receipt->status) }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('customer.receipts.download', $receipt) }}" class="text-sm font-black text-build-orange">{{ __('messages.receipts.download') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">{{ __('messages.receipts.no_receipts') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->receipts->links() }}</div>
    </x-card>
</div>
