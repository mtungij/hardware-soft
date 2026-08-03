<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductionCuringBatch;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '', 'branchFilter' => '', 'productFilter' => '', 'statusFilter' => '', 'productionDate' => '', 'sellableDate' => '']);
mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled()
    && collect(['production.view_curing', 'production.manage_curing', 'production.release_curing'])->contains(fn ($permission) => auth()->user()?->can($permission)),
    403
));
?>
<div>
    <x-page-header :title="__('production.curing.title')" description="Real non-sellable curing stock awaiting controlled release." :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.curing.title') => null]" />
    <x-card>
        <div class="mb-5 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <input wire:model.live.debounce.300ms="search" placeholder="Batch, order, product, machine…" class="rounded-lg border-slate-200 md:col-span-2 dark:bg-navy-950">
            <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All products</option>@foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->where('requires_curing', true)->orderBy('name')->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All statuses</option><option value="curing">Still curing</option><option value="ready_for_release">Ready For Release</option><option value="eligible">Eligible today</option><option value="full">Overdue / fully cured</option><option value="partially_released">Partially released</option><option value="released">Released</option><option value="quarantined">Quarantined</option></select>
            <input type="date" wire:model.live="productionDate" title="Production date" class="rounded-lg border-slate-200 dark:bg-navy-950">
            <input type="date" wire:model.live="sellableDate" title="Earliest sellable date" class="rounded-lg border-slate-200 dark:bg-navy-950">
        </div>
        @php
            $now = now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'));
            $batches = ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo(auth()->user())->with(['product.unit','machine','productionOrder','branch','sourceLocation'])
                ->when($search, fn($q) => $q->where(fn($x) => $x->where('batch_number','like',"%{$search}%")->orWhereHas('productionOrder',fn($o)=>$o->where('order_number','like',"%{$search}%"))->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))->orWhereHas('machine',fn($m)=>$m->where('name','like',"%{$search}%"))))
                ->when($branchFilter,fn($q)=>$q->where('branch_id',$branchFilter))->when($productFilter,fn($q)=>$q->where('product_id',$productFilter))
                ->when($productionDate,fn($q)=>$q->whereDate('production_date',$productionDate))->when($sellableDate,fn($q)=>$q->whereDate('minimum_sellable_at',$sellableDate))
                ->when($statusFilter === 'curing',fn($q)=>$q->where('status','curing')->where('minimum_sellable_at','>',$now))
                ->when($statusFilter === 'eligible',fn($q)=>$q->whereNotIn('status',['quarantined','closed','released'])->where('released_quantity',0)->where('minimum_sellable_at','<=',$now))
                ->when($statusFilter === 'ready_for_release',fn($q)=>$q->where('status',ProductionCuringBatch::STATUS_READY_FOR_RELEASE))
                ->when($statusFilter === 'full',fn($q)=>$q->where('full_curing_at','<=',$now)->where('remaining_quantity','>',0))
                ->when(in_array($statusFilter,['partially_released','released','quarantined']),fn($q)=>$q->where('status',$statusFilter))
                ->orderBy('minimum_sellable_at')->paginate(15);
        @endphp
        <div class="hidden overflow-x-auto md:block">
            <x-table :headers="['Batch','Product / Order','Production','Age','Earliest Sellable','Full Curing','Accepted','Released','Damaged','Remaining','Location','Status','Actions']">
                @forelse($batches as $batch)
                    <tr>
                        <td class="px-3 py-3 font-black">{{ $batch->batch_number }}</td><td class="px-3 py-3">{{ $batch->product?->name }}<br><span class="text-xs text-slate-500">{{ $batch->productionOrder?->order_number }}</span></td>
                        <td class="px-3 py-3">{{ $batch->production_date->format('d M Y') }}</td><td class="px-3 py-3">{{ $batch->curing_started_at->diffInDays($now) }} days</td>
                        <td class="px-3 py-3">{{ $batch->minimum_sellable_at->format('d M Y') }}</td><td class="px-3 py-3">{{ $batch->full_curing_at->format('d M Y') }}</td>
                        @foreach(['accepted_quantity','released_quantity','damaged_quantity','remaining_quantity'] as $field)<td class="px-3 py-3">{{ number_format((float)$batch->{$field},4) }}</td>@endforeach
                        <td class="px-3 py-3">{{ $batch->sourceLocation?->name }}</td><td class="px-3 py-3"><span class="badge-warning">{{ str($batch->resolvedStatus())->headline() }}</span></td>
                        <td class="px-3 py-3"><a href="{{ route('production.curing.show',$batch) }}" wire:navigate class="rounded-lg border px-2 py-1 text-xs font-black">View</a></td>
                    </tr>
                @empty <tr><td colspan="13" class="px-4 py-10 text-center text-slate-500">No curing batches found.</td></tr>@endforelse
            </x-table>
        </div>
        <div class="grid gap-3 md:hidden">
            @foreach($batches as $batch)<a href="{{ route('production.curing.show',$batch) }}" wire:navigate class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div class="flex justify-between gap-3"><p class="font-black">{{ $batch->batch_number }}</p><span class="text-xs font-bold">{{ str($batch->resolvedStatus())->headline() }}</span></div><p class="mt-1">{{ $batch->product?->name }}</p><p class="mt-2 text-xs text-slate-500">Sellable {{ $batch->minimum_sellable_at->format('d M Y') }} · Remaining {{ number_format((float)$batch->remaining_quantity,4) }}</p></a>@endforeach
        </div>
        <div class="mt-4">{{ $batches->links() }}</div>
    </x-card>
</div>
