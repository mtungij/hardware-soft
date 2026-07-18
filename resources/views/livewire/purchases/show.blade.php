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
    $this->purchase = $purchase->load(['supplier', 'branch', 'creator', 'receiver', 'emailSentBy', 'items.product.unit', 'goodsReceivingNotes.items.stockLocation', 'goodsReceivingNotes.items.product', 'goodsReceivingNotes.receiver', 'emailLogs.sentBy']);
});

$canSendEmail = fn () => auth()->user()->can('send purchase emails');
$canResendEmail = fn () => auth()->user()->can('resend purchase emails') || auth()->user()->can('send purchase emails');

$sendPurchaseOrder = function (PurchaseOrderEmailService $service) {
    abort_unless($this->purchase->email_status === 'sent' ? $this->canResendEmail() : $this->canSendEmail(), 403);

    try {
        $service->send($this->purchase, auth()->id());
        $this->purchase = $this->purchase->refresh()->load(['supplier', 'branch', 'creator', 'receiver', 'emailSentBy', 'items.product.unit', 'goodsReceivingNotes.items.stockLocation', 'goodsReceivingNotes.items.product', 'goodsReceivingNotes.receiver', 'emailLogs.sentBy']);
        session()->flash('success', 'Purchase Order email sent successfully.');
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first());
    } catch (\Throwable $exception) {
        session()->flash('error', 'Unable to send email: '.$exception->getMessage());
    }
};

?>

<div>
    <x-page-header title="Purchase Details" description="Purchase order, receiving progress, and GRN history." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), $purchase->reference_number => null]">
        @if (in_array($purchase->status, ['draft', 'ordered'], true) && $this->canSendEmail())
            <button wire:click="sendPurchaseOrder" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ $purchase->email_status === 'sent' ? 'Resend Email' : 'Send Email' }}</button>
        @endif
        @if ($this->canSendEmail())
            <a href="{{ route('purchases.purchase-order-pdf', $purchase) }}" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Download PDF</a>
        @endif
        <a href="{{ route('purchases.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Back</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Purchase Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Reference</dt><dd class="font-bold">{{ $purchase->reference_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd class="font-bold">{{ $purchase->supplier?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Branch</dt><dd>{{ $purchase->branch?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Date</dt><dd>{{ $purchase->purchase_date->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><span class="badge-info">{{ ucfirst($purchase->status) }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Payment</dt><dd><span class="{{ $purchase->payment_status === 'paid' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($purchase->payment_status) }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Email Status</dt><dd><span class="{{ $purchase->email_status === 'sent' ? 'badge-success' : ($purchase->email_status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($purchase->email_status ?? 'pending') }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Recipient</dt><dd>{{ $purchase->email_recipient ?: $purchase->supplier?->email ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Last Sent</dt><dd>{{ $purchase->email_sent_at?->format('M d, Y H:i') ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Sent By</dt><dd>{{ $purchase->emailSentBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Total</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Paid</dt><dd>TZS {{ \App\Support\NumberFormatter::money($purchase->paid_amount) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Balance</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Purchase Items" class="xl:col-span-2">
            <x-table :headers="['Product', 'Ordered', 'Received', 'Remaining', 'Cost', 'Selling', 'Total']">
                @foreach ($purchase->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-bold">{{ $item->product?->name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} {{ $item->product?->unit?->short_name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }}</td>
                        <td class="px-4 py-3 font-bold">{{ \App\Support\NumberFormatter::quantity($item->remainingQuantity()) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->cost_price) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->selling_price) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="Goods Receipts" class="mt-6">
        <x-table :headers="['Receipt Number', 'Receiving Date', 'Received By', 'Delivery Note', 'Supplier Invoice', 'Total Qty', 'Total Cost', 'Locations', 'Status', 'Actions']">
            @forelse ($purchase->goodsReceivingNotes as $grn)
                @php
                    $locations = $grn->items->map(fn ($item) => $item->stockLocation?->name)->filter()->unique()->join(', ');
                    $totalQuantity = $grn->items->sum('received_quantity');
                    $totalCost = $grn->items->sum(fn ($item) => (float) ($item->total_cost ?: ((float) $item->received_quantity * (float) $item->cost_price)));
                @endphp
                <tr>
                    <td class="px-4 py-3 font-black">{{ $grn->grn_number }}</td>
                    <td class="px-4 py-3">{{ $grn->received_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $grn->receiver?->name }}</td>
                    <td class="px-4 py-3">{{ $grn->supplier_delivery_note_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $grn->supplier_invoice_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($totalQuantity) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</td>
                    <td class="px-4 py-3">{{ $locations ?: '-' }}</td>
                    <td class="px-4 py-3"><span class="{{ $grn->status === 'posted' ? 'badge-success' : ($grn->status === 'cancelled' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($grn->status ?? 'posted') }}</span></td>
                    <td class="px-4 py-3"><a href="{{ route('goods-receipts.show', $grn) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">View</a></td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No goods receipts yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>

    <x-card title="Purchase Email Logs" class="mt-6">
        <x-table :headers="['Recipient', 'Subject', 'Status', 'Sent By', 'Sent At', 'Error']">
            @forelse ($purchase->emailLogs->sortByDesc('created_at') as $log)
                <tr>
                    <td class="px-4 py-3">{{ $log->recipient_email }}</td>
                    <td class="px-4 py-3">{{ $log->subject }}</td>
                    <td class="px-4 py-3"><span class="{{ $log->status === 'sent' ? 'badge-success' : ($log->status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($log->status) }}</span></td>
                    <td class="px-4 py-3">{{ $log->sentBy?->name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $log->sent_at?->format('M d, Y H:i') ?? '-' }}</td>
                    <td class="max-w-xs truncate px-4 py-3">{{ $log->error_message ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No email activity yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
