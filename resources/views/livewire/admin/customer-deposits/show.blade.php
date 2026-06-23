<?php

use App\Models\CustomerDeposit;
use App\Models\CustomerNotification;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['deposit' => null, 'rejection_reason' => '']);

mount(function (CustomerDeposit $customerDeposit) {
    $this->deposit = $customerDeposit->load(['customer', 'account', 'approvedBy', 'rejectedBy']);
});

$approve = function () {
    if ($this->deposit->status !== 'pending') {
        throw ValidationException::withMessages(['deposit' => 'Only pending deposits can be approved.']);
    }

    $this->deposit->update([
        'status' => 'approved',
        'balance_amount' => $this->deposit->amount,
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);
    CustomerNotification::create([
        'customer_account_id' => $this->deposit->customer_account_id,
        'customer_id' => $this->deposit->customer_id,
        'type' => 'deposit_approved',
        'title' => 'Deposit Approved',
        'message' => 'Your deposit of TZS '.number_format((float) $this->deposit->amount, 2).' has been approved.',
        'notifiable_type' => CustomerDeposit::class,
        'notifiable_id' => $this->deposit->id,
    ]);
    $this->deposit->refresh();
    session()->flash('success', 'Deposit approved and balance made available.');
};

$reject = function () {
    $this->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
    $this->deposit->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => $this->rejection_reason]);
    CustomerNotification::create([
        'customer_account_id' => $this->deposit->customer_account_id,
        'customer_id' => $this->deposit->customer_id,
        'type' => 'deposit_rejected',
        'title' => 'Deposit Rejected',
        'message' => 'Your deposit was rejected. Reason: '.$this->rejection_reason,
        'notifiable_type' => CustomerDeposit::class,
        'notifiable_id' => $this->deposit->id,
    ]);
    $this->deposit->refresh();
    session()->flash('success', 'Deposit rejected.');
};

?>

<div>
    <x-page-header title="Review Customer Deposit" :description="$deposit->customer?->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Deposits' => route('admin.customer-deposits.index'), 'Review' => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="Deposit Details" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">Customer</dt><dd class="font-bold">{{ $deposit->customer?->name }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Amount</dt><dd class="font-bold">TZS {{ number_format((float) $deposit->amount, 2) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Balance</dt><dd class="font-bold">TZS {{ number_format((float) $deposit->balance_amount, 2) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Method</dt><dd class="font-bold">{{ str($deposit->payment_method)->replace('_', ' ')->title() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Reference</dt><dd class="font-bold">{{ $deposit->reference_number ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Status</dt><dd class="font-bold">{{ str($deposit->status)->title() }}</dd></div>
            </dl>
            <a href="{{ route('admin.customer-deposits.download', $deposit) }}" class="mt-6 inline-flex rounded-xl border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Download Attachment</a>
        </x-card>
        <x-card title="Approval">
            @if ($deposit->status === 'pending')
                <button wire:click="approve" wire:confirm="Approve this deposit?" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">Approve Deposit</button>
                <div class="mt-4">
                    <label class="block text-sm font-bold">Rejection Reason
                        <textarea wire:model="rejection_reason" rows="4" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    @error('rejection_reason') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    <button wire:click="reject" wire:confirm="Reject this deposit?" class="mt-3 w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white">Reject Deposit</button>
                </div>
            @else
                <p class="text-sm font-semibold text-slate-500">Reviewed by {{ $deposit->approvedBy?->name ?? $deposit->rejectedBy?->name ?? '-' }}.</p>
                @if ($deposit->rejection_reason)<p class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $deposit->rejection_reason }}</p>@endif
            @endif
        </x-card>
    </div>
</div>
