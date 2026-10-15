<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state(['customer_id' => '', 'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'amount' => '', 'payment_method' => 'cash', 'reference_number' => '', 'payment_date' => '', 'notes' => '']);

rules([
    'customer_id' => ['required', 'exists:customers,id'],
    'branch_id' => ['required', 'exists:branches,id'],
    'amount' => ['required', 'numeric', 'gt:0'],
    'payment_method' => ['required', 'in:cash,mobile_money,bank'],
    'reference_number' => ['nullable', 'string', 'max:255'],
    'payment_date' => ['required', 'date'],
    'notes' => ['nullable', 'string', 'max:1000'],
]);

mount(function () {
    $this->customer_id = (string) request('customer_id', '');
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->payment_date = now()->toDateString();
});

$save = function (AccountingService $accounting) {
    $data = $this->validate();
    $customer = Customer::findOrFail($data['customer_id']);
    $accounting->receiveCustomerPayment($customer, $data, auth()->id());
    session()->flash('success', __('application.customer_payment_recorded_d51e2842'));
    $this->redirectRoute('customer-balances.show', $customer, navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.record_customer_payment_fe50ce0d') }}" description="{{ __('application.reduce_customer_outstanding_balance_41c0469f') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), 'Payment' => null]" />
    <x-card class="max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <label class="block text-sm font-bold">{{ __('application.customer_0e85749a') }}
                <select wire:model.live="customer_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">{{ __('application.select_customer_6817176b') }}</option>@foreach (Customer::orderBy('name')->get() as $customer)<option value="{{ $customer->id }}">{{ $customer->displayName() }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm font-bold">{{ __('application.branch_1627510b') }}
                <select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            </label>
            <x-money-input label="{{ __('application.amount_43dc8532') }}" name="amount" wire:model="amount" required />
            <label class="block text-sm font-bold">{{ __('application.payment_method_f383f6a2') }}<select wire:model="payment_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="cash">{{ __('application.cash_758ec54e') }}</option><option value="mobile_money">{{ __('application.mobile_money_6a38d1e4') }}</option><option value="bank">{{ __('application.bank_9e89988c') }}</option></select></label>
            <x-form-input label="{{ __('application.reference_number_74195db5') }}" name="reference_number" wire:model="reference_number" />
            <x-form-input label="{{ __('application.payment_date_4b0bd4b9') }}" name="payment_date" wire:model="payment_date" type="date" required />
            <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.save_payment_1d44002e') }}</button>
        </form>
    </x-card>
</div>
