<?php

use App\Models\Sale;
use App\Support\AuthorizationScope;

use function Livewire\Volt\hydrate;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null])->locked();

hydrate(function () {
    AuthorizationScope::authorizeSale(auth()->user(), $this->sale);
});

mount(function (Sale $sale) {
    AuthorizationScope::authorizeSale(auth()->user(), $sale);
    $this->sale = $sale->load(['branch', 'customer', 'createdBy', 'cancelledBy', 'items.product', 'items.stockLocation', 'items.sellingUnit', 'items.baseUnit', 'payments.receivedBy']);
});

?>

<div>
    <x-page-header title="{{ __('application.sale_details_be513b66') }}" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => null]">
        <div class="flex gap-2">
            <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.receipt_f6ce3b6f') }}</a>
            @if ($sale->status === 'completed' && (float) $sale->balance_amount > 0)
                <a href="{{ route('sales.payments', $sale) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">{{ __('application.receive_payment_09fd7056') }}</a>
            @endif
        </div>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="{{ __('application.sale_summary_78a5293a') }}" class="lg:col-span-1">
            <div class="space-y-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.date_eb9a4bc1') }}</span><span class="font-bold">{{ $sale->sale_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.customer_0e85749a') }}</span><span class="font-bold">{{ $sale->customer?->displayName() ?? __('application.walk_in_customer_90cd7712') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.sale_type_76caf1bd') }}</span><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->saleType() === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ $sale->saleTypeLabel() }}</span></div>
                @if ($sale->discount_mode)
                    <div class="flex justify-between"><span class="text-slate-500">{{ __('application.discount_mode_e6ee7042') }}</span><span class="font-bold">{{ $sale->discount_mode === 'order' ? __('feedback.whole_sale') : __('feedback.item_discount') }}</span></div>
                @endif
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.branch_1627510b') }}</span><span class="font-bold">{{ $sale->branch?->name }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.cashier_78c07270') }}</span><span class="font-bold">{{ $sale->createdBy?->name }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('application.status_bae7d5be') }}</span><span class="font-bold">{{ \App\Support\UiText::status($sale->status) }} / {{ \App\Support\UiText::status($sale->payment_status) }}</span></div>
                @if ($sale->cancelled_at)
                    <div class="rounded-lg bg-red-50 p-3 text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ __('application.cancelled_by_dc63e775') }} {{ $sale->cancelledBy?->name }} {{ __('application.on_db3d405b') }} {{ $sale->cancelled_at->locale(app()->getLocale())->translatedFormat('M d, Y H:i') }}</div>
                @endif
            </div>
        </x-card>

        <x-card title="{{ __('application.totals_65222d79') }}" class="lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-5">
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.subtotal_97f7359e') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->subtotal) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.discount_b524936d') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.tax_9be70f66') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.paid_dc9d4584') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</p></div>
                <div class="rounded-lg bg-navy-900 p-4 text-white"><p class="text-xs text-slate-300">{{ __('application.balance_90eef613') }}</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</p></div>
            </div>
        </x-card>
    </div>

    <x-card title="{{ __('application.items_44d25b5d') }}" class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('application.product_dd3b86d1') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('application.stock_source_29f5a910') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('application.sale_type_76caf1bd') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.qty_1e5ff9e5') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.base_qty_ec4bdf4d') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.conversion_9151f843') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.price_3e8248e3') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.discount_unit_b22d0bb4') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.net_unit_91c98ef4') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.line_discount_ee15ec35') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.tax_9be70f66') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.total_b25928c6') }}</th>
                </tr>
            </x-slot:head>
            @foreach ($sale->items as $item)
                @php
                    $discountPerUnit = (float) ($item->discount_per_unit ?? 0);
                    $netUnitPrice = (float) ($item->net_unit_price ?? ((float) $item->unit_price - $discountPerUnit));
                    $lineDiscount = (float) ($item->discount_total ?? $item->discount_amount);
                @endphp
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-bold">
                        {{ $item->product?->displayName() }}
                        @if ($item->sizeLabel())
                            <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ __('application.size_86251551') }} {{ $item->sizeLabel() }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $item->sold_from_label ?: ($item->stockLocation ? \App\Support\InventorySettings::stockLocationLabel($item->stockLocation) : '-') }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $item->sale_type === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ \App\Support\UiText::label($item->sale_type ?? 'retail') }}</span></td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }} {{ $item->selling_unit_code_snapshot ?: $item->sellingUnit?->short_name }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->base_quantity ?: $item->quantity) }} {{ $item->base_unit_code_snapshot ?: $item->baseUnit?->short_name }}</td>
                    <td class="px-4 py-3 text-right">
                        @if ($item->conversion_factor_to_base !== null)
                            1 {{ $item->selling_unit_code_snapshot ?: $item->sellingUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->conversion_factor_to_base ?: 1) }} {{ $item->base_unit_code_snapshot ?: $item->baseUnit?->short_name }}
                        @else
                            1 {{ $item->baseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->conversion_factor ?: 1) }} {{ $item->sellingUnit?->short_name }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($discountPerUnit) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($netUnitPrice) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($lineDiscount) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($item->tax_amount) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="{{ __('application.payments_44357ae5') }}" class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('application.date_eb9a4bc1') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('application.method_88306943') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('application.reference_db1c7845') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('application.received_by_f84f73c6') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('application.amount_43dc8532') }}</th>
                </tr>
            </x-slot:head>
            @foreach ($sale->payments as $payment)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">{{ $payment->payment_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td>
                    <td class="px-4 py-3">{{ \App\Support\UiText::label($payment->payment_method) }}</td>
                    <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $payment->receivedBy?->name }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
