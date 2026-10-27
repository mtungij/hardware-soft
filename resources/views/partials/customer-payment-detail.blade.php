<div class="grid gap-4 md:grid-cols-[1fr_1fr]">
    <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-900">
        <dl class="grid gap-2">
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.receipt_number_37553f8e') }}</dt><dd class="font-bold">{{ $payment->receipt_number ?: 'PAY-'.$payment->id }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.amount_paid_a2dc17b5') }}</dt><dd class="font-bold">{{ ($money)($payment->amount) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.payment_method_f383f6a2') }}</dt><dd class="font-bold">{{ $payment->paymentMethodLabel() }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.reference_db1c7845') }}</dt><dd class="font-bold">{{ $payment->reference_number ?: '-' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.branch_1627510b') }}</dt><dd class="font-bold">{{ $payment->branch?->name ?? '-' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('application.received_by_f84f73c6') }}</dt><dd class="font-bold">{{ $payment->receivedBy?->name ?? '-' }}</dd></div>
        </dl>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
        <p class="mb-2 text-xs font-black uppercase text-slate-400">{{ __('application.allocated_sales_f84d7aeb') }}</p>
        @include('partials.customer-payment-allocations', ['payment' => $payment, 'money' => $money])
    </div>
</div>
