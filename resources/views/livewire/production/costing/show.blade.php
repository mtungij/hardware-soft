<?php

use App\Models\ProductionOrderCosting;
use App\Models\ProductionOrderCostingLine;
use App\Services\ProductionCostingService;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['costing'=>null,'actualCosts'=>[],'adjustmentReasons'=>[],'finalizationNotes'=>'']);
mount(function(ProductionOrderCosting $costing):void{
    abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_costing','production.manage_costing','production.finalize_costing'])->contains(fn($p)=>auth()->user()?->can($p)),403);
    $this->costing=$costing->load(['productionOrder.product','productionOrder.machine','productionOrder.branch','productionOrder.curingBatch','lines.product','lines.unit','events.creator','calculator','finalizer']);
    foreach($this->costing->lines as $line){$this->actualCosts[$line->id]=(string)$line->actual_total_cost;$this->adjustmentReasons[$line->id]='';}
});
$refresh=function():void{$this->costing=$this->costing->refresh()->load(['productionOrder.product','productionOrder.machine','productionOrder.branch','productionOrder.curingBatch','lines.product','lines.unit','events.creator','calculator','finalizer']);foreach($this->costing->lines as $line){$this->actualCosts[$line->id]=(string)$line->actual_total_cost;}};
$recalculate=function():void{$this->costing=app(ProductionCostingService::class)->calculate($this->costing->productionOrder,auth()->user(),'Authorised recalculation');$this->refresh();session()->flash('success','Costing recalculated.');};
$adjust=function(int $lineId):void{$line=ProductionOrderCostingLine::query()->where('company_id',auth()->user()->company_id)->findOrFail($lineId);$this->costing=app(ProductionCostingService::class)->adjustNonInventoryCost($line,$this->actualCosts[$lineId]??null,$this->adjustmentReasons[$lineId]??'',auth()->user());$this->adjustmentReasons[$lineId]='';$this->refresh();session()->flash('success','Actual non-inventory cost adjusted and totals recalculated.');};
$finalize=function():void{$this->costing=app(ProductionCostingService::class)->finalize($this->costing,auth()->user(),$this->finalizationNotes?:null);$this->refresh();session()->flash('success','Production costing finalized and locked.');};
?>
<div>
    <style media="print">aside,header,button,.no-print{display:none!important}main{padding:0!important}.erp-surface{box-shadow:none!important}</style>
    <x-page-header :title="$costing->costing_number" description="Production cost, loss, unit economics, and variance analysis." :breadcrumbs="[__('production.costing.title')=>route('production.costing.index'),$costing->costing_number=>null]">
        <button onclick="window.print()" class="rounded-xl border px-4 py-2.5 text-sm font-black">Print</button>
        @if($costing->status!=='finalized' && auth()->user()?->can('production.manage_costing'))<button wire:click="recalculate" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Recalculate</button>@endif
    </x-page-header>
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($costing->has_missing_cost)<div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-black">Cost source warnings</p><ul class="mt-2 list-disc pl-5">@foreach($costing->warnings??[] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>@endif
    @php($order=$costing->productionOrder)
    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Production Summary"><dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach(['Order'=>$order?->order_number,'Date'=>$order?->production_date?->format('d M Y'),'Machine'=>$order?->machine?->name,'Product'=>$order?->product?->name,'Planned'=>$costing->planned_quantity,'Total Produced'=>$costing->total_produced_quantity,'Accepted'=>$costing->accepted_quantity,'Rejected'=>$costing->rejected_quantity,'Curing Damaged'=>$costing->curing_damaged_quantity,'Released'=>$costing->released_quantity,'Status'=>str($costing->status)->headline(),'Currency'=>$costing->currency_code] as $label=>$value)<div><dt class="text-xs font-bold text-slate-500">{{ $label }}</dt><dd class="mt-1 font-black">{{ $value??'—' }}</dd></div>@endforeach</dl></x-card>
            <x-card title="Costing Lines"><x-table :headers="['Line','Type / Basis','Planned Qty','Actual Qty','Planned Unit Cost','Actual Unit Cost','Planned Total','Actual Total','Qty Variance','Cost Variance','Source']">
                @foreach($costing->lines as $line)<tr><td class="px-3 py-3 font-black">{{ $line->name }}</td><td class="px-3 py-3">{{ str($line->line_type)->headline() }}<br><span class="text-xs text-slate-500">{{ str($line->cost_basis)->headline() }}</span></td><td class="px-3 py-3">{{ $line->planned_quantity??'—' }}</td><td class="px-3 py-3">{{ $line->actual_quantity??'—' }}</td><td class="px-3 py-3">{{ $line->planned_unit_cost??'—' }}</td><td class="px-3 py-3">{{ $line->actual_unit_cost??'Cost not provided' }}</td><td class="px-3 py-3">{{ number_format((float)$line->planned_total_cost,2) }}</td><td class="px-3 py-3">{{ number_format((float)$line->actual_total_cost,2) }}</td><td class="px-3 py-3">{{ $line->quantity_variance??'—' }}</td><td class="px-3 py-3">{{ number_format((float)$line->cost_variance,2) }}</td><td class="px-3 py-3 text-xs">{{ class_basename($line->source_type?:'missing') }}@if($line->is_manual)<br><b>Manual</b>@endif</td></tr>
                @endforeach
            </x-table></x-card>
            @if($costing->status!=='finalized' && auth()->user()?->can('production.manage_costing'))
                <x-card title="Actual Non-Inventory Cost Entry" description="Raw-material quantities and production output cannot be edited here.">
                    <div class="space-y-4">@foreach($costing->lines->where('line_type','!=',ProductionOrderCostingLine::INVENTORY) as $line)<div class="grid gap-3 rounded-xl border p-4 md:grid-cols-[1fr_180px_1fr_auto]"><div><p class="font-black">{{ $line->name }}</p><p class="text-xs text-slate-500">{{ str($line->cost_basis)->headline() }}</p></div><input type="number" min="0" step="0.0001" wire:model="actualCosts.{{ $line->id }}" class="rounded-lg border-slate-200 dark:bg-navy-950"><input wire:model="adjustmentReasons.{{ $line->id }}" placeholder="Required adjustment reason" class="rounded-lg border-slate-200 dark:bg-navy-950"><button wire:click="adjust({{ $line->id }})" class="rounded-lg bg-cyan-700 px-3 py-2 text-sm font-black text-white">Save</button></div>@endforeach</div>
                </x-card>
            @endif
            <x-card title="History"><div class="space-y-2">@foreach($costing->events as $event)<div class="rounded-lg bg-slate-100 p-3 text-sm dark:bg-white/5"><b>{{ str($event->event_type)->headline() }}</b> · {{ $event->created_at->format('d M Y H:i') }} · {{ $event->creator?->name }}@if($event->reason)<br><span class="text-slate-500">{{ $event->reason }}</span>@endif</div>@endforeach</div></x-card>
        </div>
        <div class="space-y-5">
            <x-card title="Planned & Actual"><dl class="space-y-2 text-sm">@foreach(['Planned inventory'=>$costing->planned_inventory_material_cost,'Actual inventory'=>$costing->actual_inventory_material_cost,'Planned non-inventory'=>$costing->planned_non_inventory_cost,'Actual non-inventory'=>$costing->actual_non_inventory_cost,'Total planned'=>$costing->total_planned_cost,'Total actual'=>$costing->total_actual_cost] as $label=>$value)<div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-black">{{ number_format((float)$value,2) }}</dd></div>@endforeach</dl></x-card>
            <x-card title="Loss Analysis"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Rejected loss</dt><dd class="font-black">{{ number_format((float)$costing->rejected_loss_cost,2) }}</dd></div><div class="flex justify-between"><dt>Curing damage loss</dt><dd class="font-black">{{ number_format((float)$costing->curing_damage_loss_cost,2) }}</dd></div><div class="flex justify-between"><dt>Total loss</dt><dd class="font-black">{{ number_format((float)$costing->total_loss_cost,2) }}</dd></div></dl></x-card>
            <x-card title="Unit Cost Analysis"><dl class="space-y-2 text-sm">@foreach(['Planned unit'=>$costing->cost_per_planned_unit,'Process unit'=>$costing->cost_per_total_produced_unit,'Accepted unit'=>$costing->cost_per_accepted_unit,'Effective sellable unit'=>$costing->cost_per_released_unit] as $label=>$value)<div class="flex justify-between"><dt>{{ $label }}</dt><dd class="font-black">{{ $value!==null?number_format((float)$value,4):'—' }}</dd></div>@endforeach</dl><p class="mt-3 text-xs text-slate-500">Partial curing release does not change the effective sellable unit cost.</p></x-card>
            <x-card title="Variance"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Cost variance</dt><dd class="font-black">{{ number_format((float)$costing->cost_variance,2) }} · {{ bccomp((string)$costing->cost_variance,'0',4)>0?'Unfavorable':(bccomp((string)$costing->cost_variance,'0',4)<0?'Favorable':'On plan') }}</dd></div><div class="flex justify-between"><dt>Variance %</dt><dd>{{ $costing->variance_percentage!==null?$costing->variance_percentage.'%':'—' }}</dd></div><div class="flex justify-between"><dt>Output variance</dt><dd>{{ $costing->output_variance }}</dd></div><div class="flex justify-between"><dt>Yield variance</dt><dd>{{ $costing->yield_variance }}</dd></div></dl></x-card>
            @if($costing->status!=='finalized' && auth()->user()?->can('production.finalize_costing'))<x-card title="Finalize Costing"><p class="mb-3 text-xs text-amber-700">Finalization permanently locks this analysis. Curing output must be fully released or accounted for.</p><textarea wire:model="finalizationNotes" placeholder="Finalization notes" class="mb-3 block w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea><button wire:click="finalize" wire:confirm="Finalize and permanently lock this costing?" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 font-black text-white">Finalize Costing</button></x-card>@endif
        </div>
    </div>
</div>
