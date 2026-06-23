<?php

use App\Models\CustomerReceipt;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

$receipts = computed(fn () => CustomerReceipt::with('sale')->where('customer_account_id', auth('customer')->id())->latest()->paginate(10));

?>

<div>
    <x-page-header title="My Receipts" description="Uploaded debt payment receipts." :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Receipts' => null]">
        <a href="{{ route('customer.receipts.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">Upload Receipt</a>
    </x-page-header>
    <x-card>
        <x-table :headers="['Date', 'Sale', 'Reference', 'Method', 'Amount', 'Status', 'File']">
            @forelse ($this->receipts as $receipt)
                <tr>
                    <td class="px-4 py-3">{{ $receipt->created_at->format('M d, Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $receipt->sale?->sale_number ?: 'General payment' }}</td>
                    <td class="px-4 py-3">{{ $receipt->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ str($receipt->payment_method)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $receipt->amount, 2) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $receipt->status === 'approved' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($receipt->status === 'rejected' ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200') }}">{{ str($receipt->status)->title() }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('customer.receipts.download', $receipt) }}" class="text-sm font-black text-build-orange">Download</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No receipts uploaded yet.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->receipts->links() }}</div>
    </x-card>
</div>
