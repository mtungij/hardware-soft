<div class="grid gap-4 md:grid-cols-[1fr_1fr]">
    <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-900">
        <dl class="grid gap-2">
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Receipt Number</dt><dd class="font-bold">{{ $payment->receipt_number ?: 'PAY-'.$payment->id }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Amount Paid</dt><dd class="font-bold">{{ ($money)($payment->amount) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Payment Method</dt><dd class="font-bold">{{ ($methodLabel)($payment->payment_method) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Reference</dt><dd class="font-bold">{{ $payment->reference_number ?: '-' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Branch</dt><dd class="font-bold">{{ $payment->branch?->name ?? '-' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">Received By</dt><dd class="font-bold">{{ $payment->receivedBy?->name ?? '-' }}</dd></div>
        </dl>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
        <p class="mb-2 text-xs font-black uppercase text-slate-400">Allocated Sales</p>
        @include('partials.customer-payment-allocations', ['payment' => $payment, 'money' => $money])
    </div>
</div>
