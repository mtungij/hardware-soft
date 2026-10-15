<?php

use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'supplierFilter' => '', 'statusFilter' => '', 'paymentFilter' => '', 'dateFilter' => '']);

mount(function () {
    $this->search = request('search', $this->search);
    $this->supplierFilter = request('supplierFilter', $this->supplierFilter);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
    $this->paymentFilter = request('paymentFilter', $this->paymentFilter);
    $this->dateFilter = request('dateFilter', request('date_from', $this->dateFilter));
});

$canCreate = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);
$canSendEmail = fn () => auth()->user()->can('send purchase emails');
$canResendEmail = fn () => auth()->user()->can('resend purchase emails') || auth()->user()->can('send purchase emails');

$cancelPurchase = function (int $purchaseId) {
    abort_unless($this->canManage(), 403);

    $purchase = Purchase::with('items')->findOrFail($purchaseId);

    if (! $purchase->canBeModified()) {
        session()->flash('error', __('application.received_purchases_cannot_be_cancelled_7a89f334'));
        return;
    }

    $purchase->update(['status' => 'cancelled']);
    session()->flash('success', __('application.purchase_cancelled_1eb379e3'));
};

$deletePurchase = function (int $purchaseId) {
    abort_unless($this->canManage(), 403);

    $purchase = Purchase::with('items')->findOrFail($purchaseId);

    if (! $purchase->canBeModified()) {
        session()->flash('error', __('application.received_purchases_cannot_be_deleted_0f072fe3'));
        return;
    }

    $purchase->delete();
    session()->flash('success', __('application.purchase_deleted_9ab56f12'));
};

$sendPurchaseOrder = function (int $purchaseId, PurchaseOrderEmailService $service) {
    $purchase = Purchase::with('supplier')->findOrFail($purchaseId);
    abort_unless($purchase->email_status === 'sent' ? $this->canResendEmail() : $this->canSendEmail(), 403);

    try {
        $service->send($purchase, auth()->id());
        session()->flash('success', __('application.purchase_order_email_sent_successfully_b6474d9f'));
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first());
    } catch (\Throwable $exception) {
        session()->flash('error', __('application.unable_to_send_email_1646b465').\App\Support\UiText::feedback($exception->getMessage()));
    }
};

?>

<div>
    <x-page-header title="{{ __('application.purchases_6c4e94f1') }}" :description="\App\Support\InventorySettings::warehouseEnabled() ? __('terminology.create_purchase_orders_and_receive_stock_into_main_store_43ee5266') : __('terminology.create_purchase_orders_and_receive_stock_into_dispensing_area_baacacaf')" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => null]">
       <x-export-actions
    export="tables.purchases"
    :params="compact(
        'search',
        'supplierFilter',
        'statusFilter',
        'paymentFilter',
        'dateFilter'
    )"
/>
        @if ($this->canCreate())
            <a href="{{ route('purchases.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ __('application.create_purchase_4e10c1b7') }}</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_reference_invoice_84baaee5') }}">
            <select wire:model.live="supplierFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_suppliers_471f0ea6') }}</option>
                @foreach (Supplier::orderBy('name')->get() as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                @foreach (['draft', 'ordered', 'received', 'cancelled'] as $status)
                    <option value="{{ $status }}">{{ \App\Support\UiText::label($status) }}</option>
                @endforeach
            </select>
            <select wire:model.live="paymentFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_payments_e314eea6') }}</option>
                @foreach (['unpaid', 'partial', 'paid'] as $status)
                    <option value="{{ $status }}">{{ \App\Support\UiText::label($status) }}</option>
                @endforeach
            </select>
            <input wire:model.live="dateFilter" type="date" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
        </div>

        @php
            $purchases = Purchase::query()
                ->with(['supplier', 'branch'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%")))
                ->when($supplierFilter, fn ($query) => $query->where('supplier_id', $supplierFilter))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($paymentFilter, fn ($query) => $query->where('payment_status', $paymentFilter))
                ->when($dateFilter, fn ($query) => $query->whereDate('purchase_date', $dateFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="[__('terminology.purchase_8a3cfef9'), __('application.supplier_55edd462'), __('application.date_eb9a4bc1'), __('application.total_b25928c6'), __('application.paid_dc9d4584'), __('application.balance_90eef613'), __('application.status_bae7d5be'), __('application.email_84add5b2'), __('application.actions_c3cd636a')]">
            @forelse ($purchases as $purchase)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $purchase->reference_number }}</p><p class="text-xs text-slate-500">{{ $purchase->invoice_number ?? __('terminology.no_invoice_18a71ada') }}</p></td>
                    <td class="px-4 py-3">{{ $purchase->supplier?->name }}</td>
                    <td class="px-4 py-3">{{ $purchase->purchase_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}</td>
                    <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($purchase->paid_amount) }}</td>
                    <td class="px-4 py-3 font-bold">TZS {{ \App\Support\NumberFormatter::money($purchase->balance_amount) }}</td>
                    <td class="px-4 py-3"><span class="badge-info">{{ \App\Support\UiText::status($purchase->status) }}</span></td>
                    <td class="px-4 py-3">
                        <span class="{{ $purchase->email_status === 'sent' ? 'badge-success' : ($purchase->email_status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ \App\Support\UiText::label($purchase->email_status ?? 'pending') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('purchases.show', $purchase) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.view_69bd4ef9') }}</a>
                            @if (in_array($purchase->status, ['draft', 'ordered'], true) && $this->canSendEmail())
                                <button wire:click="sendPurchaseOrder({{ $purchase->id }})" class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-bold text-build-orange dark:border-orange-500/30 dark:bg-orange-500/10">{{ $purchase->email_status === 'sent' ? __('terminology.resend_0f4f4694') : __('terminology.send_po_0815675f') }}</button>
                            @endif
                            @if ($purchase->status !== 'received' && $purchase->status !== 'cancelled' && $this->canCreate())
                                <a href="{{ route('purchases.receive', $purchase) }}" wire:navigate class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">{{ __('application.receive_965fb2dd') }}</a>
                            @endif
                            @if ($purchase->canBeModified() && $this->canManage())
                                <a href="{{ route('purchases.edit', $purchase) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</a>
                                <button wire:click="cancelPurchase({{ $purchase->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</button>
                                <button wire:click="deletePurchase({{ $purchase->id }})" wire:confirm="{{ __('application.delete_this_purchase_c23b6b10') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_purchases_found_bc81c99d') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $purchases->links() }}</div>
    </x-card>
</div>
