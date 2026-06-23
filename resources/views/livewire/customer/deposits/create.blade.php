<?php

use App\Models\CustomerDeposit;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithFileUploads::class]);

state(['amount' => '', 'payment_method' => 'mobile_money', 'reference_number' => '', 'receipt_file' => null, 'notes' => '']);

rules(fn () => [
    'amount' => ['required', 'numeric', 'gt:0'],
    'payment_method' => ['required', 'in:mobile_money,bank,cash_deposit'],
    'reference_number' => ['nullable', 'string', 'max:255', Rule::unique('customer_deposits', 'reference_number')->where('customer_id', auth('customer')->user()->customer_id)],
    'receipt_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

$save = function () {
    $data = $this->validate();
    $account = auth('customer')->user()->load('customer');
    $path = $this->receipt_file->store('customer-deposits', 'local');

    CustomerDeposit::create([
        'customer_account_id' => $account->id,
        'customer_id' => $account->customer_id,
        'branch_id' => $account->customer?->branch_id,
        'amount' => $data['amount'],
        'used_amount' => 0,
        'balance_amount' => 0,
        'payment_method' => $data['payment_method'],
        'reference_number' => $data['reference_number'] ?: null,
        'receipt_file' => $path,
        'notes' => $data['notes'] ?: null,
        'status' => 'pending',
    ]);

    session()->flash('success', __('messages.deposits.uploaded'));
    $this->redirectRoute('customer.deposits.index', navigate: true);
};

?>

<div>
    <x-page-header :title="__('messages.deposits.upload')" :description="__('messages.deposits.upload_description')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.deposits.title') => route('customer.deposits.index'), __('messages.actions.upload') => null]" />
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <x-form-input :label="__('messages.deposits.amount')" name="amount" wire:model="amount" type="number" step="0.01" required />
            <label class="block text-sm font-bold">{{ __('messages.deposits.payment_method') }}
                <select wire:model="payment_method" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="mobile_money">{{ __('messages.methods.mobile_money') }}</option><option value="bank">{{ __('messages.methods.bank') }}</option><option value="cash_deposit">{{ __('messages.methods.cash_deposit') }}</option>
                </select>
            </label>
            <x-form-input :label="__('messages.deposits.reference_number')" name="reference_number" wire:model="reference_number" />
            <label class="block text-sm font-bold">{{ __('messages.deposits.receipt_file') }}
                <input wire:model="receipt_file" type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            </label>
            @error('receipt_file') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            <label class="block text-sm font-bold">{{ __('messages.deposits.notes') }}
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white" wire:loading.attr="disabled"><span wire:loading.remove>{{ __('messages.deposits.submit') }}</span><span wire:loading>{{ __('messages.deposits.uploading') }}</span></button>
        </form>
    </x-card>
</div>
