<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'stock_location_id' => '',
    'adjustment_date' => '',
    'reference_number' => '',
    'notes' => '',
    'lines' => [
        ['product_id' => '', 'system_quantity' => '0', 'physical_quantity' => '', 'reason' => 'Physical count difference', 'notes' => ''],
    ],
]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->adjustment_date = now()->toDateString();
    $this->reference_number = 'ADJ-'.now()->format('Ymd-His');

    $locations = collect(InventorySettings::allowedSaleLocationsForUser(auth()->user(), (int) $this->branch_id))
        ->filter(fn (StockLocation $location) => $inventory->canUserAdjustLocation(auth()->user(), $location))
        ->values();

    if ((InventorySettings::current()->inventory_mode ?? 'multi_location') === 'single_location' || $locations->count() === 1) {
        $this->stock_location_id = (string) ($locations->first()?->id ?: InventorySettings::defaultLocation((int) $this->branch_id)->id);
    }
});

$reasons = fn () => [
    'Physical count difference',
    'Damaged stock',
    'Expired stock',
    'Lost stock',
    'Data entry correction',
    'Breakage',
    'Unrecorded stock received',
    'Unrecorded stock issued',
    'Other',
];

$availableLocations = function () {
    $query = StockLocation::query()
        ->where('branch_id', $this->branch_id)
        ->where('status', 'active')
        ->where('is_active', true)
        ->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId));

    if (! auth()->user()?->can('view all stock locations')) {
        $query->whereIn('id', auth()->user()?->stockLocations()
            ->wherePivot('can_adjust', true)
            ->pluck('stock_locations.id') ?? []);
    }

    return $query->orderBy('name')->get();
};

$locationReady = fn () => filled($this->stock_location_id);

$systemQuantity = function (int $productId): float {
    if (! $this->locationReady() || ! $productId) {
        return 0;
    }

    return app(InventoryService::class)->getProductStock($productId, (int) $this->stock_location_id, (int) $this->branch_id);
};

$refreshLineQuantity = function (int $index) {
    $productId = (int) ($this->lines[$index]['product_id'] ?? 0);
    $this->lines[$index]['system_quantity'] = (string) $this->systemQuantity($productId);
};

$updatedStockLocationId = function () {
    foreach (array_keys($this->lines) as $index) {
        $this->refreshLineQuantity($index);
    }
};

$updatedLines = function () {
    foreach (array_keys($this->lines) as $index) {
        $this->refreshLineQuantity($index);
    }
};

$addLine = function () {
    $this->lines[] = ['product_id' => '', 'system_quantity' => '0', 'physical_quantity' => '', 'reason' => 'Physical count difference', 'notes' => ''];
};

$removeLine = function (int $index) {
    unset($this->lines[$index]);
    $this->lines = array_values($this->lines);
};

$save = function (InventoryService $inventory) {
    if (! $this->locationReady()) {
        throw ValidationException::withMessages(['stock_location_id' => 'Select Stock Location.']);
    }

    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'stock_location_id' => ['required', 'exists:stock_locations,id'],
        'adjustment_date' => ['required', 'date'],
        'reference_number' => ['required', 'string', 'max:255'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['required', 'array', 'min:1'],
        'lines.*.product_id' => ['required', 'exists:products,id'],
        'lines.*.physical_quantity' => ['required', 'numeric', 'min:0'],
        'lines.*.reason' => ['required', 'string', 'max:255'],
        'lines.*.notes' => ['nullable', 'string', 'max:1000'],
    ]);

    foreach ($validated['lines'] as $line) {
        if (($line['reason'] ?? '') === 'Other' && blank($line['notes'] ?? '')) {
            throw ValidationException::withMessages(['lines' => 'Written explanation is required when reason is Other.']);
        }
    }

    $inventory->createStockAdjustment(
        [
            'branch_id' => (int) $validated['branch_id'],
            'stock_location_id' => (int) $validated['stock_location_id'],
            'adjustment_date' => $validated['adjustment_date'],
            'reference_number' => $validated['reference_number'],
            'notes' => $validated['notes'] ?? null,
        ],
        $validated['lines'],
        auth()->id()
    );

    session()->flash('success', 'Stock adjustment submitted for approval.');
    $this->redirectRoute('stock-adjustments.index', navigate: true);
};

?>

<div>
    @php
        $locations = $this->availableLocations();
        $selectedLocation = $locations->firstWhere('id', (int) $stock_location_id);
        $singleLocationMode = (InventorySettings::current()->inventory_mode ?? 'multi_location') === 'single_location' || $locations->count() === 1;
        $products = $this->locationReady()
            ? Product::query()->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->where('status', 'active')->with('unit')->orderBy('name')->get()
            : collect();
        $lineSummaries = collect($lines)->map(function ($line) {
            $system = (float) ($line['system_quantity'] ?? 0);
            $physical = is_numeric($line['physical_quantity'] ?? null) ? (float) $line['physical_quantity'] : $system;
            return $physical - $system;
        });
    @endphp

    <x-page-header title="Create Stock Adjustment" description="Adjust stock for one selected location using system quantity from stock movements." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => route('stock-adjustments.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                    <select wire:model.live="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (Branch::query()->when(auth()->user()?->company_id, fn ($query, $companyId) => $query->where('company_id', $companyId))->orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($singleLocationMode)
                    <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                        Adjusting Stock At: {{ $selectedLocation?->name ?? 'Default Location' }}
                    </div>
                @else
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Stock Location
                        <select wire:model.live="stock_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Select Stock Location</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                        @error('stock_location_id') <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                <x-form-input label="Adjustment Date" name="adjustment_date" type="date" wire:model="adjustment_date" required />
                <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" required />
            </div>

            @if ($selectedLocation)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    You are adjusting stock for {{ $selectedLocation->name }} only. Stock in other locations will not be affected.
                </div>
            @endif

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    @php
                        $product = filled($line['product_id'] ?? '') ? Product::with(['unit', 'size'])->find($line['product_id']) : null;
                        $system = (float) ($line['system_quantity'] ?? 0);
                        $physical = is_numeric($line['physical_quantity'] ?? null) ? (float) $line['physical_quantity'] : $system;
                        $difference = $physical - $system;
                        $direction = $difference > 0 ? 'Adjustment In' : ($difference < 0 ? 'Adjustment Out' : 'No Movement');
                    @endphp
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                        <div class="grid gap-3 lg:grid-cols-7">
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 lg:col-span-2">Product
                                <select wire:model.live="lines.{{ $index }}.product_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-navy-950" @disabled(! $this->locationReady())>
                                    <option value="">{{ $this->locationReady() ? 'Select product' : 'Select Stock Location first' }}</option>
                                    @foreach ($products as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }} / {{ $option->sku }}</option>
                                    @endforeach
                                </select>
                                @error("lines.$index.product_id") <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                            </label>

                            <div class="text-sm"><span class="block font-bold text-slate-500">SKU</span><span class="mt-2 block">{{ $product?->sku ?? '-' }}</span></div>
                            <div class="text-sm"><span class="block font-bold text-slate-500">Unit</span><span class="mt-2 block">{{ $product?->unit?->short_name ?? '-' }}</span></div>
                            <div class="text-sm"><span class="block font-bold text-slate-500">System Quantity</span><span class="mt-2 block font-black">{{ \App\Support\NumberFormatter::quantity($system) }}</span></div>
                            <x-form-input label="Physical Quantity" name="lines.{{ $index }}.physical_quantity" type="number" step="0.01" wire:model.live="lines.{{ $index }}.physical_quantity" required />
                            <div class="text-sm"><span class="block font-bold text-slate-500">Difference</span><span class="mt-2 block font-black {{ $difference < 0 ? 'text-red-600' : ($difference > 0 ? 'text-emerald-600' : 'text-slate-500') }}">{{ \App\Support\NumberFormatter::quantity($difference) }}</span><span class="text-xs">{{ $direction }}</span></div>
                        </div>
                        <div class="mt-3 grid gap-3 md:grid-cols-[1fr_2fr_auto]">
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Reason
                                <select wire:model="lines.{{ $index }}.reason" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                    @foreach ($this->reasons() as $reason)
                                        <option value="{{ $reason }}">{{ $reason }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-form-input label="Line Notes" name="lines.{{ $index }}.notes" wire:model="lines.{{ $index }}.notes" />
                            <button type="button" wire:click="removeLine({{ $index }})" class="self-end rounded-xl border border-red-200 px-4 py-2.5 text-sm font-black text-red-600 dark:border-red-500/30">Remove</button>
                        </div>
                    </div>
                @endforeach
            </div>

            @error('lines') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

            <button type="button" wire:click="addLine" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Add Product</button>

            <div class="grid gap-4 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-800 md:grid-cols-4">
                <div><span class="text-slate-500">Total Products</span><p class="text-xl font-black">{{ count($lines) }}</p></div>
                <div><span class="text-slate-500">Total Increase</span><p class="text-xl font-black text-emerald-600">{{ \App\Support\NumberFormatter::quantity($lineSummaries->filter(fn ($value) => $value > 0)->sum()) }}</p></div>
                <div><span class="text-slate-500">Total Decrease</span><p class="text-xl font-black text-red-600">{{ \App\Support\NumberFormatter::quantity(abs($lineSummaries->filter(fn ($value) => $value < 0)->sum())) }}</p></div>
                <div><span class="text-slate-500">Selected Location</span><p class="text-xl font-black">{{ $selectedLocation?->name ?? 'Not selected' }}</p></div>
            </div>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Header Notes
                <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex gap-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white" onclick="return confirm('Post this stock adjustment request?')">Submit Adjustment</button>
                <a href="{{ route('stock-adjustments.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
