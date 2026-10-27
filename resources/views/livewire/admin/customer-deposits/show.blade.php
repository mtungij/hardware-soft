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
        throw ValidationException::withMessages(['deposit' => __('application.only_pending_deposits_can_be_approved_09d3c943')]);
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
        'title' => __('portal_notifications.deposit_approved_title'),
        'message' => __('portal_notifications.deposit_approved_message', ['amount' => \App\Support\NumberFormatter::money($this->deposit->amount)]),
        'notifiable_type' => CustomerDeposit::class,
        'notifiable_id' => $this->deposit->id,
    ]);
    $this->deposit->refresh();
    session()->flash('success', __('application.deposit_approved_and_balance_made_available_5866ac36'));
};

$reject = function () {
    $this->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
    $this->deposit->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => $this->rejection_reason]);
    CustomerNotification::create([
        'customer_account_id' => $this->deposit->customer_account_id,
        'customer_id' => $this->deposit->customer_id,
        'type' => 'deposit_rejected',
        'title' => __('portal_notifications.deposit_rejected_title'),
        'message' => __('portal_notifications.deposit_rejected_message', ['reason' => $this->rejection_reason]),
        'notifiable_type' => CustomerDeposit::class,
        'notifiable_id' => $this->deposit->id,
    ]);
    $this->deposit->refresh();
    session()->flash('success', __('application.deposit_rejected_604ddd31'));
};

?>

<div>
    <x-page-header title="{{ __('application.review_customer_deposit_ea15395b') }}" :description="$deposit->customer?->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Deposits' => route('admin.customer-deposits.index'), 'Review' => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="{{ __('application.deposit_details_e76fa09e') }}" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.customer_0e85749a') }}</dt><dd class="font-bold">{{ $deposit->customer?->displayName() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.amount_43dc8532') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($deposit->amount) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.balance_90eef613') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($deposit->balance_amount) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.method_88306943') }}</dt><dd class="font-bold">{{ $deposit->paymentMethodLabel() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.reference_db1c7845') }}</dt><dd class="font-bold">{{ $deposit->reference_number ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.status_bae7d5be') }}</dt><dd class="font-bold">{{ \App\Support\UiText::label($deposit->status) }}</dd></div>
            </dl>
            <a href="{{ route('admin.customer-deposits.download', $deposit) }}" class="mt-6 inline-flex rounded-xl border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">{{ __('application.download_attachment_8752a8dc') }}</a>
        </x-card>
        <x-card title="{{ __('application.approval_8cc047ac') }}">
            @if ($deposit->status === 'pending')
                <button wire:click="approve" wire:confirm="{{ __('application.approve_this_deposit_247d495f') }}" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">{{ __('application.approve_deposit_4f90d7bb') }}</button>
                <div class="mt-4">
                    <label class="block text-sm font-bold">{{ __('application.rejection_reason_24561f1b') }}
                        <textarea wire:model="rejection_reason" rows="4" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    @error('rejection_reason') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    <button wire:click="reject" wire:confirm="{{ __('application.reject_this_deposit_004f7d09') }}" class="mt-3 w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white">{{ __('application.reject_deposit_0689c044') }}</button>
                </div>
            @else
                <p class="text-sm font-semibold text-slate-500">{{ __('application.reviewed_by_7cd6a31f') }} {{ $deposit->approvedBy?->name ?? $deposit->rejectedBy?->name ?? '-' }}.</p>
                @if ($deposit->rejection_reason)<p class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $deposit->rejection_reason }}</p>@endif
            @endif
        </x-card>
    </div>
</div>
