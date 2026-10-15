<?php

use App\Models\Customer;
use App\Services\AccountingService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '']);

?>

<div>
    <x-page-header title="{{ __('application.customer_balances_fb9ce69e') }}" description="{{ __('application.track_outstanding_balances_statements_and_receipts_32d3a521') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => null]">
        <a href="{{ route('reports.customer-payments') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.payments_report_5191649f') }}</a>
        <a href="{{ route('customer-payments.create') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.record_payment_6577ced3') }}</a>
    </x-page-header>

    @php
        $accounting = app(AccountingService::class);
        $customers = Customer::query()
            ->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
            ->withSum(['sales as total_credit_sales' => fn ($query) => $query->where('status', 'completed')], 'total_amount')
            ->withSum('payments as total_customer_payments', 'amount')
            ->withMax(['sales as last_credit_sale_date' => fn ($query) => $query->where('status', 'completed')], 'sale_date')
            ->withMax('payments as last_payment_date', 'payment_date')
            ->orderBy('name')
            ->paginate(12);
        $balanceCustomers = Customer::query()->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->get();
        $totalBalance = $balanceCustomers->sum(fn ($customer) => $accounting->customerBalance($customer));
        $customersWithBalance = $balanceCustomers->filter(fn ($customer) => $accounting->customerBalance($customer) > 0)->count();
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card><p class="text-sm text-slate-500">{{ __('application.outstanding_balance_bb9bc5a8') }}</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($totalBalance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.customers_with_balance_3371a00f') }}</p><p class="mt-2 text-2xl font-black">{{ $customersWithBalance }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('application.overdue_placeholder_5942dcc5') }}</p><p class="mt-2 text-2xl font-black text-amber-600">TZS {{ \App\Support\NumberFormatter::money(0) }}</p></x-card>
    </div>

    <x-card class="mt-6">
        <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_customers_1e014477') }}">
        <x-table :headers="[__('application.customer_name_75636316'), __('application.phone_77064d52'), __('terminology.total_credit_sales_2546c770'), __('application.total_payments_53b493df'), __('application.outstanding_balance_bb9bc5a8'), __('terminology.last_credit_sale_41e739ad'), __('terminology.last_payment_cec3d948'), __('application.actions_c3cd636a')]">
            @foreach ($customers as $customer)
                @php $balance = $accounting->customerBalance($customer); @endphp
                <tr>
                    <td class="px-4 py-3"><a href="{{ route('customer-balances.show', $customer) }}" wire:navigate class="font-bold text-build-orange">{{ $customer->displayName() }}</a></td>
                    <td class="px-4 py-3">{{ $customer->phone ?: '-' }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($customer->total_credit_sales) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($customer->total_customer_payments) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-red-600">TZS {{ \App\Support\NumberFormatter::money($balance) }}</td>
                    <td class="px-4 py-3">{{ $customer->last_credit_sale_date ? \Illuminate\Support\Carbon::parse($customer->last_credit_sale_date)->locale(app()->getLocale())->translatedFormat('M d, Y') : '-' }}</td>
                    <td class="px-4 py-3">{{ $customer->last_payment_date ? \Illuminate\Support\Carbon::parse($customer->last_payment_date)->locale(app()->getLocale())->translatedFormat('M d, Y') : '-' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a href="{{ route('customer-balances.show', $customer) }}" wire:navigate class="text-sm font-bold text-build-orange">{{ __('application.view_statement_12ff7aa3') }}</a>
                            <a href="{{ route('customer-balances.show', ['customer' => $customer, 'tab' => 'purchases']) }}" wire:navigate class="text-sm font-bold text-cyan-700 dark:text-cyan-300">{{ __('application.credit_purchases_4aab9091') }}</a>
                            <a href="{{ route('customer-payments.create', ['customer_id' => $customer->id]) }}" wire:navigate class="text-sm font-bold text-emerald-700 dark:text-emerald-300">{{ __('application.record_payment_6577ced3') }}</a>
                            <a href="{{ route('customer-balances.show', ['customer' => $customer, 'print' => 1]) }}" class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.print_statement_7f56d1b5') }}</a>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-4">{{ $customers->links() }}</div>
    </x-card>
</div>
