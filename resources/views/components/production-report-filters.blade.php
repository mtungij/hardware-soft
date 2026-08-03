@props(['options' => [], 'showStatus' => true, 'report' => null])
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-navy-900">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Date From
            <input type="date" wire:model="date_from" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Date To
            <input type="date" wire:model="date_to" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Production Site
            <select wire:model="branch_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">All accessible sites</option>@foreach($options['branches'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Product
            <select wire:model="product_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">All manufactured products</option>@foreach($options['products'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Product Family
            <select wire:model="family_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">All families</option>@foreach($options['families'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Machine
            <select wire:model="machine_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">All machines</option>@foreach($options['machines'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Mould
            <select wire:model="mould_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">All moulds</option>@foreach($options['moulds'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        @if($showStatus)<label class="text-xs font-bold text-slate-600 dark:text-slate-300">Status
            <input wire:model="status" placeholder="e.g. completed" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>@endif
        @if(in_array($report, ['summary', 'material-consumption'], true))
            <label class="text-xs font-bold text-slate-600 dark:text-slate-300">Grouped View
                <select wire:model="group_by" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">Detailed rows</option>
                    @foreach(($report === 'summary' ? ['day'=>'By Day','product'=>'By Product','family'=>'By Product Family','machine'=>'By Machine','branch'=>'By Branch'] : ['material'=>'By Raw Material','product'=>'By Finished Product','order'=>'By Production Order','day'=>'By Date']) as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        @endif
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 sm:col-span-2">Search
            <input wire:model="search" placeholder="Order, batch, inspection or reference…" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="applyFilters" class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-bold text-white hover:bg-cyan-700">Apply Filters</button>
        <button type="button" wire:click="resetFilters" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold dark:border-slate-600">Reset</button>
        @foreach(['today'=>'Today','week'=>'This Week','month'=>'This Month','previous'=>'Previous Month','year'=>'This Year'] as $key=>$label)
            <button type="button" wire:click="preset('{{ $key }}')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-navy-800">{{ $label }}</button>
        @endforeach
        <span wire:loading class="text-sm font-semibold text-cyan-600" role="status">Loading report…</span>
    </div>
    @error('date_from')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    @error('date_to')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
