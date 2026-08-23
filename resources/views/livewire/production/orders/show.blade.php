<?php

use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionCuringBatch;
use App\Models\StockMovement;
use App\Services\ProductionCostingService;
use App\Services\ProductionOrderService;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['order' => null, 'cancellationReason' => '']);
mount(function (ProductionOrder $order): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_orders','production.create_orders','production.execute_orders','production.complete_orders','production.cancel_orders'])->contains(fn($p)=>auth()->user()?->can($p)),403);
    abort_unless((int)$order->company_id === (int)CompanyFeatures::companyId(),404);
    $this->order=$order->load(['machine','mould','product','branch','rawMaterialLocation','curingLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','curingBatch.qualityInspections','costing','qualityInspections','snapshot.outputUnit','materials.materialProduct','materials.unit','creator','starter','completer','canceller']);
});
$startProduction=function():void{$this->order=app(ProductionOrderService::class)->start($this->order,auth()->user())->load(['machine','product','branch','rawMaterialLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','snapshot.outputUnit','materials.materialProduct','materials.unit','starter']);session()->flash('success','Production started. Planned quantities copied as actual defaults; no stock posted.');};
$complete=function():void{$this->order=app(ProductionOrderService::class)->complete($this->order,auth()->user())->load(['machine','mould','product','branch','rawMaterialLocation','curingLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','curingBatch.qualityInspections','costing','qualityInspections','snapshot.outputUnit','materials.materialProduct','materials.unit','completer']);session()->flash('success','Production completed and inventory posted exactly once.');};
$cancel=function():void{$this->order=app(ProductionOrderService::class)->cancel($this->order,$this->cancellationReason,auth()->user());session()->flash('success','Production order cancelled without stock movement.');};
$viewCosting=function(){
    $costing=$this->order->costing;
    if(!$costing){$costing=app(ProductionCostingService::class)->calculate($this->order,auth()->user());}
    return $this->redirectRoute('production.costing.show',$costing,navigate:true);
};
?>
<div>
    @php
        $completionIssues = $order->status === ProductionOrder::STATUS_AWAITING_COMPLETION
            ? app(ProductionOrderService::class)->completionIssues($order, auth()->user())
            : [];
    @endphp
    <x-page-header :title="$order->order_number" :description="$order->product?->name.' · '.$order->productionMethodLabel()" :breadcrumbs="[__('production.orders.title')=>route('production.orders.index'),$order->order_number=>null]">
        @if($order->status==='in_progress' && auth()->user()?->can('production.execute_orders'))<a href="{{ route('production.orders.execute',$order) }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Record Actual Consumption</a>@if(auth()->user()?->can('production.complete_orders'))<a href="{{ route('production.orders.execute',$order) }}#complete-production" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white">Complete Production</a>@endif @endif
        @if($order->status===ProductionOrder::STATUS_AWAITING_COMPLETION && $completionIssues === [])<button wire:click="complete" wire:confirm="Post actual material consumption and accepted finished output? This cannot be edited afterward." class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white">Complete & Post Stock</button>@endif
        @if($order->status==='completed')
            @if($order->curingBatch && auth()->user()?->canAny(['production.view_curing','production.manage_curing','production.release_curing']))<a href="{{ route('production.curing.show',$order->curingBatch) }}" wire:navigate class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-black text-white">View Curing Batch</a>@endif
            @if($order->costing && auth()->user()?->canAny(['production.view_costing','production.manage_costing','production.finalize_costing']))<a href="{{ route('production.costing.show',$order->costing) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">View Costing</a>@elseif(auth()->user()?->can('production.manage_costing'))<button wire:click="viewCosting" class="rounded-xl border px-4 py-2.5 text-sm font-black">View Costing</button>@endif
            @if(auth()->user()?->canAny(['production.view_quality','production.perform_quality_inspections','production.approve_quality']))<a href="{{ route('production.quality.inspections.index',['search'=>$order->order_number]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">View QC</a>@endif
            <button type="button" onclick="window.print()" class="rounded-xl border px-4 py-2.5 text-sm font-black print:hidden">Print Production Order</button>
        @endif
    </x-page-header>
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php
        $curingStatus=$order->curingBatch?->resolvedStatus();
        $hasQc=$order->qualityInspections->isNotEmpty() || ($order->curingBatch?->qualityInspections?->isNotEmpty() ?? false);
        $currentStage=match(true){
            $order->status==='planned'=>'planned',
            in_array($order->status,['in_progress','awaiting_completion'],true)=>'in_progress',
            $order->status==='completed' && $order->curingBatch && in_array($curingStatus,[ProductionCuringBatch::STATUS_RELEASED,ProductionCuringBatch::STATUS_CLOSED],true)=>'released',
            $order->status==='completed' && $order->curingBatch && $curingStatus===ProductionCuringBatch::STATUS_READY_FOR_RELEASE=>'ready_for_release',
            $order->status==='completed' && $hasQc=>'qc',
            $order->status==='completed' && $order->curingBatch=>'curing',
            $order->status==='completed'=>'completed',
            default=>'planned',
        };
        $stages=['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','curing'=>'Curing','qc'=>'QC','ready_for_release'=>'Ready For Release','released'=>'Released'];
        $currentIndex=array_search($currentStage,array_keys($stages),true);
    @endphp
    <x-card title="Production Progress" class="mb-6 print:hidden">
        <ol class="grid gap-2 sm:grid-cols-7">@foreach($stages as $key=>$label)@php $index=$loop->index; @endphp<li class="relative rounded-xl border px-3 py-3 text-center {{ $index===$currentIndex?'border-cyan-500 bg-cyan-50 text-cyan-900 ring-2 ring-cyan-500 dark:bg-cyan-950/40 dark:text-cyan-100':($index<$currentIndex?'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200':'border-slate-200 text-slate-400 dark:border-slate-700') }}"><span class="block text-xs font-bold uppercase">{{ $index<$currentIndex?'Completed':($index===$currentIndex?'Current':'Pending') }}</span><span class="font-black">{{ $label }}</span></li>@endforeach</ol>
    </x-card>
    @if($order->status===ProductionOrder::STATUS_PLANNED)
        <x-card title="Next Production Action" description="Start this planned order to record actual consumption and production output." class="mb-6">
            @if(auth()->user()?->can('production.execute_orders'))
                <button
                    type="button"
                    wire:click="startProduction"
                    wire:loading.attr="disabled"
                    wire:target="startProduction"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-cyan-600 bg-cyan-500 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:border-cyan-700 hover:bg-cyan-600 active:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-cyan-400 dark:bg-cyan-500 dark:text-slate-950 dark:hover:border-cyan-300 dark:hover:bg-cyan-400 dark:active:bg-cyan-300 dark:focus:ring-offset-slate-950"
                >
                    <span wire:loading.remove wire:target="startProduction">{{ __('production.orders.actions.start_production') }}</span>
                    <span wire:loading wire:target="startProduction">{{ __('production.orders.actions.starting_production') }}</span>
                </button>
                <p class="mt-2 text-xs text-slate-500">Any stock, machine, location, or recipe-snapshot issue will be shown here after you click Start Production.</p>
            @else
                <p class="text-sm font-bold text-amber-700 dark:text-amber-300">You need the Production Order Execution permission to start this order.</p>
            @endif
        </x-card>
    @endif
    @if($order->status===ProductionOrder::STATUS_AWAITING_COMPLETION)
        @php
            $authorizationOnly = array_keys($completionIssues) === ['authorization'];
            $completionCardTitle = $completionIssues === []
                ? 'Next Production Action'
                : ($authorizationOnly ? 'Awaiting Authorized Completion' : 'Cannot Complete Production');
        @endphp
        <x-card :title="$completionCardTitle" description="Post actual consumption and accepted output exactly once." class="mb-6">
            @if($completionIssues === [])
                <button wire:click="complete" wire:confirm="Post actual material consumption and accepted finished output? This cannot be edited afterward." wire:loading.attr="disabled" wire:target="complete" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50">
                    <span wire:loading.remove wire:target="complete">Complete &amp; Post Stock</span>
                    <span wire:loading wire:target="complete">Posting Stock...</span>
                </button>
            @else
                <p class="text-sm font-bold text-amber-700 dark:text-amber-300">Completion is currently blocked for the following reason{{ count($completionIssues) === 1 ? '' : 's' }}:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-700 dark:text-amber-300">
                    @foreach($completionIssues as $issue)<li>{{ $issue }}</li>@endforeach
                </ul>
            @endif
        </x-card>
    @endif
    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Order Summary"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Status</dt><dd class="font-black">{{ str($order->status)->headline() }}</dd></div><div class="flex justify-between"><dt>Production Method</dt><dd class="font-black">{{ $order->productionMethodLabel() }}</dd></div><div class="flex justify-between"><dt>Machine</dt><dd>{{ $order->machine?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>Mould</dt><dd>{{ $order->mould?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>Date</dt><dd>{{ $order->production_date->format('d M Y') }}</dd></div><div class="flex justify-between"><dt>Branch</dt><dd>{{ $order->branch?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>Raw Location</dt><dd>{{ $order->rawMaterialLocation?->name }}</dd></div><div class="flex justify-between"><dt>Initial Output Location</dt><dd>{{ $order->productionOutputLocation?->name ?: $order->finishedGoodsLocation?->name }}</dd></div><div class="flex justify-between"><dt>Final Sellable Location</dt><dd>{{ $order->finalFinishedGoodsLocation?->name ?: $order->finishedGoodsLocation?->name }}</dd></div><div class="flex justify-between"><dt>Planned</dt><dd>{{ $order->planned_quantity }}</dd></div><div class="flex justify-between"><dt>Accepted</dt><dd>{{ $order->accepted_quantity }}</dd></div><div class="flex justify-between"><dt>Rejected</dt><dd>{{ $order->rejected_quantity }}</dd></div></dl>@if($order->curingBatch && auth()->user()?->canAny(['production.view_curing','production.manage_curing','production.release_curing']))<a href="{{ route('production.curing.show',$order->curingBatch) }}" wire:navigate class="mt-4 inline-flex rounded-lg bg-amber-500 px-3 py-2 text-xs font-black text-white">View Curing Batch</a>@endif</x-card>
        <x-card title="Immutable Recipe Snapshot"><p class="font-black">{{ $order->snapshot?->recipe_name }}</p><p class="text-sm text-slate-500">Version {{ $order->snapshot?->recipe_version ?: '—' }} · captured {{ $order->snapshot?->captured_at?->format('d M Y H:i') }}</p><p class="mt-3 text-xs font-bold text-amber-700">Later recipe edits do not change this order.</p></x-card>
        <x-card title="Posting"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Reference</dt><dd>{{ $order->posting_reference ?: 'Not posted' }}</dd></div><div class="flex justify-between"><dt>Posted</dt><dd>{{ $order->posted_at?->format('d M Y H:i') ?: '—' }}</dd></div><div class="flex justify-between"><dt>Completed By</dt><dd>{{ $order->completer?->name ?: '—' }}</dd></div></dl><p class="mt-3 text-xs text-slate-500">Rejected output is retained here and never added to sellable stock.</p>@if($order->costing && auth()->user()?->canAny(['production.view_costing','production.manage_costing','production.finalize_costing']))<a href="{{ route('production.costing.show',$order->costing) }}" wire:navigate class="mt-4 inline-flex rounded-lg border px-3 py-2 text-xs font-black">View Costing</a>@endif</x-card>
    </div>
    @php $availability = app(ProductionOrderService::class)->availability($order); @endphp
    <x-card title="Planned and Actual Requirements" class="mt-6">
        <x-table :headers="['Line','Type','Normalized','Planned','Actual','Available at Raw Location','Cost']">
            @foreach($order->materials as $line)<tr><td class="px-4 py-3 font-black">{{ $line->name }}</td><td class="px-4 py-3">{{ str($line->line_type)->headline() }}</td><td class="px-4 py-3">{{ $line->normalized_quantity_per_output ?? '—' }}</td><td class="px-4 py-3">{{ $line->planned_quantity ?? '—' }} {{ $line->unit?->short_name }}</td><td class="px-4 py-3">{{ $line->actual_quantity ?? '—' }} {{ $line->unit?->short_name }}</td><td class="px-4 py-3">@if($line->line_type===ProductionOrderMaterial::TYPE_INVENTORY){{ $availability[$line->id] ?? 0 }} <span class="{{ bccomp((string)($availability[$line->id]??0),(string)($line->actual_quantity??$line->planned_quantity),4)>=0?'text-emerald-700':'text-red-700' }}">{{ bccomp((string)($availability[$line->id]??0),(string)($line->actual_quantity??$line->planned_quantity),4)>=0?'Sufficient':'Shortage' }}</span>@else—@endif</td><td class="px-4 py-3">TZS {{ number_format((float)($line->actual_cost ?: $line->planned_cost),2) }}</td></tr>@endforeach
        </x-table>
    </x-card>
    @if(auth()->user()?->can('production.cancel_orders') && !in_array($order->status,['completed','cancelled']))
        <x-card title="Cancel Unposted Order" class="mt-6"><div class="flex flex-col gap-3 sm:flex-row"><input wire:model.blur="cancellationReason" placeholder="Cancellation reason required" class="flex-1 rounded-lg border-slate-200 dark:bg-navy-950"><button wire:click="cancel" wire:confirm="Cancel this order without posting stock?" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">Cancel Order</button></div>@error('cancellation_reason')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror</x-card>
    @endif
    @php $movements=StockMovement::query()->where('reference_type',ProductionOrder::class)->where('reference_id',$order->id)->get(); @endphp
    @if($movements->isNotEmpty())<x-card title="Stock Movement References" class="mt-6"><x-table :headers="['Type','Product','Location','In','Out']">@foreach($movements as $movement)<tr><td class="px-4 py-3">{{ $movement->movement_type }}</td><td class="px-4 py-3">{{ $movement->product?->name }}</td><td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td><td class="px-4 py-3">{{ $movement->quantity_in }}</td><td class="px-4 py-3">{{ $movement->quantity_out }}</td></tr>@endforeach</x-table></x-card>@endif
</div>
