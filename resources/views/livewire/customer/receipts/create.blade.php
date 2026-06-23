<?php

use App\Models\CustomerReceipt;
use App\Models\Sale;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.customer');
uses([WithFileUploads::class]);

state(['sale_id' => '', 'amount' => '', 'payment_method' => 'mobile_money', 'reference_number' => '', 'receipt_file' => null, 'notes' => '']);

rules(fn () => [
    'sale_id' => ['nullable', Rule::exists('sales', 'id')->where('customer_id', auth('customer')->user()->customer_id)],
    'amount' => ['required', 'numeric', 'gt:0'],
    'payment_method' => ['required', 'in:mobile_money,bank,cash_deposit'],
    'reference_number' => ['nullable', 'string', 'max:255', Rule::unique('customer_receipts', 'reference_number')->where('customer_id', auth('customer')->user()->customer_id)],
    'receipt_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

mount(function () {
    $this->sale_id = (string) request('sale_id', '');
});

$sales = computed(fn () => Sale::where('customer_id', auth('customer')->user()->customer_id)->where('status', 'completed')->where('balance_amount', '>', 0)->latest('sale_date')->get());

$save = function () {
    $data = $this->validate();
    $account = auth('customer')->user();
    $sale = $data['sale_id'] ? Sale::where('customer_id', $account->customer_id)->findOrFail($data['sale_id']) : null;

    if ($sale && (float) $data['amount'] > (float) $sale->balance_amount) {
        throw ValidationException::withMessages(['amount' => 'Receipt amount cannot exceed the selected sale balance.']);
    }

    $path = $this->receipt_file->store('customer-receipts', 'local');

    CustomerReceipt::create([
        'customer_account_id' => $account->id,
        'customer_id' => $account->customer_id,
        'sale_id' => $sale?->id,
        'branch_id' => $sale?->branch_id ?? $account->customer?->branch_id,
        'amount' => $data['amount'],
        'payment_method' => $data['payment_method'],
        'reference_number' => $data['reference_number'] ?: null,
        'receipt_file' => $path,
        'notes' => $data['notes'] ?: null,
        'status' => 'pending',
    ]);

    session()->flash('success', 'Receipt uploaded and waiting for admin approval.');
    $this->redirectRoute('customer.receipts.index', navigate: true);
};

?>

<div>
    <x-page-header title="Upload Payment Receipt" description="Submit proof of payment for approval." :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Receipts' => route('customer.receipts.index'), 'Upload' => null]" />
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <label class="block text-sm font-bold">Sale / Invoice
                <select wire:model="sale_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">General customer payment</option>
                    @foreach ($this->sales as $sale)
                        <option value="{{ $sale->id }}">{{ $sale->sale_number }} - Balance TZS {{ number_format((float) $sale->balance_amount, 2) }}</option>
                    @endforeach
                </select>
            </label>
            <x-form-input label="Amount" name="amount" wire:model="amount" type="number" step="0.01" required />
            <label class="block text-sm font-bold">Payment Method
                <select wire:model="payment_method" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="mobile_money">Mobile Money</option><option value="bank">Bank</option><option value="cash_deposit">Cash Deposit</option>
                </select>
            </label>
            <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" />
            <label class="block text-sm font-bold">Receipt Image/PDF
                <input wire:model="receipt_file" type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            </label>
            @error('receipt_file') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            <label class="block text-sm font-bold">Notes
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white" wire:loading.attr="disabled"><span wire:loading.remove>Submit Receipt</span><span wire:loading>Uploading...</span></button>
        </form>
    </x-card>
</div>
