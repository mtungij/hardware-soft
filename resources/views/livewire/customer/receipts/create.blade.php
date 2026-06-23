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
        throw ValidationException::withMessages(['amount' => __('messages.receipts.too_much')]);
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

    session()->flash('success', __('messages.receipts.uploaded'));
    $this->redirectRoute('customer.receipts.index', navigate: true);
};

?>

<div>
    <x-page-header :title="__('messages.receipts.upload')" :description="__('messages.receipts.upload_description')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.receipts.title') => route('customer.receipts.index'), __('messages.actions.upload') => null]" />
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <label class="block text-sm font-bold">{{ __('messages.receipts.invoice') }}
                <select wire:model="sale_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('messages.receipts.general_payment') }}</option>
                    @foreach ($this->sales as $sale)
                        <option value="{{ $sale->id }}">{{ $sale->sale_number }} - {{ __('messages.debts.balance') }} TZS {{ number_format((float) $sale->balance_amount, 2) }}</option>
                    @endforeach
                </select>
            </label>
            <x-form-input :label="__('messages.receipts.amount')" name="amount" wire:model="amount" type="number" step="0.01" required />
            <label class="block text-sm font-bold">{{ __('messages.receipts.payment_method') }}
                <select wire:model="payment_method" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="mobile_money">{{ __('messages.methods.mobile_money') }}</option><option value="bank">{{ __('messages.methods.bank') }}</option><option value="cash_deposit">{{ __('messages.methods.cash_deposit') }}</option>
                </select>
            </label>
            <x-form-input :label="__('messages.receipts.reference_number')" name="reference_number" wire:model="reference_number" />
            <label class="block text-sm font-bold">{{ __('messages.receipts.receipt_file') }}
                <input wire:model="receipt_file" type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            </label>
            @error('receipt_file') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            <label class="block text-sm font-bold">{{ __('messages.receipts.notes') }}
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white" wire:loading.attr="disabled"><span wire:loading.remove>{{ __('messages.receipts.submit') }}</span><span wire:loading>{{ __('messages.receipts.uploading') }}</span></button>
        </form>
    </x-card>
</div>
