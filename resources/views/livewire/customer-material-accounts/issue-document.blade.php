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
    <div class="mb-4 text-center"><h1 class="text-lg font-black">{{ $account->company?->company_name?:'HARDEX POS' }}</h1><h2 class="mt-2 text-sm font-black">{{ __('application.material_issue_receipt_4eff12e0') }}</h2><p class="mt-1 font-bold">{{ __('application.material_collection_issue_b7b3609a') }}</p></div>
    <button id="print-receipt" onclick="window.print()" class="mb-4 w-full rounded-lg bg-black px-4 py-3 font-bold text-white print:hidden">{{ __('customer_material_accounts.material_issue.print_receipt') }}</button>
    <div class="border-y border-dashed border-black py-3"><dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1"><dt class="font-bold">{{ __('application.reference_45c3dc1c') }}</dt><dd class="text-right">{{ $issue->reference_number }}</dd><dt class="font-bold">{{ __('application.date_81b7d2ea') }}</dt><dd class="text-right">{{ $issue->issued_at->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</dd><dt class="font-bold">{{ __('application.customer_5e0d7363') }}</dt><dd class="text-right">{{ $account->customer->displayName() }}</dd><dt class="font-bold">{{ __('application.project_account_ad9249ae') }}</dt><dd class="text-right">{{ $account->reference_number }}</dd><dt class="font-bold">{{ __('application.branch_8b31d33d') }}</dt><dd class="text-right">{{ $issue->branch->name }}</dd><dt class="font-bold">{{ __('application.stock_location_980f6a5e') }}</dt><dd class="text-right">{{ $issue->stockLocation->name }}</dd></dl></div>
    <div class="py-3"><p class="mb-3 font-black">{{ __('application.materials_2402ea36') }}</p>@foreach($issue->lines as $line)<div class="mb-3"><p class="font-black">{{ $line->product_name_snapshot }}</p><p class="mt-1 text-right">{{ \App\Support\NumberFormatter::quantity($line->quantity) }} {{ $line->unit_code_snapshot }} × {{ $money($line->agreed_unit_price) }} = <strong>{{ $money($line->line_value) }}</strong></p></div>@endforeach</div>
    <div class="border-y border-dashed border-black py-3"><dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-2"><dt class="font-bold">{{ __('application.total_material_value_e0a78eea') }}</dt><dd class="font-black">{{ $money($issue->total_value) }}</dd><dt>{{ __('application.previous_funded_balance_6c046b86') }}</dt><dd class="font-bold">{{ $money($previousBalance) }}</dd><dt>{{ __('application.amount_used_f4d35832') }}</dt><dd class="font-bold">{{ $money($issue->total_value) }}</dd><dt class="font-bold">{{ __('application.remaining_funded_balance_c7a8c6dd') }}</dt><dd class="font-black">{{ $money($remainingBalance) }}</dd></dl></div>
    <div class="py-3"><p><strong>{{ __('application.collected_by_4e9af775') }}</strong> {{ $issue->collected_by?:'-' }}</p><p class="mt-1"><strong>{{ __('application.issued_by_aba2d603') }}</strong> {{ $issue->issuedBy?->name??'-' }}</p>@if($issue->notes)<p class="mt-2"><strong>{{ __('application.notes_9c3befe7') }}</strong> {{ $issue->notes }}</p>@endif</div>
    <p class="mt-4 text-center font-black">{{ $account->company?->company_name?:'HARDEX POS' }}</p>
</div>
