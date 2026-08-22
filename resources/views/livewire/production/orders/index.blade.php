<?php

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Support\CompanyFeatures;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app'); uses([WithPagination::class]);
state(['search' => '', 'statusFilter' => '', 'branchFilter' => '', 'machineFilter' => '', 'productFilter' => '', 'dateFrom' => '', 'dateTo' => '']);
mount(fn () => abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_orders','production.create_orders','production.execute_orders','production.complete_orders','production.cancel_orders'])->contains(fn ($p) => auth()->user()?->can($p)), 403));
?>
<div>
    <x-page-header :title="__('production.orders.title')" description="Controlled production execution and one-time stock posting." :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.orders.title') => null]">
        @can('production.create_orders')<a href="{{ route('production.orders.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Create from Schedule</a>@endcan
    </x-page-header>
    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" placeholder="Order, product, machine..." class="rounded-lg border-slate-200 md:col-span-2 dark:bg-navy-950">
            <select wire:model.live="statusFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All statuses</option>@foreach (ProductionOrder::STATUSES as $status)<option value="{{ $status }}">{{ str($status)->headline() }}</option>@endforeach</select>
            <select wire:model.live="branchFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::query()->where('company_id', CompanyFeatures::companyId())->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
            <select wire:model.live="machineFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All machines</option>@foreach (Machine::query()->forCurrentCompany()->get() as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select>
            <select wire:model.live="productFilter" class="rounded-lg border-slate-200 dark:bg-navy-950"><option value="">All products</option>@foreach (Product::query()->where('company_id', CompanyFeatures::companyId())->manufactured()->get() as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border-slate-200 dark:bg-navy-950"><input type="date" wire:model.live="dateTo" class="rounded-lg border-slate-200 dark:bg-navy-950">
        </div>
        @php
            $orders = ProductionOrder::query()->forCurrentCompany()->with(['machine','mould','product','branch'])
                ->when($search, fn($q) => $q->where(fn($x) => $x->where('order_number','like',"%{$search}%")->orWhereHas('product',fn($p)=>$p->where('name','like',"%{$search}%"))->orWhereHas('machine',fn($m)=>$m->where('name','like',"%{$search}%"))))
                ->when($statusFilter,fn($q)=>$q->where('status',$statusFilter))->when($branchFilter,fn($q)=>$q->where('branch_id',$branchFilter))
                ->when($machineFilter,fn($q)=>$q->where('machine_id',$machineFilter))->when($productFilter,fn($q)=>$q->where('product_id',$productFilter))
                ->when($dateFrom,fn($q)=>$q->whereDate('production_date','>=',$dateFrom))->when($dateTo,fn($q)=>$q->whereDate('production_date','<=',$dateTo))
                ->latest()->paginate(12);
        @endphp
        <x-table :headers="['Order','Date','Method / Equipment','Product','Planned','Accepted','Rejected','Status','Branch','Actions']">
            @forelse ($orders as $order)<tr><td class="px-4 py-3 font-black">{{ $order->order_number }}</td><td class="px-4 py-3">{{ $order->production_date->format('d M Y') }}</td><td class="px-4 py-3"><span class="block font-bold">{{ $order->productionMethodLabel() }}</span><span class="text-xs text-slate-500">Machine: {{ $order->machine?->name ?: '—' }} · Mould: {{ $order->mould?->name ?: '—' }}</span></td><td class="px-4 py-3">{{ $order->product?->name }}</td><td class="px-4 py-3">{{ $order->planned_quantity }}</td><td class="px-4 py-3">{{ $order->accepted_quantity }}</td><td class="px-4 py-3">{{ $order->rejected_quantity }}</td><td class="px-4 py-3"><span class="badge-warning">{{ str($order->status)->headline() }}</span></td><td class="px-4 py-3">{{ $order->branch?->name ?: '—' }}</td><td class="px-4 py-3"><a href="{{ route('production.orders.show',$order) }}" wire:navigate class="rounded border px-2 py-1 text-xs font-bold">{{ $order->status===ProductionOrder::STATUS_AWAITING_COMPLETION ? 'Review & Complete' : 'View' }}</a></td></tr>
            @empty <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No production orders found.</td></tr>@endforelse
        </x-table><div class="mt-4">{{ $orders->links() }}</div>
    </x-card>
</div>
