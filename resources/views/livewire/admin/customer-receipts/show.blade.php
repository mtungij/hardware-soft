<?php

use App\Models\CustomerReceipt;
use App\Models\CustomerNotification;
use App\Services\AccountingService;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['receipt' => null, 'rejection_reason' => '']);

mount(function (CustomerReceipt $customerReceipt) {
    $this->receipt = $customerReceipt->load(['customer', 'sale', 'account', 'approvedBy', 'rejectedBy']);
});

$approve = function (AccountingService $accounting) {
    if ($this->receipt->status !== 'pending') {
        throw ValidationException::withMessages(['receipt' => 'Only pending receipts can be approved.']);
    }

    $payment = $accounting->receiveCustomerPayment($this->receipt->customer, [
        'branch_id' => $this->receipt->branch_id ?: $this->receipt->customer->branch_id,
        'amount' => (float) $this->receipt->amount,
        'payment_method' => $this->receipt->payment_method === 'cash_deposit' ? 'cash' : $this->receipt->payment_method,
        'reference_number' => $this->receipt->reference_number,
        'payment_date' => now()->toDateString(),
        'notes' => 'Approved from customer portal receipt #'.$this->receipt->id,
    ], auth()->id());

    $this->receipt->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'customer_payment_id' => $payment->id]);
    CustomerNotification::create([
        'customer_account_id' => $this->receipt->customer_account_id,
        'customer_id' => $this->receipt->customer_id,
        'type' => 'receipt_approved',
        'title' => 'Receipt Approved',
        'message' => 'Your payment receipt for TZS '.number_format((float) $this->receipt->amount, 2).' has been approved.',
        'notifiable_type' => CustomerReceipt::class,
        'notifiable_id' => $this->receipt->id,
    ]);
    $this->receipt->refresh();
    session()->flash('success', 'Receipt approved and customer payment recorded.');
};

$reject = function () {
    $this->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
    $this->receipt->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => $this->rejection_reason]);
    CustomerNotification::create([
        'customer_account_id' => $this->receipt->customer_account_id,
        'customer_id' => $this->receipt->customer_id,
        'type' => 'receipt_rejected',
        'title' => 'Receipt Rejected',
        'message' => 'Your payment receipt was rejected. Reason: '.$this->rejection_reason,
        'notifiable_type' => CustomerReceipt::class,
        'notifiable_id' => $this->receipt->id,
    ]);
    $this->receipt->refresh();
    session()->flash('success', 'Receipt rejected.');
};

?>

<div>
    <x-page-header title="Review Customer Receipt" :description="$receipt->customer?->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Receipts' => route('admin.customer-receipts.index'), 'Review' => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="Receipt Details" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">Customer</dt><dd class="font-bold">{{ $receipt->customer?->name }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Sale</dt><dd class="font-bold">{{ $receipt->sale?->sale_number ?: 'General payment' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Amount</dt><dd class="font-bold">TZS {{ number_format((float) $receipt->amount, 2) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Method</dt><dd class="font-bold">{{ str($receipt->payment_method)->replace('_', ' ')->title() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Reference</dt><dd class="font-bold">{{ $receipt->reference_number ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">Status</dt><dd class="font-bold">{{ str($receipt->status)->title() }}</dd></div>
            </dl>
            <a href="{{ route('admin.customer-receipts.download', $receipt) }}" class="mt-6 inline-flex rounded-xl border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Download Attachment</a>
        </x-card>
        <x-card title="Approval">
            @if ($receipt->status === 'pending')
                <button wire:click="approve" wire:confirm="Approve this receipt and record customer payment?" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">Approve Receipt</button>
                <div class="mt-4">
                    <label class="block text-sm font-bold">Rejection Reason
                        <textarea wire:model="rejection_reason" rows="4" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    @error('rejection_reason') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    <button wire:click="reject" wire:confirm="Reject this receipt?" class="mt-3 w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white">Reject Receipt</button>
                </div>
            @else
                <p class="text-sm font-semibold text-slate-500">Reviewed by {{ $receipt->approvedBy?->name ?? $receipt->rejectedBy?->name ?? '-' }}.</p>
                @if ($receipt->rejection_reason)<p class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $receipt->rejection_reason }}</p>@endif
            @endif
        </x-card>
    </div>
</div>
