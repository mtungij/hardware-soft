<?php

use App\Models\Purchase;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['purchase' => null]);

mount(function (Purchase $purchase) {
    $this->purchase = $purchase->load(['supplier', 'branch', 'creator', 'receiver', 'emailSentBy', 'items.product', 'items.costBreakdown', 'items.purchaseUnit', 'items.stockUnit', 'goodsReceivingNotes.items.stockLocation', 'goodsReceivingNotes.items.product', 'goodsReceivingNotes.receiver', 'emailLogs.sentBy']);
});

$canSendEmail = fn () => auth()->user()->can('send purchase emails');
$canResendEmail = fn () => auth()->user()->can('resend purchase emails') || auth()->user()->can('send purchase emails');

$sendPurchaseOrder = function (PurchaseOrderEmailService $service) {
    abort_unless($this->purchase->email_status === 'sent' ? $this->canResendEmail() : $this->canSendEmail(), 403);

    try {
        $service->send($this->purchase, auth()->id());
        $this->purchase = $this->purchase->refresh()->load(['supplier', 'branch', 'creator', 'receiver', 'emailSentBy', 'items.product', 'items.costBreakdown', 'items.purchaseUnit', 'items.stockUnit', 'goodsReceivingNotes.items.stockLocation', 'goodsReceivingNotes.items.product', 'goodsReceivingNotes.receiver', 'emailLogs.sentBy']);
        session()->flash('success', __('application.purchase_order_email_sent_successfully_b6474d9f'));
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first());
    } catch (\Throwable $exception) {
        session()->flash('error', __('application.unable_to_send_email_1646b465').\App\Support\UiText::feedback($exception->getMessage()));
    }
};

?>

<div>
    <x-page-header title="{{ __('application.purchase_details_efcceef7') }}" description="{{ __('application.purchase_order_receiving_progress_and_grn_history_1be4bb2b') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), $purchase->reference_number => null]">
        @if (in_array($purchase->status, ['draft', 'ordered'], true) && $this->canSendEmail())
            <button wire:click="sendPurchaseOrder" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ $purchase->email_status === 'sent' ? __('terminology.resend_email_c5aa56b3') : __('terminology.send_email_be4e8e2d') }}</button>
        @endif
        @if ($this->canSendEmail())
            <a href="{{ route('purchases.purchase-order-pdf', $purchase) }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.download_pdf_98e5ef06') }}</a>
        @endif
        <a href="{{ route('purchases.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.back_b52b36b7') }}</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="{{ __('application.purchase_summary_007b3227') }}">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.reference_db1c7845') }}</dt><dd class="font-bold">{{ $purchase->reference_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.supplier_55edd462') }}</dt><dd class="font-bold">{{ $purchase->supplier?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.branch_1627510b') }}</dt><dd>{{ $purchase->branch?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.date_eb9a4bc1') }}</dt><dd>{{ $purchase->purchase_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.status_bae7d5be') }}</dt><dd><span class="badge-info">{{ \App\Support\UiText::status($purchase->status) }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.payment_b41a92be') }}</dt><dd><span class="{{ $purchase->payment_status === 'paid' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($purchase->payment_status) }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.email_status_cf6437ec') }}</dt><dd><span class="{{ $purchase->email_status === 'sent' ? 'badge-success' : ($purchase->email_status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::label($purchase->email_status ?? 'pending') }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.recipient_90343260') }}</dt><dd>{{ $purchase->email_recipient ?: $purchase->supplier?->email ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.last_sent_1adbfc9d') }}</dt><dd>{{ $purchase->email_sent_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.sent_by_a027e031') }}</dt><dd>{{ $purchase->emailSentBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.total_b25928c6') }}</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.paid_dc9d4584') }}</dt><dd>TZS {{ \App\Support\NumberFormatter::money($purchase->paid_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.balance_90eef613') }}</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</dd></div>
            </dl>
        </x-card>

        <x-card title="{{ __('application.purchase_items_625bdcde') }}" class="xl:col-span-2">
            <x-table :headers="[__('application.product_dd3b86d1'), __('application.purchase_unit_9e240a38'), __('application.conversion_9151f843'), __('application.ordered_c9dd3b77'), __('terminology.base_ordered_71000bb1'), __('application.received_27548c4f'), __('terminology.base_received_a4a36db9'), __('application.remaining_cc632b5e'), __('application.supplier_unit_cost_cb1c1045'), __('application.selling_4cbdcca3'), __('application.total_b25928c6')]">
                @foreach ($purchase->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-bold">
                            {{ $item->product?->displayName() }}
                            @if ($item->sizeLabel())
                                <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ __('application.size_86251551') }} {{ $item->sizeLabel() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}</td>
                        <td class="px-4 py-3">1 {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->purchaseFactor()) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_ordered_quantity ?? $item->stockQuantity((float) $item->ordered_quantity)) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->base_received_quantity ?? $item->stockQuantity((float) $item->received_quantity)) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</td>
                        <td class="px-4 py-3 font-bold">{{ \App\Support\NumberFormatter::quantity($item->remainingQuantity()) }}</td>
                        <td class="px-4 py-3">
                            TZS {{ \App\Support\NumberFormatter::money($item->cost_price) }} / {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}
                            @if ($item->costBreakdown->isNotEmpty())
                                <details class="mt-2 text-xs"><summary class="cursor-pointer font-bold text-cyan-700 dark:text-cyan-300">{{ __('application.cost_breakdown_915586ea') }}</summary>
                                    <div class="mt-2 space-y-1">
                                        @foreach ($item->costBreakdown as $component)
                                            <p>{{ $component->cost_type_name_snapshot }}: TZS {{ \App\Support\NumberFormatter::money($component->amount) }}@if ($component->reference) · {{ $component->reference }}@endif @if ($component->notes) · {{ $component->notes }}@endif</p>
                                        @endforeach
                                        <p class="font-bold">{{ __('application.breakdown_total_tzs_f7294300') }} {{ \App\Support\NumberFormatter::money($item->costBreakdown->sum('amount')) }}</p>
                                        <p class="{{ round($item->costBreakdown->sum('amount') * 100) === round((float) $item->cost_price * 100) ? 'text-emerald-700' : 'text-amber-700' }}">{{ round($item->costBreakdown->sum('amount') * 100) === round((float) $item->cost_price * 100) ? __('terminology.cost_breakdown_matches_unit_cost_01f1fcbe') : __('terminology.cost_breakdown_does_not_match_unit_cost_e9e72c89') }}</p>
                                    </div>
                                </details>
                            @endif
                        </td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->selling_price) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="{{ __('application.goods_receipts_749d8c0e') }}" class="mt-6">
        <x-table :headers="[__('application.receipt_number_37553f8e'), __('application.receiving_date_64d0be44'), __('application.received_by_f84f73c6'), __('application.delivery_note_da6ce2cc'), __('application.supplier_invoice_dedacf19'), __('terminology.total_qty_42fa6483'), __('application.goods_value_e621e6b5'), __('application.additional_costs_9c8e58f4'), __('terminology.landed_total_79b521b2'), __('application.locations_e59e8f15'), __('application.status_bae7d5be'), __('application.actions_c3cd636a')]">
            @forelse ($purchase->goodsReceivingNotes as $grn)
                @php
                    $locations = $grn->items->map(fn ($item) => $item->stockLocation?->name)->filter()->unique()->join(', ');
                    $totalQuantity = $grn->items->sum('received_quantity');
                    $goodsValue = (float) ($grn->goods_value ?? $grn->items->sum(fn ($item) => (float) ($item->total_cost ?: ((float) $item->received_quantity * (float) $item->cost_price))));
                    $additionalCost = (float) ($grn->additional_cost_total ?? 0);
                    $totalCost = (float) ($grn->landed_total ?? $goodsValue);
                @endphp
                <tr>
                    <td class="px-4 py-3 font-black">{{ $grn->grn_number }}</td>
                    <td class="px-4 py-3">{{ $grn->received_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $grn->receiver?->name }}</td>
                    <td class="px-4 py-3">{{ $grn->supplier_delivery_note_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $grn->supplier_invoice_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($totalQuantity) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($goodsValue) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($additionalCost) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</td>
                    <td class="px-4 py-3">{{ $locations ?: '-' }}</td>
                    <td class="px-4 py-3"><span class="{{ $grn->status === 'posted' ? 'badge-success' : ($grn->status === 'cancelled' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::label($grn->status ?? 'posted') }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('goods-receipts.show', $grn) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.view_69bd4ef9') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="12" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_goods_receipts_yet_20b381e7') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>

    <x-card title="{{ __('application.purchase_email_logs_a052e30d') }}" class="mt-6">
        <x-table :headers="[__('application.recipient_90343260'), __('application.subject_8d183dbd'), __('application.status_bae7d5be'), __('application.sent_by_a027e031'), __('terminology.sent_at_a3931e39'), __('application.error_7f2f6a15')]">
            @forelse ($purchase->emailLogs->sortByDesc('created_at') as $log)
                <tr>
                    <td class="px-4 py-3">{{ $log->recipient_email }}</td>
                    <td class="px-4 py-3">{{ $log->subject }}</td>
                    <td class="px-4 py-3"><span class="{{ $log->status === 'sent' ? 'badge-success' : ($log->status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::status($log->status) }}</span></td>
                    <td class="px-4 py-3">{{ $log->sentBy?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $log->sent_at?->locale(app()->getLocale())->translatedFormat('M d, Y H:i') ?? '-' }}</td>
                    <td class="max-w-xs truncate px-4 py-3">{{ \App\Support\UiText::feedback($log->error_message) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_email_activity_yet_6540d275') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
