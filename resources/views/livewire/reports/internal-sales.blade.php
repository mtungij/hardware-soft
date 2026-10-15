<?php

use App\Models\Product;
use App\Models\StockLocation;
use App\Services\FinancialReportService;
use App\Services\InternalSaleReportService;
use App\Support\AuthorizationScope;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state([
    'tab' => 'outgoing', 'date_from' => '', 'date_to' => '',
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'from_location_id' => '', 'to_location_id' => '',
    'product_id' => '', 'status' => 'completed', 'number' => '',
]);

mount(function () {
    $user = auth()->user();
    $canOutgoing = $user->can('reports.internal_sales');
    $canIncoming = $user->can('reports.internal_acquisitions');
    $canLocation = $user->can('reports.location_margins') && $user->can('stock.view_value') && $user->can('internal_sales.view_margin');
    abort_unless($canOutgoing || $canIncoming || $canLocation, 403);
    $this->tab = $canOutgoing ? 'outgoing' : ($canIncoming ? 'incoming' : 'location');
    $this->date_from = now()->startOfMonth()->toDateString();
    $this->date_to = today()->toDateString();
});

$updatedTab = function (): void {
    $user = auth()->user();
    $allowed = match ($this->tab) {
        'outgoing' => $user->can('reports.internal_sales'),
        'incoming' => $user->can('reports.internal_acquisitions'),
        'location' => $user->can('reports.location_margins') && $user->can('stock.view_value') && $user->can('internal_sales.view_margin'),
        default => false,
    };
    abort_unless($allowed, 403);
};

?>

<div>
    <x-page-header title="{{ __('application.internal_sales_report_0d1c35a2') }}" description="{{ __('application.see_what_each_location_sold_bought_and_earned_1dbb1d3d') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Internal Sales' => null]">
        @if(auth()->user()?->can('reports.export'))
            <x-export-actions export="reports.internal-sales" :params="compact('tab', 'date_from', 'date_to', 'branch_id', 'from_location_id', 'to_location_id', 'product_id', 'status', 'number')" />
        @endif
    </x-page-header>
    @php
        $user = auth()->user();
        $canOutgoing = $user->can('reports.internal_sales');
        $canIncoming = $user->can('reports.internal_acquisitions');
        $canLocation = $user->can('reports.location_margins') && $user->can('stock.view_value') && $user->can('internal_sales.view_margin');
        $canCost = $user->can('internal_sales.view_cost');
        $canMargin = $user->can('internal_sales.view_margin');
        $locations = StockLocation::query()->whereIn('id', AuthorizationScope::stockLocationIds($user))->orderBy('name')->get();
        $branches = app(FinancialReportService::class)->valuationBranches();
        $products = Product::query()->where('company_id', $user->company_id)->where('status', 'active')->orderBy('name')->get();
        $filters = [
            'date_from' => $date_from, 'date_to' => $date_to, 'branch_id' => $branch_id,
            'from_location_id' => $from_location_id, 'to_location_id' => $to_location_id,
            'product_id' => $product_id, 'status' => $status, 'number' => $number,
        ];
        $report = app(InternalSaleReportService::class);
        $rows = in_array($tab, ['outgoing', 'incoming'], true) && (($tab === 'outgoing' && $canOutgoing) || ($tab === 'incoming' && $canIncoming))
            ? $report->rows($user, $tab, $filters) : collect();
        $totals = $report->totals($rows);
        $effectiveBranch = $branch_id ? (int) $branch_id
            : (AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH) === AuthorizationScope::COMPANY ? null : (int) $user->branch_id);
    @endphp
    <x-card>
        <div class="mb-4 flex flex-wrap gap-2">
            @if($canOutgoing)<button wire:click="$set('tab', 'outgoing')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'outgoing' ? 'bg-build-orange text-white' : 'border' }}">{{ __('application.outgoing_internal_sales_80587025') }}</button>@endif
            @if($canIncoming)<button wire:click="$set('tab', 'incoming')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'incoming' ? 'bg-build-orange text-white' : 'border' }}">{{ __('application.incoming_internal_acquisitions_d4659d2e') }}</button>@endif
            @if($canLocation)<button wire:click="$set('tab', 'location')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'location' ? 'bg-build-orange text-white' : 'border' }}">{{ __('application.location_profitability_043595c7') }}</button>@endif
        </div>
        <div class="grid gap-3 md:grid-cols-4">
            <label class="text-xs font-bold">{{ __('application.date_from_3cf45b40') }}<input wire:model.live="date_from" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"></label>
            <label class="text-xs font-bold">{{ __('application.date_to_8a23403b') }}<input wire:model.live="date_to" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"></label>
            <label class="text-xs font-bold">{{ __('application.branch_1627510b') }}<select wire:model.live="branch_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_authorized_branches_6c5afd0a') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">{{ __('application.source_location_319ca91f') }}<select wire:model.live="from_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_visible_sources_74364b02') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">{{ __('application.destination_location_2f202e90') }}<select wire:model.live="to_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_visible_destinations_5ffa8135') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">{{ __('application.product_dd3b86d1') }}<select wire:model.live="product_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.all_products_3ffece7c') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->displayNameWithSize() }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">{{ __('application.status_bae7d5be') }}<select wire:model.live="status" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="completed">{{ __('application.completed_1798b3ba') }}</option><option value="draft">{{ __('application.draft_23d33e22') }}</option><option value="cancelled">{{ __('application.cancelled_a1bf92ef') }}</option><option value="all">{{ __('application.all_6a720856') }}</option></select></label>
            <label class="text-xs font-bold">{{ __('application.internal_sale_number_3afd179e') }}<input wire:model.live.debounce.300ms="number" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950" placeholder="{{ __('application.search_number_933393af') }}"></label>
        </div>
    </x-card>
    @if($tab === 'outgoing' && $canOutgoing)
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <x-card><p class="text-sm text-slate-500">{{ __('application.total_internal_sales_2bdb34a8') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['internal_value']) }}</p></x-card>
            @if($canCost)<x-card><p class="text-sm text-slate-500">{{ __('application.total_internal_cost_962e0479') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['source_cost']) }}</p></x-card>@endif
            @if($canMargin)<x-card><p class="text-sm text-slate-500">{{ __('application.total_internal_profit_5f15b75d') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['internal_margin']) }}</p></x-card>@endif
            <x-card><p class="text-sm text-slate-500">{{ __('application.completed_internal_sales_ad1ee940') }}</p><p class="text-xl font-black">{{ $totals['sales'] }}</p></x-card>
        </div>
        <x-card class="mt-4">
            <h3 class="mb-3 font-bold">{{ __('application.products_sold_internally_91feb447') }}</h3>
            <x-table :headers="array_merge([__('application.date_eb9a4bc1'), __('application.internal_sale_9d355b80'), __('application.destination_location_2f202e90'), __('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('application.quantity_sold_f9c91831'), __('application.internal_unit_price_a9671639'), __('application.internal_sales_value_1f1e708b')], $canCost ? [__('terminology.source_cost_947e264d')] : [], $canMargin ? [__('application.internal_profit_9b602e26')] : [], [__('application.status_bae7d5be')])">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date']?->format('Y-m-d') }}</td>
                        <td class="px-4 py-3"><a href="{{ route('internal-sales.show', $row['sale_id']) }}" wire:navigate class="font-bold text-build-orange">{{ $row['number'] }}</a></td>
                        <td class="px-4 py-3">{{ $row['destination'] }}</td>
                        <td class="px-4 py-3">{{ $row['product'] }}</td>
                        <td class="px-4 py-3">{{ $row['sku'] }}</td>
                        <td class="px-4 py-3">{{ $row['transaction_unit'] }}</td>
                        <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($row['transaction_quantity']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['internal_unit_price']) : '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['internal_value']) : '—' }}</td>
                        @if($canCost)<td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['source_cost']) : '—' }}</td>@endif
                        @if($canMargin)<td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['internal_margin']) : '—' }}</td>@endif
                        <td class="px-4 py-3">{{ \App\Support\UiText::status($row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 10 + ($canCost ? 1 : 0) + ($canMargin ? 1 : 0) }}" class="px-4 py-8 text-center">{{ __('application.no_internal_sales_in_this_period_f363ab21') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'incoming' && $canIncoming)
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <x-card><p class="text-sm text-slate-500">{{ __('application.internal_purchases_acquisitions_8ae1e8d2') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['acquisition_value']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">{{ __('application.total_quantity_received_525b03ce') }}</p><p class="text-xl font-black">{{ \App\Support\NumberFormatter::quantity($totals['base_quantity']) }} {{ __('application.base_units_0f5a2f71') }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">{{ __('application.internal_receipts_08846fd6') }}</p><p class="text-xl font-black">{{ $totals['sales'] }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">{{ __('application.source_locations_606456d7') }}</p><p class="text-xl font-black">{{ $rows->where('posted', true)->pluck('source_id')->unique()->count() }}</p></x-card>
        </div>
        <x-card class="mt-4">
            <h3 class="mb-3 font-bold">{{ __('application.stock_bought_internally_225b40c4') }}</h3>
            <x-table :headers="[__('application.date_eb9a4bc1'), __('application.internal_sale_9d355b80'), __('application.source_location_319ca91f'), __('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.unit_f6b935ab'), __('terminology.quantity_received_fe99f0ec'), __('terminology.acquisition_unit_price_a115b3a1'), __('terminology.total_acquisition_value_dcd49dd4'), __('application.status_bae7d5be')]">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['date']?->format('Y-m-d') }}</td>
                        <td class="px-4 py-3"><a href="{{ route('internal-sales.show', $row['sale_id']) }}" wire:navigate class="font-bold text-build-orange">{{ $row['number'] }}</a></td>
                        <td class="px-4 py-3">{{ $row['source'] }}</td>
                        <td class="px-4 py-3">{{ $row['product'] }}</td>
                        <td class="px-4 py-3">{{ $row['sku'] }}</td>
                        <td class="px-4 py-3">{{ $row['transaction_unit'] }}</td>
                        <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($row['transaction_quantity']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['internal_unit_price']) : '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['posted'] ? \App\Support\NumberFormatter::money($row['acquisition_value']) : '—' }}</td>
                        <td class="px-4 py-3">{{ \App\Support\UiText::status($row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center">{{ __('application.no_internal_acquisitions_in_this_period_501a6790') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'location' && $canLocation)
        @php
            $financial = app(FinancialReportService::class);
            $valuationById = collect($financial->locationMargins($effectiveBranch, $date_from, $date_to))->keyBy('location_id');
            $sourceLines = $report->locationRows($user, 'outgoing', $filters)->where('posted', true);
            $receiptLines = $report->locationRows($user, 'incoming', $filters)->where('posted', true);
            $customerProducts = $report->customerProductRows($user, $filters);
            $companyProfit = $user->can('reports.profit') ? $financial->profitLoss($effectiveBranch, $date_from, $date_to) : null;
            $shownLocations = $locations->filter(fn ($location) =>
                (! $branch_id || $location->branch_id == $branch_id)
                && ((! $from_location_id && ! $to_location_id)
                    || $location->id == $from_location_id || $location->id == $to_location_id));
        @endphp
        @if($companyProfit)
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <x-card><p class="text-sm text-slate-500">{{ __('application.external_customer_revenue_e8d34f47') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['revenue']) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.original_company_cogs_6b990e9d') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['cogs']) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">{{ __('application.consolidated_gross_profit_d8cc9f4b') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['gross_profit']) }}</p></x-card>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('application.company_figures_include_all_customer_sales_for_the_selected_dates_and_branc_34c0f423') }}</p>
        @endif
        @if($status !== 'completed' || $number)
            <p class="mt-3 text-xs text-slate-500">{{ __('application.status_and_internal_sale_number_filter_internal_activity_customer_sales_and_9eeb0ebb') }}</p>
        @endif
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @forelse($shownLocations as $location)
                @php
                    $source = $sourceLines->where('source_id', $location->id);
                    $receipts = $receiptLines->where('destination_id', $location->id);
                    $customers = $customerProducts->where('location_id', $location->id);
                    $sourceTotals = $report->totals($source);
                    $receiptTotals = $report->totals($receipts);
                    $customerSales = $customers->sum('customer_sales');
                    $soldCost = $customers->sum('acquisition_cost');
                    $valuation = $valuationById->get($location->id);
                    $showSource = $source->isNotEmpty() || ($location->type === 'store' && $receipts->isEmpty() && $customers->isEmpty());
                    $showOutlet = $receipts->isNotEmpty() || $customers->isNotEmpty() || $location->type === 'dispensing';
                @endphp
                <x-card>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-black">{{ $location->name }}</h3>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ \App\Support\UiText::label($location->type) }}</span>
                    </div>
                    @if($showSource)
                        <section>
                            <h4 class="mb-2 font-bold">{{ __('application.internal_sales_from_this_location_b7920bca') }}</h4>
                            @if($source->isEmpty())
                                <p class="text-sm text-slate-500">{{ __('application.no_internal_sales_in_this_period_f363ab21') }}</p>
                                <p class="mt-2 text-sm">{{ __('application.current_stock_value_e75a319f') }} <strong>TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</strong></p>
                            @else
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div><dt class="text-slate-500">{{ __('application.internal_sales_a7596208') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['internal_value']) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.internal_cost_632d656a') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['source_cost']) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.internal_profit_9b602e26') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['internal_margin']) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.current_stock_value_2b2a035d') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</dd></div>
                                </dl>
                                <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">{{ __('application.products_sold_internally_91feb447') }}</summary>
                                    <div class="mt-2 overflow-x-auto"><x-table :headers="[__('application.product_dd3b86d1'), __('application.qty_sold_6d4e3556'), __('application.internal_sales_a7596208'), __('application.cost_64ae43e8'), __('application.profit_8544a477')]">
                                        @foreach($report->productSummary($source) as $product)
                                            <tr><td class="px-3 py-2">{{ $product['product'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::quantity($product['base_quantity']) }} {{ $product['base_unit'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['internal_value']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['source_cost']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['internal_margin']) }}</td></tr>
                                        @endforeach
                                    </x-table></div>
                                </details>
                            @endif
                        </section>
                    @endif
                    @if($showOutlet)
                        <section class="{{ $showSource ? 'mt-5 border-t pt-4' : '' }}">
                            <h4 class="mb-2 font-bold">{{ __('application.shop_and_dispensing_activity_0aafe0f5') }}</h4>
                            @if($receipts->isEmpty() && $customers->isEmpty())
                                <p class="text-sm text-slate-500">{{ __('application.no_internal_receipts_or_customer_sales_in_this_period_aa90ca30') }}</p>
                                <p class="mt-2 text-sm">{{ __('application.current_stock_value_e75a319f') }} <strong>TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</strong></p>
                            @else
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div><dt class="text-slate-500">{{ __('application.stock_bought_internally_04bdead4') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($receiptTotals['acquisition_value']) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.customer_sales_a93a5751') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($customerSales) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.cost_of_sold_stock_e0981624') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($soldCost) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.outlet_profit_f6a0f4dd') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($customerSales - $soldCost) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.current_stock_value_2b2a035d') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</dd></div>
                                    <div><dt class="text-slate-500">{{ __('application.stock_value_at_internal_buying_cost_1a9eab4c') }}</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['location_commercial_value'] ?? 0) }}</dd></div>
                                </dl>
                                @if($customers->isNotEmpty())
                                    <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">{{ __('application.products_sold_to_customers_9b1e24f0') }}</summary>
                                        <div class="mt-2 overflow-x-auto"><x-table :headers="[__('application.product_dd3b86d1'), __('application.qty_sold_6d4e3556'), __('application.customer_sales_a93a5751'), __('terminology.acquisition_cost_7a7e7e28'), __('application.outlet_profit_f6a0f4dd')]">
                                            @foreach($customers as $product)
                                                <tr><td class="px-3 py-2">{{ $product['product'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::quantity($product['base_quantity']) }} {{ $product['base_unit'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['customer_sales']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['acquisition_cost']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['outlet_profit']) }}</td></tr>
                                            @endforeach
                                        </x-table></div>
                                    </details>
                                @endif
                                @if($receipts->isNotEmpty())
                                    <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">{{ __('application.products_bought_internally_f617f809') }}</summary>
                                        <div class="mt-2 overflow-x-auto"><x-table :headers="[__('application.product_dd3b86d1'), __('terminology.qty_received_794fec42'), __('terminology.internal_buying_price_a8d05673'), __('application.acquisition_value_177290e3')]">
                                            @foreach($receipts as $receipt)
                                                <tr><td class="px-3 py-2">{{ $receipt['product'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::quantity($receipt['transaction_quantity']) }} {{ $receipt['transaction_unit'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($receipt['internal_unit_price']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($receipt['acquisition_value']) }}</td></tr>
                                            @endforeach
                                        </x-table></div>
                                    </details>
                                @endif
                            @endif
                        </section>
                    @endif
                </x-card>
            @empty
                <x-card><p class="text-sm text-slate-500">{{ __('application.no_authorized_locations_match_these_filters_777bdcaa') }}</p></x-card>
            @endforelse
        </div>
    @endif
</div>
