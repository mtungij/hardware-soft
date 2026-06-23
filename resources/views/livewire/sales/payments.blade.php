<?php

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'sale' => null,
    'payment_method' => 'cash',
    'amount' => '',
    'reference_number' => '',
]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['customer', 'payments']);
    $this->amount = (string) $sale->balance_amount;
});

$receivePayment = function () {
    $this->validate([
        'payment_method' => ['required', 'in:cash,mobile_money,bank'],
        'amount' => ['required', 'numeric', 'gt:0'],
        'reference_number' => ['nullable', 'string', 'max:255'],
    ]);

    DB::transaction(function () {
        $sale = Sale::query()->whereKey($this->sale->id)->lockForUpdate()->firstOrFail();

        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => 'Cancelled sales cannot receive payments.']);
        }

        $amount = min((float) $this->amount, (float) $sale->balance_amount);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'This sale has no outstanding balance.']);
        }

        $sale->payments()->create([
            'payment_method' => $this->payment_method,
            'amount' => $amount,
            'reference_number' => $this->reference_number ?: null,
            'received_by' => auth()->id(),
            'payment_date' => now()->toDateString(),
        ]);

        $newPaid = (float) $sale->paid_amount + $amount;
        $newBalance = max(0, (float) $sale->total_amount - $newPaid);

        $sale->update([
            'paid_amount' => min($newPaid, (float) $sale->total_amount),
            'balance_amount' => $newBalance,
            'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
        ]);

        if ($sale->customer_id) {
            $customer = Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->first();
            $customer?->decrement('balance_amount', min($amount, (float) $customer->balance_amount));
        }

        $this->sale = $sale->refresh()->load(['customer', 'payments']);
        $this->amount = (string) $this->sale->balance_amount;
        $this->reference_number = '';
    });

    session()->flash('success', 'Payment received successfully.');
};

?>

<div>
    <x-page-header title="Receive Sale Payment" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => route('sales.show', $sale), 'Payment' => null]" />

    <div class="grid gap-6 lg:grid-cols-[1fr_420px]">
        <x-card title="Payment History">
            <div class="mb-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Total</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->total_amount, 2) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Paid</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->paid_amount, 2) }}</p></div>
                <div class="rounded-lg bg-navy-900 p-4 text-white"><p class="text-xs text-slate-300">Balance</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->balance_amount, 2) }}</p></div>
            </div>
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">Date</th>
                        <th class="px-4 py-3 text-left">Method</th>
                        <th class="px-4 py-3 text-left">Reference</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </x-slot:head>
                @foreach ($sale->payments as $payment)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td>
                        <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                        <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>

        <x-card title="Record Payment">
            @if ((float) $sale->balance_amount <= 0)
                <div class="rounded-lg bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">This sale is fully paid.</div>
            @else
                <form wire:submit="receivePayment" class="space-y-4">
                    <label class="block text-sm font-bold">Payment Method</label>
                    <select wire:model="payment_method" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank">Bank</option>
                    </select>
                    <x-form-input label="Amount" wire:model="amount" type="number" step="0.01" />
                    <x-form-input label="Reference Number" wire:model="reference_number" />
                    <button class="w-full rounded-lg bg-build-orange px-4 py-3 text-sm font-black text-white">Receive Payment</button>
                </form>
            @endif
        </x-card>
    </div>
</div>
