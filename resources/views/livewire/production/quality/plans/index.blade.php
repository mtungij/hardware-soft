<?php
use App\Models\ProductionQualityPlan;use App\Services\ProductionQualityService;use App\Support\CompanyFeatures;use Livewire\WithPagination;
use function Livewire\Volt\{layout,mount,state,uses};layout('layouts.app');uses([WithPagination::class]);state(['search'=>'','statusFilter'=>'','stageFilter'=>'']);
mount(fn()=>abort_unless(CompanyFeatures::manufacturingEnabled()&&auth()->user()?->canAny(['production.view_quality','production.manage_quality_plans']),403));
$activate=function(int $id){app(ProductionQualityService::class)->activatePlan(ProductionQualityPlan::query()->forCurrentCompany()->findOrFail($id),auth()->user());session()->flash('success','Quality plan activated.');};
$deactivate=function(int $id){app(ProductionQualityService::class)->deactivatePlan(ProductionQualityPlan::query()->forCurrentCompany()->findOrFail($id),auth()->user());session()->flash('success','Quality plan deactivated.');};
$duplicate=function(int $id){$copy=app(ProductionQualityService::class)->duplicatePlan(ProductionQualityPlan::query()->forCurrentCompany()->findOrFail($id),auth()->user());$this->redirectRoute('production.quality.plans.edit',$copy,navigate:true);};
?>
<div>
<x-page-header :title="__('production.quality.plans')" description="Versioned inspection requirements for manufactured products." :breadcrumbs="['Dashboard'=>route('dashboard'),__('production.title')=>route('production.index'),__('production.quality.title')=>route('production.quality.inspections.index'),__('production.quality.plans')=>null]"/>
<div class="mb-4 flex flex-wrap gap-2"><a href="{{route('production.quality.inspections.index')}}" wire:navigate class="rounded-lg border px-3 py-2 text-sm font-bold">Inspections</a><a href="{{route('production.quality.holds.index')}}" wire:navigate class="rounded-lg border px-3 py-2 text-sm font-bold">Holds</a>@can('production.manage_quality_plans')<a href="{{route('production.quality.plans.create')}}" wire:navigate class="rounded-lg bg-build-orange px-3 py-2 text-sm font-black text-white">Create plan</a>@endcan</div>
@if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{session('success')}}</div>@endif
<x-card><div class="mb-4 grid gap-3 md:grid-cols-3"><input wire:model.live.debounce.300ms="search" placeholder="Plan, code or product…" class="rounded-lg border-slate-200 dark:bg-navy-950"><select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All statuses</option>@foreach(ProductionQualityPlan::STATUSES as $v)<option value="{{$v}}">{{str($v)->headline()}}</option>@endforeach</select><select wire:model.live="stageFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All stages</option>@foreach(ProductionQualityPlan::STAGES as $v)<option value="{{$v}}">{{str($v)->headline()}}</option>@endforeach</select></div>
@php($plans=ProductionQualityPlan::query()->forCurrentCompany()->with(['product','checks'])->when($search,fn($q)=>$q->where(fn($x)=>$x->where('name','like',"%{$search}%")->orWhere('code','like',"%{$search}%")->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))))->when($statusFilter,fn($q)=>$q->where('status',$statusFilter))->when($stageFilter,fn($q)=>$q->where('inspection_stage',$stageFilter))->latest()->paginate(15))
<div class="overflow-x-auto"><x-table :headers="['Plan','Product','Stage','Version','Checks','Status','Effective','Actions']">
@forelse($plans as $plan)
<tr><td class="px-3 py-3 font-black">{{$plan->name}}<br><span class="text-xs text-slate-500">{{$plan->code}}</span></td><td class="px-3 py-3">{{$plan->product?->name}}</td><td class="px-3 py-3">{{str($plan->inspection_stage)->headline()}}</td><td class="px-3 py-3">{{$plan->version?:'—'}}</td><td class="px-3 py-3">{{$plan->checks->count()}}</td><td class="px-3 py-3">{{str($plan->status)->headline()}}</td><td class="px-3 py-3">{{$plan->effective_from?->format('d M Y')?:'—'}} – {{$plan->effective_to?->format('d M Y')?:'Open'}}</td><td class="px-3 py-3"><div class="flex flex-wrap gap-1"><a href="{{route('production.quality.plans.show',$plan)}}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">View</a>
@can('production.manage_quality_plans')
    @if($plan->status==='draft')
        <a href="{{route('production.quality.plans.edit',$plan)}}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">Edit</a><button wire:click="activate({{$plan->id}})" class="rounded bg-emerald-600 px-2 py-1 text-xs font-bold text-white">Activate</button>
    @elseif($plan->status==='active')
        <button wire:click="deactivate({{$plan->id}})" class="rounded border px-2 py-1 text-xs font-bold">Deactivate</button>
    @endif
    <button wire:click="duplicate({{$plan->id}})" class="rounded border px-2 py-1 text-xs font-bold">Duplicate</button>
@endcan
</div></td></tr>
@empty<tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No quality plans found.</td></tr>@endforelse
</x-table></div><div class="mt-4">{{$plans->links()}}</div></x-card></div>
