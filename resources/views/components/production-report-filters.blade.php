@props(['options' => [], 'showStatus' => true, 'report' => null])
<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-navy-900">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.date_from_3cf45b40') }}
            <input type="date" wire:model="date_from" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.date_to_8a23403b') }}
            <input type="date" wire:model="date_to" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.production_site_4478556f') }}
            <select wire:model="branch_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.all_accessible_sites_97747ace') }}</option>@foreach($options['branches'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.product_dd3b86d1') }}
            <select wire:model="product_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.all_manufactured_products_2d8ec4ba') }}</option>@foreach($options['products'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.product_family_f2e6e065') }}
            <select wire:model="family_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.all_families_7a3d99ac') }}</option>@foreach($options['families'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.machine_65bb1b78') }}
            <select wire:model="machine_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.all_machines_957bf628') }}</option>@foreach($options['machines'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.mould_1fdffa4c') }}
            <select wire:model="mould_id" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.all_moulds_197b92e2') }}</option>@foreach($options['moulds'] ?? [] as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        </label>
        @if($showStatus)<label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.status_bae7d5be') }}
            <input wire:model="status" placeholder="{{ __('application.e_g_completed_98fd2388') }}" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>@endif
        @if(in_array($report, ['summary', 'material-consumption'], true))
            <label class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('application.grouped_view_6036427d') }}
                <select wire:model="group_by" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950"><option value="">{{ __('application.detailed_rows_20f5dc07') }}</option>
                    @foreach(($report === 'summary' ? ['day'=>'By Day','product'=>'By Product','family'=>'By Product Family','machine'=>'By Machine','branch'=>'By Branch'] : ['material'=>'By Raw Material','product'=>'By Finished Product','order'=>'By Production Order','day'=>'By Date']) as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        @endif
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 sm:col-span-2">{{ __('application.search_bce06414') }}
            <input wire:model="search" placeholder="{{ __('application.order_batch_inspection_or_reference_2926d1db') }}" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm dark:border-slate-600 dark:bg-navy-950">
        </label>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="applyFilters" class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-bold text-white hover:bg-cyan-700">{{ __('application.apply_filters_6b2a78a8') }}</button>
        <button type="button" wire:click="resetFilters" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold dark:border-slate-600">{{ __('application.reset_44c57abd') }}</button>
        @foreach(['today'=>'Today','week'=>'This Week','month'=>'This Month','previous'=>'Previous Month','year'=>'This Year'] as $key=>$label)
            <button type="button" wire:click="preset('{{ $key }}')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-navy-800">{{ $label }}</button>
        @endforeach
        <span wire:loading class="text-sm font-semibold text-cyan-600" role="status">{{ __('application.loading_report_53284c7e') }}</span>
    </div>
    @error('date_from')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    @error('date_to')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
