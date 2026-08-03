<?php
use App\Models\ProductionQualityInspection;
use App\Models\User;
use App\Services\ProductionQualityService;
use App\Support\CompanyFeatures;
use function Livewire\Volt\{layout,mount,state};
layout('layouts.app');
state(['inspection','decision_reason'=>'','inspection_result'=>'passed','accepted_quantity'=>'','rejected_quantity'=>'0','inspector_id'=>'','inspection_notes'=>'']);
mount(function(ProductionQualityInspection $inspection){
    abort_unless(CompanyFeatures::manufacturingEnabled() && auth()->user()?->canAny(['production.view_quality','production.perform_quality_inspections','production.approve_quality']),403);
    $this->inspection=$inspection->load(['product.unit','plan','recipeSnapshot','results.unit','productionOrder','curingBatch','machine','inspector','approver','holds.placer','holds.releaser','supersedes','retests']);
    $this->accepted_quantity=(string)$inspection->applicable_quantity;
    $this->inspector_id=(string)($inspection->inspected_by?:auth()->id());
    $this->inspection_notes=$inspection->notes?:'';
});
$reload=function(){$this->inspection=$this->inspection->fresh(['product.unit','plan','recipeSnapshot','results.unit','productionOrder','curingBatch','machine','inspector','approver','holds.placer','holds.releaser','supersedes','retests']);};
$recordInspection=function(){app(ProductionQualityService::class)->recordQueuedInspection($this->inspection,['result'=>$this->inspection_result,'accepted_quantity'=>$this->accepted_quantity,'rejected_quantity'=>$this->rejected_quantity,'inspector_id'=>$this->inspector_id,'notes'=>$this->inspection_notes],auth()->user());$this->reload();session()->flash('success','Quality inspection recorded and is ready for approval.');};
$approve=function(){app(ProductionQualityService::class)->approve($this->inspection,auth()->user(),$this->decision_reason);$this->reload();};
$reject=function(){app(ProductionQualityService::class)->reject($this->inspection,auth()->user(),$this->decision_reason);$this->reload();};
?>
<div>
<x-page-header :title="$inspection->inspection_number" description="Immutable quality inspection record and approval timeline." :breadcrumbs="[__('production.quality.inspections')=>route('production.quality.inspections.index'),$inspection->inspection_number=>null]"/>
@if(session('success'))<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{session('success')}}</div>@endif
<div class="mb-4 flex flex-wrap gap-2 print:hidden">
    @if(in_array($inspection->result,['failed','conditional']))
        @can('production.perform_quality_inspections')
            <a href="{{route('production.quality.inspections.create',['retest'=>$inspection->id])}}" wire:navigate class="rounded-lg bg-build-orange px-3 py-2 text-sm font-black text-white">Create retest</a>
        @endcan
    @endif
    <button onclick="window.print()" class="rounded-lg border px-3 py-2 text-sm font-bold">Print</button>
</div>
<x-card><dl class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
    <div><dt class="text-xs text-slate-500">Product</dt><dd class="font-bold">{{$inspection->product?->name}}</dd></div>
    <div><dt class="text-xs text-slate-500">Context</dt><dd class="font-bold">{{$inspection->productionOrder?->order_number?:$inspection->curingBatch?->batch_number}}</dd></div>
    <div><dt class="text-xs text-slate-500">Plan / Version</dt><dd class="font-bold">{{$inspection->plan?->name}} / {{$inspection->plan?->version?:'—'}}</dd></div>
    <div><dt class="text-xs text-slate-500">Recipe Snapshot</dt><dd class="font-bold">{{$inspection->recipeSnapshot?->recipe_name?:'—'}}</dd></div>
    <div><dt class="text-xs text-slate-500">Stage</dt><dd class="font-bold">{{str($inspection->inspection_stage)->headline()}}</dd></div>
    <div><dt class="text-xs text-slate-500">Inspector</dt><dd class="font-bold">{{$inspection->inspector?->name}} · {{$inspection->inspected_at->format('d M Y H:i')}}</dd></div>
    <div><dt class="text-xs text-slate-500">Result</dt><dd class="font-black">{{str($inspection->result)->headline()}}</dd></div>
    <div><dt class="text-xs text-slate-500">Approval</dt><dd class="font-black">{{str($inspection->approval_status)->headline()}}</dd></div>
    <div><dt class="text-xs text-slate-500">Sampling</dt><dd class="font-bold">Sample {{$inspection->sample_quantity??'—'}} · Inspected {{$inspection->inspected_quantity??'—'}}</dd></div>
</dl></x-card>
@if($inspection->result==='pending' && $inspection->production_curing_batch_id)
    @can('production.perform_quality_inspections')
        <x-card class="mt-5"><form wire:submit="recordInspection" class="space-y-4"><h2 class="text-lg font-black">Record inspection</h2><div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><label class="text-sm font-bold">Decision<select wire:model="inspection_result" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="passed">Pass</option><option value="conditional">Partial Pass</option><option value="failed">Fail</option></select>@error('result')<span class="text-xs text-red-600">{{$message}}</span>@enderror</label><x-form-input label="Accepted quantity" name="accepted_quantity" type="number" min="0" step="0.0001" wire:model="accepted_quantity"/><x-form-input label="Rejected quantity" name="rejected_quantity" type="number" min="0" step="0.0001" wire:model="rejected_quantity"/><label class="text-sm font-bold">Inspector<select wire:model="inspector_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:bg-navy-950">@foreach(User::query()->where('company_id',$inspection->company_id)->where('status','active')->orderBy('name')->get()->filter(fn($candidate)=>$candidate->can('production.perform_quality_inspections')) as $candidate)<option value="{{$candidate->id}}">{{$candidate->name}}</option>@endforeach</select></label><label class="text-sm font-bold md:col-span-2 xl:col-span-4">Inspection notes<textarea wire:model="inspection_notes" class="mt-1 block min-h-24 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea></label></div><button class="rounded-lg bg-build-orange px-4 py-2 font-black text-white">Record Quality Result</button></form></x-card>
    @endcan
@endif
<x-card class="mt-5"><div class="overflow-x-auto"><x-table :headers="['Check','Requirement Snapshot','Actual','Unit','Result','Critical','Comment']">
@foreach($inspection->results as $line)
<tr class="{{$line->result==='failed'?'bg-red-50 dark:bg-red-950/20':''}}"><td class="px-3 py-3 font-bold">{{$line->check_name}}</td><td class="px-3 py-3">{{str($line->acceptance_rule)->headline()}} · {{$line->minimum_value??'—'}} / {{$line->maximum_value??'—'}} / {{$line->target_value??'—'}}</td><td class="px-3 py-3">{{$line->numeric_value??($line->boolean_value===null?($line->selected_value?:$line->text_value):($line->boolean_value?'Yes':'No'))}}</td><td class="px-3 py-3">{{$line->unit?->short_name?:'—'}}</td><td class="px-3 py-3 font-black">{{str($line->result)->headline()}}</td><td class="px-3 py-3">{{$line->is_critical?'Yes':'No'}}</td><td class="px-3 py-3">{{$line->inspector_comment}}</td></tr>
@endforeach
</x-table></div></x-card>
@if($inspection->approval_status==='pending' && $inspection->result!=='pending')
    @can('production.approve_quality')
        <x-card class="mt-5"><h2 class="mb-3 text-lg font-black">Approval decision</h2><textarea wire:model="decision_reason" placeholder="Justification (required for conditional approval and rejection)" class="block min-h-24 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea>@error('approval_reason')<p class="text-sm text-red-600">{{$message}}</p>@enderror @error('rejection_reason')<p class="text-sm text-red-600">{{$message}}</p>@enderror<div class="mt-3 flex gap-2"><button wire:click="approve" class="rounded-lg bg-emerald-600 px-4 py-2 font-black text-white">Approve</button><button wire:click="reject" class="rounded-lg bg-red-600 px-4 py-2 font-black text-white">Reject</button></div></x-card>
    @endcan
@endif
<div class="mt-5 grid gap-5 md:grid-cols-2"><x-card><h2 class="mb-2 font-black">Corrective Action</h2><p>{{$inspection->corrective_action?:'None recorded.'}}</p><p class="mt-2 text-sm text-slate-500">Passed {{$inspection->passed_quantity??'—'}} · Failed {{$inspection->failed_quantity??'—'}} · Retest required {{$inspection->retest_required?'Yes':'No'}}</p></x-card><x-card><h2 class="mb-2 font-black">Audit Timeline</h2><p>Inspected by {{$inspection->inspector?->name}} at {{$inspection->inspected_at}}</p>@if($inspection->approver)<p>{{str($inspection->approval_status)->headline()}} by {{$inspection->approver->name}} at {{$inspection->approved_at}}</p>@endif @if($inspection->supersedes)<p>Retest of {{$inspection->supersedes->inspection_number}}</p>@endif @foreach($inspection->retests as $r)<p>Retested by <a class="font-bold" href="{{route('production.quality.inspections.show',$r)}}">{{$r->inspection_number}}</a></p>@endforeach @foreach($inspection->holds as $h)<p>Hold {{$h->hold_number}} · {{str($h->status)->headline()}}</p>@endforeach</x-card></div>
</div>
