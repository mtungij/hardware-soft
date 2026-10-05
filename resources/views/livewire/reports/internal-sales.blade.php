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
    <x-page-header title="Internal Sales Report" description="See what each location sold, bought, and earned." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Internal Sales' => null]">
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
            @if($canOutgoing)<button wire:click="$set('tab', 'outgoing')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'outgoing' ? 'bg-build-orange text-white' : 'border' }}">Outgoing Internal Sales</button>@endif
            @if($canIncoming)<button wire:click="$set('tab', 'incoming')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'incoming' ? 'bg-build-orange text-white' : 'border' }}">Incoming Internal Acquisitions</button>@endif
            @if($canLocation)<button wire:click="$set('tab', 'location')" class="rounded-lg px-3 py-2 text-sm font-bold {{ $tab === 'location' ? 'bg-build-orange text-white' : 'border' }}">Location Profitability</button>@endif
        </div>
        <div class="grid gap-3 md:grid-cols-4">
            <label class="text-xs font-bold">Date From<input wire:model.live="date_from" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"></label>
            <label class="text-xs font-bold">Date To<input wire:model.live="date_to" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"></label>
            <label class="text-xs font-bold">Branch<select wire:model.live="branch_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">All authorized branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">Source Location<select wire:model.live="from_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All visible sources</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">Destination Location<select wire:model.live="to_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All visible destinations</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">Product<select wire:model.live="product_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->displayNameWithSize() }}</option>@endforeach</select></label>
            <label class="text-xs font-bold">Status<select wire:model.live="status" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="completed">Completed</option><option value="draft">Draft</option><option value="cancelled">Cancelled</option><option value="all">All</option></select></label>
            <label class="text-xs font-bold">Internal Sale Number<input wire:model.live.debounce.300ms="number" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950" placeholder="Search number"></label>
        </div>
    </x-card>
    @if($tab === 'outgoing' && $canOutgoing)
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <x-card><p class="text-sm text-slate-500">Total Internal Sales</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['internal_value']) }}</p></x-card>
            @if($canCost)<x-card><p class="text-sm text-slate-500">Total Internal Cost</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['source_cost']) }}</p></x-card>@endif
            @if($canMargin)<x-card><p class="text-sm text-slate-500">Total Internal Profit</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['internal_margin']) }}</p></x-card>@endif
            <x-card><p class="text-sm text-slate-500">Completed Internal Sales</p><p class="text-xl font-black">{{ $totals['sales'] }}</p></x-card>
        </div>
        <x-card class="mt-4">
            <h3 class="mb-3 font-bold">Products sold internally</h3>
            <x-table :headers="array_merge(['Date', 'Internal Sale #', 'Destination Location', 'Product', 'SKU', 'Unit', 'Quantity Sold', 'Internal Unit Price', 'Internal Sales Value'], $canCost ? ['Source Cost'] : [], $canMargin ? ['Internal Profit'] : [], ['Status'])">
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
                        <td class="px-4 py-3">{{ ucfirst($row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 10 + ($canCost ? 1 : 0) + ($canMargin ? 1 : 0) }}" class="px-4 py-8 text-center">No internal sales in this period.</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'incoming' && $canIncoming)
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <x-card><p class="text-sm text-slate-500">Internal Purchases / Acquisitions</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totals['acquisition_value']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">Total Quantity Received</p><p class="text-xl font-black">{{ \App\Support\NumberFormatter::quantity($totals['base_quantity']) }} base units</p></x-card>
            <x-card><p class="text-sm text-slate-500">Internal Receipts</p><p class="text-xl font-black">{{ $totals['sales'] }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">Source Locations</p><p class="text-xl font-black">{{ $rows->where('posted', true)->pluck('source_id')->unique()->count() }}</p></x-card>
        </div>
        <x-card class="mt-4">
            <h3 class="mb-3 font-bold">Stock bought internally</h3>
            <x-table :headers="['Date', 'Internal Sale #', 'Source Location', 'Product', 'SKU', 'Unit', 'Quantity Received', 'Acquisition Unit Price', 'Total Acquisition Value', 'Status']">
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
                        <td class="px-4 py-3">{{ ucfirst($row['status']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center">No internal acquisitions in this period.</td></tr>
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
                <x-card><p class="text-sm text-slate-500">External Customer Revenue</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['revenue']) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">Original Company COGS</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['cogs']) }}</p></x-card>
                <x-card><p class="text-sm text-slate-500">Consolidated Gross Profit</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['gross_profit']) }}</p></x-card>
            </div>
            <p class="mt-2 text-xs text-slate-500">Company figures include all customer sales for the selected dates and branch. Internal Sales are excluded from company revenue. Location internal profit is a management metric only.</p>
        @endif
        @if($status !== 'completed' || $number)
            <p class="mt-3 text-xs text-slate-500">Status and Internal Sale Number filter internal activity. Customer sales and current stock values are shown separately.</p>
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
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ ucfirst($location->type) }}</span>
                    </div>
                    @if($showSource)
                        <section>
                            <h4 class="mb-2 font-bold">Internal sales from this location</h4>
                            @if($source->isEmpty())
                                <p class="text-sm text-slate-500">No internal sales in this period.</p>
                                <p class="mt-2 text-sm">Current Stock Value: <strong>TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</strong></p>
                            @else
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div><dt class="text-slate-500">Internal Sales</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['internal_value']) }}</dd></div>
                                    <div><dt class="text-slate-500">Internal Cost</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['source_cost']) }}</dd></div>
                                    <div><dt class="text-slate-500">Internal Profit</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($sourceTotals['internal_margin']) }}</dd></div>
                                    <div><dt class="text-slate-500">Current Stock Value</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</dd></div>
                                </dl>
                                <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">Products sold internally</summary>
                                    <div class="mt-2 overflow-x-auto"><x-table :headers="['Product', 'Qty Sold', 'Internal Sales', 'Cost', 'Profit']">
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
                            <h4 class="mb-2 font-bold">Shop and dispensing activity</h4>
                            @if($receipts->isEmpty() && $customers->isEmpty())
                                <p class="text-sm text-slate-500">No internal receipts or customer sales in this period.</p>
                                <p class="mt-2 text-sm">Current Stock Value: <strong>TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</strong></p>
                            @else
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div><dt class="text-slate-500">Stock Bought Internally</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($receiptTotals['acquisition_value']) }}</dd></div>
                                    <div><dt class="text-slate-500">Customer Sales</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($customerSales) }}</dd></div>
                                    <div><dt class="text-slate-500">Cost of Sold Stock</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($soldCost) }}</dd></div>
                                    <div><dt class="text-slate-500">Outlet Profit</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($customerSales - $soldCost) }}</dd></div>
                                    <div><dt class="text-slate-500">Current Stock Value</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['company_stock_value'] ?? 0) }}</dd></div>
                                    <div><dt class="text-slate-500">Stock Value at Internal Buying Cost</dt><dd class="font-bold">TZS {{ \App\Support\NumberFormatter::money($valuation['location_commercial_value'] ?? 0) }}</dd></div>
                                </dl>
                                @if($customers->isNotEmpty())
                                    <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">Products sold to customers</summary>
                                        <div class="mt-2 overflow-x-auto"><x-table :headers="['Product', 'Qty Sold', 'Customer Sales', 'Acquisition Cost', 'Outlet Profit']">
                                            @foreach($customers as $product)
                                                <tr><td class="px-3 py-2">{{ $product['product'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::quantity($product['base_quantity']) }} {{ $product['base_unit'] }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['customer_sales']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['acquisition_cost']) }}</td><td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::money($product['outlet_profit']) }}</td></tr>
                                            @endforeach
                                        </x-table></div>
                                    </details>
                                @endif
                                @if($receipts->isNotEmpty())
                                    <details class="mt-3"><summary class="cursor-pointer text-sm font-bold text-build-orange">Products bought internally</summary>
                                        <div class="mt-2 overflow-x-auto"><x-table :headers="['Product', 'Qty Received', 'Internal Buying Price', 'Acquisition Value']">
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
                <x-card><p class="text-sm text-slate-500">No authorized locations match these filters.</p></x-card>
            @endforelse
        </div>
    @endif
</div>
