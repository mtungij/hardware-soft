<?php

use App\Models\CustomerDeposit;
use Livewire\WithPagination;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithPagination::class]);

$deposits = computed(fn () => CustomerDeposit::where('customer_account_id', auth('customer')->id())->latest()->paginate(10));

?>

<div>
    <x-page-header title="My Deposits" description="Track advance deposits and balances." :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Deposits' => null]">
        <a href="{{ route('customer.deposits.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">Upload Deposit</a>
    </x-page-header>
    <x-card>
        <x-table :headers="['Date', 'Reference', 'Method', 'Amount', 'Used', 'Balance', 'Status', 'File']">
            @forelse ($this->deposits as $deposit)
                <tr>
                    <td class="px-4 py-3">{{ $deposit->created_at->format('M d, Y') }}</td>
                    <td class="px-4 py-3">{{ $deposit->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ str($deposit->payment_method)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $deposit->amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $deposit->used_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $deposit->balance_amount, 2) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ in_array($deposit->status, ['approved', 'partial']) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($deposit->status === 'rejected' ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200') }}">{{ str($deposit->status)->title() }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('customer.deposits.download', $deposit) }}" class="text-sm font-black text-build-orange">Download</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">No deposits uploaded yet.</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $this->deposits->links() }}</div>
    </x-card>
</div>
