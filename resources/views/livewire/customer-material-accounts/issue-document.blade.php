<?php
use App\Models\CustomerMaterialIssue;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
layout('layouts.print');state(['materialIssue']);mount(function(CustomerMaterialIssue $materialIssue){$this->materialIssue=$materialIssue->load(['account.customer','account.company','lines.planLine','stockLocation','branch','issuedBy']);});
?>
<div class="receipt mx-auto min-h-screen w-full max-w-[80mm] bg-white p-4 text-[13px] leading-snug text-black sm:p-6">
    @php
        $receipt=app(\App\Services\CustomerMaterialIssueDocumentService::class)->data($materialIssue);
        $issue=$receipt['issue'];$account=$receipt['account'];$previousBalance=$receipt['previousBalance'];$remainingBalance=$receipt['remainingBalance'];
        $money=fn($v)=>'TZS '.\App\Support\NumberFormatter::money($v);
    @endphp
    <style>
        @page { size: 80mm auto; margin: 4mm; }
        @media print { html, body { width: 80mm; background: white !important; } .receipt { max-width: none !important; min-height: auto !important; padding: 0 !important; } }
    </style>
    <div class="mb-4 text-center"><h1 class="text-lg font-black">{{ $account->company?->company_name?:'HARDEX POS' }}</h1><h2 class="mt-2 text-sm font-black">MATERIAL ISSUE RECEIPT / RISITI YA UTOAJI BIDHAA</h2><p class="mt-1 font-bold">MATERIAL COLLECTION / ISSUE</p></div>
    <button id="print-receipt" onclick="window.print()" class="mb-4 w-full rounded-lg bg-black px-4 py-3 font-bold text-white print:hidden">{{ __('customer_material_accounts.material_issue.print_receipt') }}</button>
    <div class="border-y border-dashed border-black py-3"><dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1"><dt class="font-bold">Reference:</dt><dd class="text-right">{{ $issue->reference_number }}</dd><dt class="font-bold">Date:</dt><dd class="text-right">{{ $issue->issued_at->format('d M Y H:i') }}</dd><dt class="font-bold">Customer:</dt><dd class="text-right">{{ $account->customer->name }}</dd><dt class="font-bold">Project / Account:</dt><dd class="text-right">{{ $account->reference_number }}</dd><dt class="font-bold">Branch:</dt><dd class="text-right">{{ $issue->branch->name }}</dd><dt class="font-bold">Stock Location:</dt><dd class="text-right">{{ $issue->stockLocation->name }}</dd></dl></div>
    <div class="py-3"><p class="mb-3 font-black">Materials / Bidhaa</p>@foreach($issue->lines as $line)<div class="mb-3"><p class="font-black">{{ $line->product_name_snapshot }}</p><p class="mt-1 text-right">{{ \App\Support\NumberFormatter::quantity($line->quantity) }} {{ $line->unit_code_snapshot }} × {{ $money($line->agreed_unit_price) }} = <strong>{{ $money($line->line_value) }}</strong></p></div>@endforeach</div>
    <div class="border-y border-dashed border-black py-3"><dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-2"><dt class="font-bold">Total Material Value:</dt><dd class="font-black">{{ $money($issue->total_value) }}</dd><dt>Previous Funded Balance:</dt><dd class="font-bold">{{ $money($previousBalance) }}</dd><dt>Amount Used:</dt><dd class="font-bold">{{ $money($issue->total_value) }}</dd><dt class="font-bold">Remaining Funded Balance:</dt><dd class="font-black">{{ $money($remainingBalance) }}</dd></dl></div>
    <div class="py-3"><p><strong>Collected By:</strong> {{ $issue->collected_by?:'-' }}</p><p class="mt-1"><strong>Issued By:</strong> {{ $issue->issuedBy?->name??'-' }}</p>@if($issue->notes)<p class="mt-2"><strong>Notes:</strong> {{ $issue->notes }}</p>@endif</div>
    <p class="mt-4 text-center font-black">{{ $account->company?->company_name?:'HARDEX POS' }}</p>
</div>
