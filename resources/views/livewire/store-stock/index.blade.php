<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

abort_unless(InventorySettings::warehouseEnabled(), 403);

state([
    'branchFilter' => '',
    'locationFilter' => '',
    'categoryFilter' => '',
    'brandFilter' => '',
    'supplierFilter' => '',
    'search' => '',
    'lowStockOnly' => false,
    'outOfStockOnly' => false,
    'groupedView' => false,
    'ledgerProductId' => '',
    'ledgerLocationId' => '',
]);

mount(function () {
    $setting = InventorySettings::current();
    $this->branchFilter = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));

    if (($setting->inventory_mode ?? 'multi_location') === 'single_location') {
        $this->locationFilter = (string) ($setting->default_stock_location_id ?: InventorySettings::defaultLocation((int) $this->branchFilter)->id);
    }
});

$updatedOutOfStockOnly = function () {
    if ($this->outOfStockOnly) {
        $this->lowStockOnly = false;
    }
};

$updatedLowStockOnly = function () {
    if ($this->lowStockOnly) {
        $this->outOfStockOnly = false;
    }
};

$openLedger = function (int $productId, int $locationId) {
    $this->ledgerProductId = (string) $productId;
    $this->ledgerLocationId = (string) $locationId;
    $this->dispatch('open-modal', 'stock-ledger');
};

$canSelectLocation = fn () => (InventorySettings::current()->inventory_mode ?? 'multi_location') !== 'single_location';

$allowedLocationIds = function () {
    $locations = StockLocation::query()
        ->where('status', 'active')
        ->where('is_active', true)
        ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
        ->when($this->branchFilter, fn ($query) => $query->where('branch_id', $this->branchFilter));

    if (! auth()->user()?->can('view all stock locations')) {
        $locations->whereIn('id', auth()->user()?->stockLocations()
            ->wherePivot('can_view', true)
            ->pluck('stock_locations.id') ?? []);
    }

    return $locations->pluck('id')->all();
};

$locationBadge = function (?string $type): string {
    return match ($type) {
        'warehouse' => 'badge-info',
        'store', 'branch_store' => 'badge-success',
        'dispensing', 'showroom' => 'rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-black text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-200',
        'returns' => 'rounded-full bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        'damaged' => 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'transit' => 'rounded-full bg-purple-50 px-2.5 py-1 text-xs font-black text-purple-700 dark:bg-purple-500/15 dark:text-purple-200',
        default => 'badge-muted',
    };
};

?>

<div>
    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
        $branchId = $branchFilter !== '' ? (int) $branchFilter : null;
        $allowedIds = $this->allowedLocationIds();
        $singleLocationMode = ! $this->canSelectLocation();

        $locations = StockLocation::query()
            ->with('branch')
            ->whereIn('id', $allowedIds ?: [0])
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();

        if ($singleLocationMode && blank($locationFilter) && $locations->isNotEmpty()) {
            $locationFilter = (string) $locations->first()->id;
        }

        $brands = Product::query()
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        $stockExpression = "SUM(CASE WHEN stock_movements.quantity_in <> 0 OR stock_movements.quantity_out <> 0 THEN stock_movements.quantity_in - stock_movements.quantity_out WHEN stock_movements.movement_type IN ('sale_out','transfer_out','adjustment_out','damage_out','purchase_receipt_reversal') THEN -stock_movements.quantity ELSE stock_movements.quantity END)";
        $costNumerator = "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) * stock_movements.unit_cost ELSE 0 END)";
        $costDenominator = "SUM(CASE WHEN stock_movements.unit_cost IS NOT NULL AND (stock_movements.quantity_in > 0 OR stock_movements.movement_type IN ('purchase_in','purchase_receipt','transfer_in','adjustment_in','return_in','direct_stock_in')) THEN (CASE WHEN stock_movements.quantity_in > 0 THEN stock_movements.quantity_in ELSE stock_movements.quantity END) ELSE 0 END)";
        $productNameExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "products.name || ' - ' || product_sizes.symbol"
            : "CONCAT(products.name, ' - ', product_sizes.symbol)";

        $stockSubquery = StockMovement::query()
            ->select([
                'company_id',
                'branch_id',
                'product_id',
                'stock_location_id',
                DB::raw("{$stockExpression} as quantity"),
                DB::raw("CASE WHEN {$costDenominator} > 0 THEN {$costNumerator} / {$costDenominator} ELSE 0 END as average_cost"),
            ])
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->groupBy('company_id', 'branch_id', 'product_id', 'stock_location_id');

        $baseRows = DB::table('products')
            ->crossJoin('stock_locations')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoin('product_sizes', 'product_sizes.id', '=', 'products.product_size_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_locations.branch_id')
            ->leftJoinSub($stockSubquery, 'stock_totals', function ($join) {
                $join->on('stock_totals.product_id', '=', 'products.id')
                    ->on('stock_totals.stock_location_id', '=', 'stock_locations.id')
                    ->on('stock_totals.branch_id', '=', 'stock_locations.branch_id');
            })
            ->where('products.status', 'active')
            ->where('stock_locations.status', 'active')
            ->where('stock_locations.is_active', true)
            ->whereIn('stock_locations.id', $allowedIds ?: [0])
            ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('products.company_id', $companyId)->where('stock_locations.company_id', $companyId))
            ->when($branchId, fn ($query) => $query->where('stock_locations.branch_id', $branchId))
            ->when($locationFilter, fn ($query) => $query->where('stock_locations.id', $locationFilter))
            ->when($categoryFilter, fn ($query) => $query->where('products.category_id', $categoryFilter))
            ->when($brandFilter, fn ($query) => $query->where('products.brand', $brandFilter))
            ->when($supplierFilter, fn ($query) => $query->whereExists(function ($exists) use ($supplierFilter) {
                $exists->select(DB::raw(1))
                    ->from('purchase_items')
                    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->whereColumn('purchase_items.product_id', 'products.id')
                    ->where('purchases.supplier_id', $supplierFilter);
            }))
            ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhere('products.barcode', 'like', "%{$search}%")
                ->orWhere('product_sizes.name', 'like', "%{$search}%")
                ->orWhere('product_sizes.symbol', 'like', "%{$search}%")))
            ->select([
                'products.id as product_id',
                DB::raw("CASE WHEN product_sizes.symbol IS NULL OR product_sizes.symbol = '' THEN products.name ELSE {$productNameExpression} END as product_name"),
                'products.sku',
                'products.barcode',
                'products.brand',
                'products.reorder_level',
                'categories.name as category_name',
                'units.short_name as unit_name',
                'branches.name as branch_name',
                'stock_locations.id as stock_location_id',
                'stock_locations.name as location_name',
                'stock_locations.type as location_type',
                DB::raw('COALESCE(stock_totals.quantity, 0) as quantity'),
                DB::raw('COALESCE(stock_totals.average_cost, 0) as average_cost'),
                DB::raw('COALESCE(stock_totals.quantity, 0) * COALESCE(stock_totals.average_cost, 0) as stock_value'),
                DB::raw("CASE WHEN COALESCE(stock_totals.quantity, 0) <= 0 THEN 'out_of_stock' WHEN COALESCE(stock_totals.quantity, 0) <= products.reorder_level THEN 'low_stock' ELSE 'in_stock' END as stock_status"),
            ])
            ->when($lowStockOnly, fn ($query) => $query->whereRaw('COALESCE(stock_totals.quantity, 0) > 0 AND COALESCE(stock_totals.quantity, 0) <= products.reorder_level'))
            ->when($outOfStockOnly, fn ($query) => $query->whereRaw('COALESCE(stock_totals.quantity, 0) <= 0'));

        $summaryRows = (clone $baseRows)->get();
        $rows = (clone $baseRows)
            ->orderBy('products.name')
            ->orderBy('stock_locations.name')
            ->paginate(25);

        $groupedRows = $rows->getCollection()->groupBy('product_id');
        $selectedLocation = $locationFilter ? $locations->firstWhere('id', (int) $locationFilter) : null;
        $ledgerProduct = $ledgerProductId ? Product::query()->find($ledgerProductId) : null;
        $ledgerLocation = $ledgerLocationId ? StockLocation::query()->find($ledgerLocationId) : null;
        $ledgerMovements = ($ledgerProduct && $ledgerLocation)
            ? StockMovement::query()
                ->with(['creator'])
                ->where('product_id', $ledgerProduct->id)
                ->where('stock_location_id', $ledgerLocation->id)
                ->orderBy('movement_date')
                ->orderBy('id')
                ->get()
            : collect();
        $runningBalance = 0;
    @endphp

    <x-page-header title="Stock by Location" description="Stock kwa Eneo - balances calculated from stock movements by location." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock by Location' => null]">
        <x-export-actions export="tables.store-stock" :params="[
            'branchFilter' => $branchFilter,
            'stock_location_id' => $locationFilter,
            'categoryFilter' => $categoryFilter,
            'brand' => $brandFilter,
            'supplier_id' => $supplierFilter,
            'search' => $search,
            'low_stock_only' => $lowStockOnly ? 1 : null,
            'out_of_stock_only' => $outOfStockOnly ? 1 : null,
            'grouped' => $groupedView ? 1 : null,
        ]" />
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-5">
        <x-card><p class="text-sm text-slate-500">Current Stock Quantity</p><p class="mt-2 text-2xl font-black">{{ \App\Support\NumberFormatter::quantity($summaryRows->sum('quantity')) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Current Stock Value</p><p class="mt-2 text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($summaryRows->sum('stock_value')) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Low Stock Products</p><p class="mt-2 text-2xl font-black">{{ $summaryRows->where('stock_status', 'low_stock')->count() }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Out Of Stock Products</p><p class="mt-2 text-2xl font-black">{{ $summaryRows->where('stock_status', 'out_of_stock')->count() }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Active Locations</p><p class="mt-2 text-2xl font-black">{{ $locations->count() }}</p></x-card>
    </div>

    <x-card class="mt-5">
        <div class="mb-4 grid gap-3 lg:grid-cols-4 xl:grid-cols-5">
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All Branches</option>
                @foreach (Branch::query()->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>

            @if ($singleLocationMode)
                <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                    {{ $selectedLocation?->name ?? 'Default Location' }}
                </div>
            @else
                <select wire:model.live="locationFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">All Locations</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}{{ $location->branch?->name ? ' / '.$location->branch->name : '' }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All Categories</option>
                @foreach (Category::query()->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="brandFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All Brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand }}">{{ $brand }}</option>
                @endforeach
            </select>

            <select wire:model.live="supplierFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All Suppliers</option>
                @foreach (Supplier::query()->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get() as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>

            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 lg:col-span-2" placeholder="Search product, SKU, barcode...">

            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">
                <input type="checkbox" wire:model.live="lowStockOnly" class="rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
                Low Stock Only
            </label>

            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">
                <input type="checkbox" wire:model.live="outOfStockOnly" class="rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
                Out Of Stock Only
            </label>

            <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">
                <input type="checkbox" wire:model.live="groupedView" class="rounded border-slate-300 text-cyan-500 focus:ring-cyan-500">
                Grouped View
            </label>
        </div>

        @if ($groupedView)
            <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                @forelse ($groupedRows as $productRows)
                    @php
                        $first = $productRows->first();
                        $productTotal = $productRows->sum('quantity');
                        $productValue = $productRows->sum('stock_value');
                    @endphp
                    <div class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-navy-900">
                        <div class="bg-slate-50 px-4 py-3 dark:bg-white/5">
                            <p class="font-black">{{ $first->product_name }}</p>
                            <p class="text-xs text-slate-500">{{ $first->sku }} / {{ $first->category_name }} / {{ $first->unit_name }}</p>
                        </div>
                        @foreach ($productRows as $row)
                            <button type="button" wire:click="openLedger({{ $row->product_id }}, {{ $row->stock_location_id }})" class="grid w-full gap-2 px-4 py-3 text-left text-sm hover:bg-slate-50 dark:hover:bg-white/5 md:grid-cols-[1.5fr_1fr_1fr_1fr_auto]">
                                <span><span class="{{ $this->locationBadge($row->location_type) }}">{{ str($row->location_type)->replace('_', ' ')->title() }}</span> <span class="ml-2 font-bold">{{ $row->location_name }}</span></span>
                                <span>{{ \App\Support\NumberFormatter::quantity($row->quantity) }}</span>
                                <span>TZS {{ \App\Support\NumberFormatter::money($row->average_cost) }}</span>
                                <span>TZS {{ \App\Support\NumberFormatter::money($row->stock_value) }}</span>
                                <span class="font-black">{{ str($row->stock_status)->replace('_', ' ')->title() }}</span>
                            </button>
                        @endforeach
                        <div class="grid gap-2 bg-slate-50 px-4 py-3 text-sm font-black dark:bg-white/5 md:grid-cols-[1.5fr_1fr_1fr_1fr_auto]">
                            <span>Total</span><span>{{ \App\Support\NumberFormatter::quantity($productTotal) }}</span><span></span><span>TZS {{ \App\Support\NumberFormatter::money($productValue) }}</span><span></span>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-slate-500">No stock records found.</div>
                @endforelse
            </div>
        @else
            <x-table :headers="['Product', 'SKU', 'Category', 'Unit', 'Stock Location', 'Quantity', 'Average Cost', 'Stock Value', 'Reorder Level', 'Status']">
                @forelse ($rows as $row)
                    <tr wire:click="openLedger({{ $row->product_id }}, {{ $row->stock_location_id }})" class="cursor-pointer hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $row->product_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $row->sku }}</td>
                        <td class="px-4 py-3">{{ $row->category_name }}</td>
                        <td class="px-4 py-3">{{ $row->unit_name }}</td>
                        <td class="px-4 py-3">
                            <div class="font-bold">{{ $row->location_name }}</div>
                            <span class="{{ $this->locationBadge($row->location_type) }}">{{ str($row->location_type)->replace('_', ' ')->title() }}</span>
                        </td>
                        <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($row->quantity) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($row->average_cost) }}</td>
                        <td class="px-4 py-3 font-bold">TZS {{ \App\Support\NumberFormatter::money($row->stock_value) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row->reorder_level) }}</td>
                        <td class="px-4 py-3">
                            <span class="{{ $row->stock_status === 'in_stock' ? 'badge-success' : ($row->stock_status === 'low_stock' ? 'badge-warning' : 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300') }}">{{ str($row->stock_status)->replace('_', ' ')->title() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No stock records found.</td></tr>
                @endforelse
            </x-table>
        @endif

        <div class="mt-4">{{ $rows->links() }}</div>
    </x-card>

    <x-modal name="stock-ledger" maxWidth="4xl">
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Stock Ledger</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $ledgerProduct?->name }} / {{ $ledgerLocation?->name }}</p>
        </div>
        <div class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
            <x-table :headers="['Date', 'Type', 'In', 'Out', 'Cost', 'Reference', 'Closing Balance']">
                @forelse ($ledgerMovements as $movement)
                    @php
                        $signed = $movement->signedQuantity();
                        $runningBalance += $signed;
                    @endphp
                    <tr>
                        <td class="px-4 py-3">{{ $movement->movement_date?->format('d M Y') }}</td>
                        <td class="px-4 py-3"><span class="badge-info">{{ str($movement->movement_type)->replace('_', ' ')->title() }}</span></td>
                        <td class="px-4 py-3 text-emerald-700">{{ $signed > 0 ? \App\Support\NumberFormatter::quantity($signed) : '-' }}</td>
                        <td class="px-4 py-3 text-red-700">{{ $signed < 0 ? \App\Support\NumberFormatter::quantity(abs($signed)) : '-' }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($movement->unit_cost) }}</td>
                        <td class="px-4 py-3 text-xs">{{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}</td>
                        <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($runningBalance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No ledger movements found.</td></tr>
                @endforelse
            </x-table>
        </div>
    </x-modal>
</div>
