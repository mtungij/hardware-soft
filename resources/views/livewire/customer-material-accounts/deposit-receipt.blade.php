<?php
use App\Models\CustomerMaterialCashTransaction;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
layout('layouts.print');state(['cashTransaction']);mount(function(CustomerMaterialCashTransaction $cashTransaction){$this->cashTransaction=$cashTransaction->load(['account.customer','account.company','branch','receivedBy']);});
?>
<div class="mx-auto min-h-screen max-w-2xl bg-white p-8 text-slate-950">
    @php $cash=$cashTransaction;$account=$cash->account;$money=fn($v)=>'TZS '.\App\Support\NumberFormatter::money($v); @endphp
    <div class="mb-8 flex justify-between border-b-2 border-slate-900 pb-5"><div><h1 class="text-2xl font-black">{{ $cash->transaction_type==='refund'?'DEPOSIT REFUND':'CUSTOMER MATERIAL DEPOSIT RECEIPT' }}</h1><p>{{ $account->company?->company_name?:config('app.name') }}</p></div><button onclick="window.print()" class="print:hidden rounded-lg bg-slate-900 px-4 py-2 text-white">Print</button></div>
    <dl class="grid grid-cols-2 gap-4 text-sm"><div><dt class="text-slate-500">Receipt Reference</dt><dd class="font-bold">{{ $cash->reference_number }}</dd></div><div><dt class="text-slate-500">Date</dt><dd class="font-bold">{{ $cash->transacted_at->format('d M Y H:i') }}</dd></div><div><dt class="text-slate-500">Customer</dt><dd class="font-bold">{{ $account->customer->name }} · {{ $account->customer->phone }}</dd></div><div><dt class="text-slate-500">Project Account</dt><dd class="font-bold">{{ $account->project_name }} · {{ $account->reference_number }}</dd></div><div><dt class="text-slate-500">Payment Method</dt><dd class="font-bold">{{ str($cash->payment_method)->replace('_',' ')->title() }}</dd></div><div><dt class="text-slate-500">Payment Reference</dt><dd class="font-bold">{{ $cash->payment_reference?:'-' }}</dd></div><div><dt class="text-slate-500">Branch</dt><dd class="font-bold">{{ $cash->branch->name }}</dd></div><div><dt class="text-slate-500">Received / Processed By</dt><dd class="font-bold">{{ $cash->receivedBy?->name??'-' }}</dd></div></dl>
    <div class="my-8 rounded-xl bg-slate-100 p-6 text-center"><p class="text-sm uppercase text-slate-500">Amount</p><p class="text-3xl font-black">{{ $money($cash->amount) }}</p></div>
    @if($cash->reason)<p><strong>Reason:</strong> {{ $cash->reason }}</p>@endif @if($cash->notes)<p><strong>Notes:</strong> {{ $cash->notes }}</p>@endif
    <p class="mt-10 border-t pt-4 text-xs text-slate-500">This receipt records project funding only. It does not represent a stock issue or material collection.</p>
</div>
