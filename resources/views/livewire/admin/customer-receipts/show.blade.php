<?php

use App\Models\CustomerReceipt;
use App\Models\CustomerNotification;
use App\Services\AccountingService;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['receipt' => null])->locked();
state(['rejection_reason' => '']);

mount(function (CustomerReceipt $customerReceipt) {
    $this->receipt = $customerReceipt->load(['customer', 'sale', 'account', 'approvedBy', 'rejectedBy']);
});

$approve = function (AccountingService $accounting) {
    abort_unless(auth()->user()->can('approve customer receipts'), 403);
    \Illuminate\Support\Facades\DB::transaction(function () use ($accounting) {
    $this->receipt = CustomerReceipt::query()->where('company_id', auth()->user()->company_id)->lockForUpdate()->findOrFail($this->receipt->id);
    if ($this->receipt->status !== 'pending') {
        throw ValidationException::withMessages(['receipt' => __('application.only_pending_receipts_can_be_approved_1fb83546')]);
    }

    $payment = $accounting->receiveCustomerPayment($this->receipt->customer, [
        'branch_id' => $this->receipt->branch_id ?: $this->receipt->customer->branch_id,
        'amount' => (float) $this->receipt->amount,
        'source_receipt_id' => $this->receipt->id,
        'payment_submission_key' => $this->receipt->payment_submission_key ?: substr(hash('sha256', 'receipt:'.$this->receipt->id), 0, 36),
        'payment_method' => $this->receipt->payment_method_id ? 'method:'.$this->receipt->payment_method_id : ($this->receipt->payment_method === 'cash_deposit' ? 'cash' : $this->receipt->payment_method),
        'reference_number' => $this->receipt->reference_number,
        'payment_date' => now()->toDateString(),
        'notes' => 'Approved from customer portal receipt #'.$this->receipt->id,
    ], auth()->id());

    $this->receipt->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'customer_payment_id' => $payment->id]);
    CustomerNotification::create([
        'customer_account_id' => $this->receipt->customer_account_id,
        'customer_id' => $this->receipt->customer_id,
        'type' => 'receipt_approved',
        'title' => __('portal_notifications.receipt_approved_title'),
        'message' => __('portal_notifications.receipt_approved_message', ['amount' => \App\Support\NumberFormatter::money($this->receipt->amount)]),
        'notifiable_type' => CustomerReceipt::class,
        'notifiable_id' => $this->receipt->id,
    ]);
    $this->receipt->refresh();
    });
    session()->flash('success', __('application.receipt_approved_and_customer_payment_recorded_cfbcb6cd'));
};

$reject = function () {
    $this->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
    $this->receipt->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => $this->rejection_reason]);
    CustomerNotification::create([
        'customer_account_id' => $this->receipt->customer_account_id,
        'customer_id' => $this->receipt->customer_id,
        'type' => 'receipt_rejected',
        'title' => __('portal_notifications.receipt_rejected_title'),
        'message' => __('portal_notifications.receipt_rejected_message', ['reason' => $this->rejection_reason]),
        'notifiable_type' => CustomerReceipt::class,
        'notifiable_id' => $this->receipt->id,
    ]);
    $this->receipt->refresh();
    session()->flash('success', __('application.receipt_rejected_876bfa1b'));
};

?>

<div>
    <x-page-header title="{{ __('application.review_customer_receipt_481f6b27') }}" :description="$receipt->customer?->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Receipts' => route('admin.customer-receipts.index'), 'Review' => null]" />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="{{ __('application.receipt_details_90ab6b88') }}" class="lg:col-span-2">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.customer_0e85749a') }}</dt><dd class="font-bold">{{ $receipt->customer?->displayName() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.sale_0028d743') }}</dt><dd class="font-bold">{{ $receipt->sale?->sale_number ?: __('terminology.general_payment_1ef4b5b6') }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.amount_43dc8532') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($receipt->amount) }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.method_88306943') }}</dt><dd class="font-bold">{{ $receipt->paymentMethodLabel() }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.reference_db1c7845') }}</dt><dd class="font-bold">{{ $receipt->reference_number ?: '-' }}</dd></div>
                <div><dt class="text-xs font-black uppercase text-slate-400">{{ __('application.status_bae7d5be') }}</dt><dd class="font-bold">{{ \App\Support\UiText::label($receipt->status) }}</dd></div>
            </dl>
            <a href="{{ route('admin.customer-receipts.download', $receipt) }}" class="mt-6 inline-flex rounded-xl border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">{{ __('application.download_attachment_8752a8dc') }}</a>
        </x-card>
        <x-card title="{{ __('application.approval_8cc047ac') }}">
            @if ($receipt->status === 'pending')
                <button wire:click="approve" wire:confirm="{{ __('application.approve_this_receipt_and_record_customer_payment_b2001057') }}" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">{{ __('application.approve_receipt_f201cd7e') }}</button>
                <div class="mt-4">
                    <label class="block text-sm font-bold">{{ __('application.rejection_reason_24561f1b') }}
                        <textarea wire:model="rejection_reason" rows="4" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    </label>
                    @error('rejection_reason') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    <button wire:click="reject" wire:confirm="{{ __('application.reject_this_receipt_ba0bf554') }}" class="mt-3 w-full rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white">{{ __('application.reject_receipt_576594a7') }}</button>
                </div>
            @else
                <p class="text-sm font-semibold text-slate-500">{{ __('application.reviewed_by_7cd6a31f') }} {{ $receipt->approvedBy?->name ?? $receipt->rejectedBy?->name ?? '-' }}.</p>
                @if ($receipt->rejection_reason)<p class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $receipt->rejection_reason }}</p>@endif
            @endif
        </x-card>
    </div>
</div>
