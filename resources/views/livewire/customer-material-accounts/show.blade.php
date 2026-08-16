<?php

use App\Models\CustomerMaterialAccount;
use App\Models\StockLocation;
use App\Services\CustomerMaterialAccountService;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['customerMaterialAccount', 'deposit_amount' => '', 'deposit_method' => 'cash', 'deposit_reference' => '', 'deposit_notes' => '', 'deposit_key' => '', 'refund_amount' => '', 'refund_method' => 'cash', 'refund_reason' => '', 'refund_key' => '', 'stock_location_id' => '', 'issue_quantities' => [], 'collected_by' => '', 'issue_notes' => '', 'issue_key' => '', 'cancel_reason' => '']);
mount(function (CustomerMaterialAccount $customerMaterialAccount) {
    $this->customerMaterialAccount = $customerMaterialAccount;
    $this->deposit_key = (string) Str::uuid(); $this->refund_key = (string) Str::uuid(); $this->issue_key = (string) Str::uuid();
    $this->stock_location_id = (string) (StockLocation::where('branch_id', $customerMaterialAccount->branch_id)->where('can_issue_stock', true)->where('is_active', true)->value('id') ?? '');
});
$activate = function (CustomerMaterialAccountService $service) { abort_unless(auth()->user()->can('customer_material_accounts.edit'), 403); $service->activate($this->customerMaterialAccount, auth()->id()); session()->flash('success', 'Material account activated.'); };
$recordDeposit = function (CustomerMaterialAccountService $service) {
    abort_unless(auth()->user()->can('customer_material_accounts.record_deposit'), 403);
    $data = $this->validate(['deposit_amount' => ['required','numeric','gt:0'], 'deposit_method' => ['required','in:cash,mobile_money,bank,cheque'], 'deposit_reference' => ['nullable','string','max:255'], 'deposit_notes' => ['nullable','string','max:1000']]);
    $service->recordDeposit($this->customerMaterialAccount, ['amount'=>$data['deposit_amount'],'payment_method'=>$data['deposit_method'],'payment_reference'=>$data['deposit_reference'],'notes'=>$data['deposit_notes']], auth()->id(), $this->deposit_key);
    $this->deposit_amount=''; $this->deposit_reference=''; $this->deposit_notes=''; $this->deposit_key=(string)Str::uuid(); session()->flash('success','Deposit recorded. No stock was moved.');
};
$issueMaterials = function (CustomerMaterialAccountService $service) {
    abort_unless(auth()->user()->can('customer_material_accounts.issue_material'), 403);
    $this->validate(['stock_location_id'=>['required','exists:stock_locations,id'], 'issue_quantities'=>['required','array'], 'issue_quantities.*'=>['nullable','numeric','gte:0'], 'collected_by'=>['nullable','string','max:255'], 'issue_notes'=>['nullable','string','max:1000']]);
    $rows=collect($this->issue_quantities)->filter(fn($quantity)=>(float)$quantity>0)->map(fn($quantity,$lineId)=>['plan_line_id'=>(int)$lineId,'quantity'=>(float)$quantity])->values()->all();
    $service->issue($this->customerMaterialAccount,$rows,(int)$this->stock_location_id,['collected_by'=>$this->collected_by,'notes'=>$this->issue_notes],auth()->id(),$this->issue_key);
    $this->issue_quantities=[]; $this->collected_by=''; $this->issue_notes=''; $this->issue_key=(string)Str::uuid(); session()->flash('success','Materials issued and stock posted exactly once.');
};
$refund = function (CustomerMaterialAccountService $service) {
    abort_unless(auth()->user()->can('customer_material_accounts.refund'), 403);
    $data=$this->validate(['refund_amount'=>['required','numeric','gt:0'],'refund_method'=>['required','in:cash,mobile_money,bank,cheque'],'refund_reason'=>['required','string','max:1000']]);
    $service->refund($this->customerMaterialAccount,['amount'=>$data['refund_amount'],'payment_method'=>$data['refund_method'],'reason'=>$data['refund_reason']],auth()->id(),$this->refund_key);
    $this->refund_amount='';$this->refund_reason='';$this->refund_key=(string)Str::uuid();session()->flash('success','Unused funded balance refunded.');
};
$cancel = function (CustomerMaterialAccountService $service) { abort_unless(auth()->user()->can('customer_material_accounts.cancel'),403); $service->cancel($this->customerMaterialAccount,$this->cancel_reason,auth()->id()); $this->cancel_reason='';session()->flash('success','Account cancelled; all history remains available.'); };
?>

<div>
    @php
        $account=$customerMaterialAccount->fresh(['customer','branch','planLines.product','issues.lines','issues.stockLocation','issues.issuedBy','transactions.actor','transactions.branch','cashTransactions.receivedBy']);
        $money=fn($value)=>'TZS '.\App\Support\NumberFormatter::money($value);
        $running=0;
    @endphp
    <x-page-header :title="$account->project_name" :description="$account->reference_number.' · '.$account->customer->name" :breadcrumbs="['Dashboard'=>route('dashboard'),'Material Accounts'=>route('customer-material-accounts.index'),$account->reference_number=>null]">
        @can('customer_material_accounts.edit')@if(in_array($account->status,['draft','active']))<a href="{{ route('customer-material-accounts.edit-plan',$account) }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Edit Plan</a>@endif @endcan
        <button onclick="window.print()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Print Statement</button>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-card><p class="text-xs text-slate-500">Project / Plan Value</p><p class="mt-1 text-lg font-black">{{ $money($account->plannedValue()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Total Deposited</p><p class="mt-1 text-lg font-black">{{ $money($account->depositedAmount()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Materials Issued</p><p class="mt-1 text-lg font-black">{{ $money($account->issuedValue()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Available Funded Balance</p><p class="mt-1 text-lg font-black text-emerald-600">{{ $money($account->availableFundedBalance()) }}</p></x-card>
        <x-card><p class="text-xs text-slate-500">Remaining Project Commitment</p><p class="mt-1 text-lg font-black text-amber-600">{{ $money($account->remainingProjectCommitment()) }}</p></x-card>
    </div>

    <x-card class="mt-5" title="Project Details">
        <div class="grid gap-3 text-sm md:grid-cols-4"><div><span class="text-slate-500">Customer</span><p class="font-bold">{{ $account->customer->name }} · {{ $account->customer->phone }}</p></div><div><span class="text-slate-500">Branch</span><p class="font-bold">{{ $account->branch->name }}</p></div><div><span class="text-slate-500">Location</span><p class="font-bold">{{ $account->project_location ?: '-' }}</p></div><div><span class="text-slate-500">Status</span><p class="font-bold">{{ str($account->status)->title() }}</p></div></div>
        @if($account->status==='draft' && auth()->user()->can('customer_material_accounts.edit'))<button wire:click="activate" class="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white">Activate Account</button>@endif
        <div class="mt-4 flex flex-wrap gap-2 print:hidden">@if($latestCash=$account->cashTransactions->sortByDesc('transacted_at')->first())<a target="_blank" href="{{ route('customer-material-accounts.deposit-receipt',$latestCash) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Latest Deposit / Refund Receipt</a>@endif @if($latestIssue=$account->issues->sortByDesc('issued_at')->first())<a target="_blank" href="{{ route('customer-material-accounts.issue-document',$latestIssue) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Latest Material Issue Document</a>@endif</div>
    </x-card>

    <x-card class="mt-5" title="Material Plan Progress">
        <x-table :headers="['Material','Planned','Previously Issued','Remaining','Agreed Price','Planned Value']">
            @foreach($account->planLines as $line)<tr><td class="px-4 py-3 font-bold">{{ $line->product_name_snapshot }}</td><td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->planned_quantity) }} {{ $line->unit_code_snapshot }}</td><td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($line->issuedQuantity()) }} {{ $line->unit_code_snapshot }}</td><td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($line->remainingQuantity()) }} {{ $line->unit_code_snapshot }}</td><td class="px-4 py-3 text-right">{{ $money($line->agreed_unit_price) }}</td><td class="px-4 py-3 text-right">{{ $money($line->planned_line_total) }}</td></tr>@endforeach
        </x-table>
    </x-card>

    @if($account->status==='active')
    <div class="mt-5 grid gap-5 xl:grid-cols-2 print:hidden">
        @can('customer_material_accounts.record_deposit')<x-card title="Record Deposit"><form wire:submit="recordDeposit" class="space-y-3"><x-money-input label="Amount" name="deposit_amount" wire:model="deposit_amount" required/><label class="block text-sm font-bold">Payment Method<select wire:model="deposit_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank">Bank</option><option value="cheque">Cheque</option></select></label><x-form-input label="Payment Reference" name="deposit_reference" wire:model="deposit_reference"/><label class="block text-sm font-bold">Notes<textarea wire:model="deposit_notes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></textarea></label><button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Post Deposit</button></form></x-card>@endcan
        @can('customer_material_accounts.issue_material')<x-card title="Issue Materials"><form wire:submit="issueMaterials" class="space-y-3"><label class="block text-sm font-bold">Stock Location<select wire:model="stock_location_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">@foreach(StockLocation::where('branch_id',$account->branch_id)->where('is_active',true)->where('can_issue_stock',true)->orderBy('name')->get() as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>@foreach($account->planLines->filter(fn($line)=>$line->remainingQuantity()>0) as $line)<label class="block text-sm font-bold">{{ $line->product_name_snapshot }} <span class="font-normal text-slate-500">(remaining {{ \App\Support\NumberFormatter::quantity($line->remainingQuantity()) }} {{ $line->unit_code_snapshot }}, {{ $money($line->agreed_unit_price) }}/{{ $line->unit_code_snapshot }})</span><input wire:model="issue_quantities.{{ $line->id }}" type="number" min="0" max="{{ $line->remainingQuantity() }}" step="any" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></label>@endforeach<x-form-input label="Collected By" name="collected_by" wire:model="collected_by"/><label class="block text-sm font-bold">Notes<textarea wire:model="issue_notes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></textarea></label>@error('funded_balance')<p class="text-sm font-bold text-red-600">{{ $message }}</p>@enderror<button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white">Post Material Issue</button></form></x-card>@endcan
        @can('customer_material_accounts.refund')<x-card title="Refund Unused Balance"><form wire:submit="refund" class="space-y-3"><p class="text-sm text-slate-500">Maximum refundable: <strong>{{ $money($account->availableFundedBalance()) }}</strong></p><x-money-input label="Refund Amount" name="refund_amount" wire:model="refund_amount" required/><label class="block text-sm font-bold">Method<select wire:model="refund_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank">Bank</option><option value="cheque">Cheque</option></select></label><label class="block text-sm font-bold">Required Reason<textarea wire:model="refund_reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></textarea></label><button class="rounded-lg border border-red-300 px-4 py-2 text-sm font-bold text-red-700">Post Refund</button></form></x-card>@endcan
        @can('customer_material_accounts.cancel')<x-card title="Cancel Account"><form wire:submit="cancel" class="space-y-3"><p class="text-sm text-slate-500">Cancellation stops new transactions but preserves the plan, deposits, issues, stock postings, and statement.</p><label class="block text-sm font-bold">Required Reason<textarea wire:model="cancel_reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></textarea></label><button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white">Cancel Account</button></form></x-card>@endcan
    </div>
    @endif

    <x-card class="mt-5" title="Customer Material Account Statement">
        <x-table :headers="['Date','Reference','Type','Description','Deposit / Credit','Issue / Refund','Running Funded Balance','User','Branch']">
            @forelse($account->transactions->sortBy(fn($row)=>[$row->transacted_at,$row->id]) as $transaction)@php $running+=(float)$transaction->credit_amount-(float)$transaction->debit_amount; @endphp<tr><td class="px-4 py-3">{{ $transaction->transacted_at->format('d M Y H:i') }}</td><td class="px-4 py-3 font-mono text-xs">{{ $transaction->reference_number }}</td><td class="px-4 py-3">{{ str($transaction->transaction_type)->replace('_',' ')->title() }}</td><td class="px-4 py-3">{{ $transaction->description }}</td><td class="px-4 py-3 text-right text-emerald-600">{{ (float)$transaction->credit_amount>0?$money($transaction->credit_amount):'-' }}</td><td class="px-4 py-3 text-right text-red-600">{{ (float)$transaction->debit_amount>0?$money($transaction->debit_amount):'-' }}</td><td class="px-4 py-3 text-right font-black">{{ $money($running) }}</td><td class="px-4 py-3">{{ $transaction->actor?->name??'-' }}</td><td class="px-4 py-3">{{ $transaction->branch?->name??'-' }}</td></tr>@empty<tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No deposits or issues yet.</td></tr>@endforelse
        </x-table>
    </x-card>

    <x-card class="mt-5" title="Material Issue Documents">
        <x-table :headers="['Reference','Date','Materials','Value','COGS (internal)','Stock Location','Issued By','Collected By']">@forelse($account->issues->sortByDesc('issued_at') as $issue)<tr><td class="px-4 py-3 font-mono text-xs">{{ $issue->reference_number }}</td><td class="px-4 py-3">{{ $issue->issued_at->format('d M Y H:i') }}</td><td class="px-4 py-3">@foreach($issue->lines as $line)<div>{{ $line->product_name_snapshot }} — {{ \App\Support\NumberFormatter::quantity($line->quantity) }} {{ $line->unit_code_snapshot }}</div>@endforeach</td><td class="px-4 py-3 text-right">{{ $money($issue->total_value) }}</td><td class="px-4 py-3 text-right">{{ $money($issue->total_cost) }}</td><td class="px-4 py-3">{{ $issue->stockLocation->name }}</td><td class="px-4 py-3">{{ $issue->issuedBy?->name??'-' }}</td><td class="px-4 py-3">{{ $issue->collected_by?:'-' }}</td></tr>@empty<tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No material issues posted.</td></tr>@endforelse</x-table>
    </x-card>
</div>
