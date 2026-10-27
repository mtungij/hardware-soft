<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'date_from' => '',
    'date_to' => '',
    'customer_id' => '',
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'payment_method' => '',
    'received_by' => '',
]);

mount(function () {
    $this->date_from = request('date_from', today()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
    $this->customer_id = request('customer_id', '');
    $this->branch_id = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branch_id', '') ? (int) request('branch_id', '') : null) ?? '');
    $this->payment_method = request('payment_method', '');
    $this->received_by = request('received_by', '');
});

?>

<div>
    @php
        $money = fn ($value) => 'TZS '.\App\Support\NumberFormatter::money($value);
        $methodLabel = fn ($method) => $method;
        $query = CustomerPayment::query()
            ->with(['customer', 'branch', 'receivedBy'])
            ->when($date_from, fn ($q) => $q->whereDate('payment_date', '>=', $date_from))
            ->when($date_to, fn ($q) => $q->whereDate('payment_date', '<=', $date_to))
            ->when($customer_id, fn ($q) => $q->where('customer_id', $customer_id))
            ->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))
            ->when($payment_method, fn ($q) => \App\Services\PaymentMethodService::filter($q, $payment_method))
            ->when($received_by, fn ($q) => $q->where('received_by', $received_by));
        $payments = (clone $query)->latest('payment_date')->latest()->paginate(25);
        $totalsByMethod = (clone $query)->get()->groupBy(fn ($payment) => $payment->paymentMethodLabel())->map(fn ($group) => $group->sum('amount'));
        $methods = $totalsByMethod->keys()->all();
        $grandTotal = (clone $query)->sum('amount');
        $dailyGroups = (clone $query)->oldest('payment_date')->get()->groupBy(fn ($payment) => $payment->payment_date?->format('Y-m-d'));
        $exportParams = compact('date_from', 'date_to', 'customer_id', 'branch_id', 'payment_method', 'received_by');
    @endphp

    <x-page-header title="{{ __('application.customer_payment_report_1fa3b1d2') }}" description="{{ __('application.daily_customer_payments_by_method_customer_branch_and_cashier_4bd16efa') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Customer Payments' => null]">
        <x-export-actions export="reports.customer-payments" :params="$exportParams" />
    </x-page-header>

    <x-card>
        <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <input wire:model.live="date_from" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <input wire:model.live="date_to" type="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
            <select wire:model.live="customer_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_customers_872f2c2f') }}</option>@foreach (Customer::where(fn ($q) => $q->where('is_system_customer', false)->orWhereNull('is_system_customer'))->orderBy('name')->get() as $customer)<option value="{{ $customer->id }}">{{ $customer->displayName() }}</option>@endforeach</select>
            <select wire:model.live="branch_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_0bf51d9a') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="payment_method" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_methods_06a3fcd1') }}</option><x-payment-method-options :history="true" :branch-id="$branch_id ?: null" /></select>
            <select wire:model.live="received_by" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.all_received_by_9b674ea0') }}</option>@foreach (User::orderBy('name')->get() as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
        </div>
    </x-card>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($methods as $method)
            <x-card><p class="text-sm text-slate-500">{{ $methodLabel($method) }}</p><p class="mt-2 text-xl font-black">{{ $money($totalsByMethod[$method] ?? 0) }}</p></x-card>
        @endforeach
        <x-card><p class="text-sm text-slate-500">{{ __('application.total_payments_53b493df') }}</p><p class="mt-2 text-xl font-black text-emerald-600">{{ $money($grandTotal) }}</p></x-card>
    </div>

    <x-card class="mt-4" title="{{ __('application.daily_summary_d9fab1c7') }}">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($dailyGroups as $date => $rows)
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                    <p class="font-black">{{ \Illuminate\Support\Carbon::parse($date)->locale(app()->getLocale())->translatedFormat('d F Y') }}</p>
                    @foreach ($methods as $method)
                        <div class="mt-1 flex justify-between text-sm"><span>{{ $methodLabel($method) }}</span><span class="font-bold">{{ $money($rows->filter(fn ($payment) => $payment->paymentMethodLabel() === $method)->sum('amount')) }}</span></div>
                    @endforeach
                    <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-sm font-black dark:border-slate-700"><span>{{ __('application.total_b25928c6') }}</span><span>{{ $money($rows->sum('amount')) }}</span></div>
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ __('application.no_payments_found_69d803dd') }}</p>
            @endforelse
        </div>
    </x-card>

    <x-card class="mt-4" title="{{ __('application.payment_details_b8579d99') }}">
        <x-table :headers="[__('application.date_eb9a4bc1'), __('application.time_6c82e6dd'), __('application.receipt_number_37553f8e'), __('application.customer_name_75636316'), __('application.phone_77064d52'), __('application.amount_paid_a2dc17b5'), __('application.payment_method_f383f6a2'), __('application.reference_db1c7845'), __('application.branch_1627510b'), __('application.received_by_f84f73c6'), __('application.notes_70440046')]">
            @forelse ($payments as $payment)
                <tr>
                    <td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3">{{ $payment->created_at?->format('H:i') }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $payment->receipt_number ?: 'PAY-'.$payment->id }}</td>
                    <td class="px-4 py-3 font-bold">{{ $payment->customer?->displayName() }}</td>
                    <td class="px-4 py-3">{{ $payment->customer?->phone ?: '-' }}</td>
                    <td class="px-4 py-3 text-right font-black">{{ $money($payment->amount) }}</td>
                    <td class="px-4 py-3">{{ $payment->paymentMethodLabel() }}</td>
                    <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $payment->branch?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $payment->receivedBy?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $payment->notes ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_payments_found_69d803dd') }}</td></tr>
            @endforelse
        </x-table>
        <div class="mt-4">{{ $payments->links() }}</div>
    </x-card>
</div>
