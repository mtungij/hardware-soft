<?php

use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '', 'supplierFilter' => '', 'statusFilter' => '', 'paymentFilter' => '', 'dateFilter' => '']);

$canCreate = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);
$canSendEmail = fn () => auth()->user()->can('send purchase emails');
$canResendEmail = fn () => auth()->user()->can('resend purchase emails') || auth()->user()->can('send purchase emails');

$cancelPurchase = function (int $purchaseId) {
    abort_unless($this->canManage(), 403);

    $purchase = Purchase::with('items')->findOrFail($purchaseId);

    if (! $purchase->canBeModified()) {
        session()->flash('error', 'Received purchases cannot be cancelled.');
        return;
    }

    $purchase->update(['status' => 'cancelled']);
    session()->flash('success', 'Purchase cancelled.');
};

$deletePurchase = function (int $purchaseId) {
    abort_unless($this->canManage(), 403);

    $purchase = Purchase::with('items')->findOrFail($purchaseId);

    if (! $purchase->canBeModified()) {
        session()->flash('error', 'Received purchases cannot be deleted.');
        return;
    }

    $purchase->delete();
    session()->flash('success', 'Purchase deleted.');
};

$sendPurchaseOrder = function (int $purchaseId, PurchaseOrderEmailService $service) {
    $purchase = Purchase::with('supplier')->findOrFail($purchaseId);
    abort_unless($purchase->email_status === 'sent' ? $this->canResendEmail() : $this->canSendEmail(), 403);

    try {
        $service->send($purchase, auth()->id());
        session()->flash('success', 'Purchase Order email sent successfully.');
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first());
    } catch (\Throwable $exception) {
        session()->flash('error', 'Unable to send email: '.$exception->getMessage());
    }
};

?>

<div>
    <x-page-header title="Purchases" :description="\App\Support\InventorySettings::warehouseEnabled() ? 'Create purchase orders and receive stock into Main Store.' : 'Create purchase orders and receive stock into Dispensing Area.'" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => null]">
        @if ($this->canCreate())
            <a href="{{ route('purchases.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Purchase</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search reference/invoice...">
            <select wire:model.live="supplierFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All suppliers</option>
                @foreach (Supplier::orderBy('name')->get() as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                @foreach (['draft', 'ordered', 'received', 'cancelled'] as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <select wire:model.live="paymentFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All payments</option>
                @foreach (['unpaid', 'partial', 'paid'] as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
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

        <x-table :headers="['Purchase #', 'Supplier', 'Date', 'Total', 'Paid', 'Balance', 'Status', 'Email', 'Actions']">
            @forelse ($purchases as $purchase)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3"><p class="font-black">{{ $purchase->reference_number }}</p><p class="text-xs text-slate-500">{{ $purchase->invoice_number ?? 'No invoice' }}</p></td>
                    <td class="px-4 py-3">{{ $purchase->supplier?->name }}</td>
                    <td class="px-4 py-3">{{ $purchase->purchase_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $purchase->total_amount, 2) }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $purchase->paid_amount, 2) }}</td>
                    <td class="px-4 py-3 font-bold">TZS {{ number_format((float) $purchase->balance_amount, 2) }}</td>
                    <td class="px-4 py-3"><span class="badge-info">{{ ucfirst($purchase->status) }}</span></td>
                    <td class="px-4 py-3">
                        <span class="{{ $purchase->email_status === 'sent' ? 'badge-success' : ($purchase->email_status === 'failed' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($purchase->email_status ?? 'pending') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('purchases.show', $purchase) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">View</a>
                            @if (in_array($purchase->status, ['draft', 'ordered'], true) && $this->canSendEmail())
                                <button wire:click="sendPurchaseOrder({{ $purchase->id }})" class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-bold text-build-orange dark:border-orange-500/30 dark:bg-orange-500/10">{{ $purchase->email_status === 'sent' ? 'Resend' : 'Send PO' }}</button>
                            @endif
                            @if ($purchase->status !== 'received' && $purchase->status !== 'cancelled' && $this->canCreate())
                                <a href="{{ route('purchases.receive', $purchase) }}" wire:navigate class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">Receive</a>
                            @endif
                            @if ($purchase->canBeModified() && $this->canManage())
                                <a href="{{ route('purchases.edit', $purchase) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                                <button wire:click="cancelPurchase({{ $purchase->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Cancel</button>
                                <button wire:click="deletePurchase({{ $purchase->id }})" wire:confirm="Delete this purchase?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No purchases found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $purchases->links() }}</div>
    </x-card>
</div>
