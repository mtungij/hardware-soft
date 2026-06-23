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

    session()->flash('success', 'Deposit uploaded and waiting for admin approval.');
    $this->redirectRoute('customer.deposits.index', navigate: true);
};

?>

<div>
    <x-page-header title="Upload Deposit" description="Submit advance payment proof for approval." :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Deposits' => route('customer.deposits.index'), 'Upload' => null]" />
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <x-form-input label="Amount" name="amount" wire:model="amount" type="number" step="0.01" required />
            <label class="block text-sm font-bold">Payment Method
                <select wire:model="payment_method" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="mobile_money">Mobile Money</option><option value="bank">Bank</option><option value="cash_deposit">Cash Deposit</option>
                </select>
            </label>
            <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" />
            <label class="block text-sm font-bold">Deposit Receipt Image/PDF
                <input wire:model="receipt_file" type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            </label>
            @error('receipt_file') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            <label class="block text-sm font-bold">Notes
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white" wire:loading.attr="disabled"><span wire:loading.remove>Submit Deposit</span><span wire:loading>Uploading...</span></button>
        </form>
    </x-card>
</div>
