<?php

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Services\ProductionCostingService;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search'=>'','statusFilter'=>'','branchFilter'=>'','productFilter'=>'','machineFilter'=>'','dateFrom'=>'','dateTo'=>'']);
mount(fn()=>abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_costing','production.manage_costing','production.finalize_costing'])->contains(fn($p)=>auth()->user()?->can($p)),403));
$calculate=function(int $orderId):void{
    $order=ProductionOrder::query()->forCurrentCompany()->findOrFail($orderId);
    $costing=app(ProductionCostingService::class)->calculate($order,auth()->user());
    session()->flash('success','Production costing calculated from immutable order and movement history.');
    $this->redirectRoute('production.costing.show',$costing,navigate:true);
};
?>
<div>
    <x-page-header :title="__('production.costing.title')" description="Historical production cost, loss allocation, and variance analysis." :breadcrumbs="['Dashboard'=>route('dashboard'),__('production.title')=>route('production.index'),__('production.costing.title')=>null]" />
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    <x-card>
        <div class="mb-5 grid gap-3 md:grid-cols-4 xl:grid-cols-7">
            <input wire:model.live.debounce.300ms="search" placeholder="Costing, order, product, machine…" class="rounded-lg border-slate-200 md:col-span-2 dark:bg-navy-950">
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All costings</option><option value="uncosted">Uncosted</option><option value="calculated">Provisional / calculated</option><option value="finalized">Finalized</option><option value="missing">Cost missing</option><option value="unfavorable">Unfavorable variance</option></select>
            <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All branches</option>@foreach(Branch::query()->where('company_id',CompanyFeatures::companyId())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All products</option>@foreach(Product::query()->where('company_id',CompanyFeatures::companyId())->manufactured()->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
            <select wire:model.live="machineFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All machines</option>@foreach(Machine::query()->forCurrentCompany()->orderBy('name')->get() as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border-slate-200 dark:bg-navy-950"><input type="date" wire:model.live="dateTo" class="rounded-lg border-slate-200 dark:bg-navy-950">
        </div>
        @php
            $user=auth()->user();
            $orders=ProductionOrder::query()->forCurrentCompany()->where('status',ProductionOrder::STATUS_COMPLETED)->with(['costing','product','machine','branch','curingBatch'])
                ->when($user?->branch_id && !$user->can('manage cross branch stock locations'),fn($q)=>$q->where(fn($b)=>$b->where('branch_id',$user->branch_id)->orWhereNull('branch_id')))
                ->when($search,fn($q)=>$q->where(fn($x)=>$x->where('order_number','like',"%{$search}%")->orWhereHas('costing',fn($c)=>$c->where('costing_number','like',"%{$search}%"))->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))->orWhereHas('machine',fn($m)=>$m->where('name','like',"%{$search}%"))))
                ->when($statusFilter==='uncosted',fn($q)=>$q->doesntHave('costing'))->when(in_array($statusFilter,['calculated','finalized']),fn($q)=>$q->whereHas('costing',fn($c)=>$c->where('status',$statusFilter)))
                ->when($statusFilter==='missing',fn($q)=>$q->whereHas('costing',fn($c)=>$c->where('has_missing_cost',true)))
                ->when($statusFilter==='unfavorable',fn($q)=>$q->whereHas('costing',fn($c)=>$c->where('cost_variance','>',0)))
                ->when($branchFilter,fn($q)=>$q->where('branch_id',$branchFilter))->when($productFilter,fn($q)=>$q->where('product_id',$productFilter))->when($machineFilter,fn($q)=>$q->where('machine_id',$machineFilter))
                ->when($dateFrom,fn($q)=>$q->whereDate('production_date','>=',$dateFrom))->when($dateTo,fn($q)=>$q->whereDate('production_date','<=',$dateTo))
                ->latest('production_date')->paginate(15);
        @endphp
        <div class="overflow-x-auto"><x-table :headers="['Costing','Order / Product','Date','Planned','Accepted','Rejected','Curing Damage','Planned Cost','Actual Cost','Accepted Unit Cost','Sellable Unit Cost','Variance','Status','Actions']">
            @forelse($orders as $order)@php($cost=$order->costing)<tr>
                <td class="px-3 py-3 font-black">{{ $cost?->costing_number ?: 'Not calculated' }}</td><td class="px-3 py-3">{{ $order->order_number }}<br><span class="text-xs text-slate-500">{{ $order->product?->name }} · {{ $order->machine?->name }}</span></td><td class="px-3 py-3">{{ $order->production_date->format('d M Y') }}</td>
                <td class="px-3 py-3">{{ $order->planned_quantity }}</td><td class="px-3 py-3">{{ $order->accepted_quantity }}</td><td class="px-3 py-3">{{ $order->rejected_quantity }}</td><td class="px-3 py-3">{{ $cost?->curing_damaged_quantity ?: '0' }}</td>
                <td class="px-3 py-3">{{ $cost ? number_format((float)$cost->total_planned_cost,2) : '—' }}</td><td class="px-3 py-3">{{ $cost ? number_format((float)$cost->total_actual_cost,2) : '—' }}</td><td class="px-3 py-3">{{ $cost?->cost_per_accepted_unit ? number_format((float)$cost->cost_per_accepted_unit,4) : '—' }}</td><td class="px-3 py-3">{{ $cost?->cost_per_released_unit ? number_format((float)$cost->cost_per_released_unit,4) : '—' }}</td>
                <td class="px-3 py-3">@if($cost){{ number_format((float)$cost->cost_variance,2) }}<br><span class="text-xs font-bold">{{ bccomp((string)$cost->cost_variance,'0',4)>0?'Unfavorable':(bccomp((string)$cost->cost_variance,'0',4)<0?'Favorable':'On plan') }}</span>@else—@endif</td>
                <td class="px-3 py-3">{{ $cost ? str($cost->status)->headline() : 'Uncosted' }}@if($cost?->has_missing_cost)<br><span class="text-xs font-bold text-red-600">Cost missing</span>@endif</td>
                <td class="px-3 py-3">@if($cost)<a href="{{ route('production.costing.show',$cost) }}" wire:navigate class="rounded border px-2 py-1 text-xs font-black">View</a>@elseif(auth()->user()?->can('production.manage_costing'))<button wire:click="calculate({{ $order->id }})" class="rounded bg-build-orange px-2 py-1 text-xs font-black text-white">Calculate</button>@endif</td>
            </tr>@empty<tr><td colspan="14" class="px-4 py-10 text-center text-slate-500">No completed production orders found.</td></tr>@endforelse
        </x-table></div><div class="mt-4">{{ $orders->links() }}</div>
    </x-card>
</div>
