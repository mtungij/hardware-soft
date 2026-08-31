<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['branch_id' => '', 'transfer_number' => '', 'from_location_id' => '', 'to_location_id' => '', 'transfer_date' => '', 'notes' => '', 'items' => []]);

$authorizedBranches = function () {
    $user = auth()->user();
    $query = Branch::withoutGlobalScopes()
        ->where('company_id', $user->company_id)
        ->where('status', 'active');

    if (AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY) {
        $query->whereKey($user->branch_id);
    }

    return $query->orderByDesc('is_default')->orderBy('name')->get();
};

$transferSourceLocations = function () {
    if (! $this->branch_id) {
        return collect();
    }

    return AuthorizationScope::stockLocationsForBranch(auth()->user(), 'can_transfer', (int) $this->branch_id)
        ->filter(fn (StockLocation $location) => $location->can_transfer && $location->can_issue_stock)
        ->values();
};

$transferDestinationLocations = function () {
    if (! $this->branch_id) {
        return collect();
    }

    return AuthorizationScope::stockLocationsForBranch(auth()->user(), 'can_receive', (int) $this->branch_id)
        ->filter(fn (StockLocation $location) => $location->can_receive_stock && (int) $location->id !== (int) $this->from_location_id)
        ->values();
};

mount(function (InventoryService $inventory) {
    $branches = $this->authorizedBranches();
    $preferredBranch = $branches->firstWhere('id', auth()->user()->branch_id) ?? $branches->first();
    $this->branch_id = (string) ($preferredBranch?->id ?? '');
    $this->transfer_number = $inventory->generateTransferNumber();
    $this->from_location_id = (string) ($this->transferSourceLocations()->first()?->id ?? '');
    $this->to_location_id = (string) ($this->transferDestinationLocations()->first()?->id ?? '');
    $this->transfer_date = now()->toDateString();
    $this->items = [['product_id' => '', 'quantity' => '1', 'notes' => '']];
});

$updatedBranchId = function () {
    if (! $this->authorizedBranches()->contains('id', (int) $this->branch_id)) {
        $this->branch_id = '';
        $this->from_location_id = '';
        $this->to_location_id = '';

        return;
    }

    $this->from_location_id = (string) ($this->transferSourceLocations()->first()?->id ?? '');
    $this->to_location_id = (string) ($this->transferDestinationLocations()->first()?->id ?? '');
    $this->items = collect($this->items)->map(fn (array $item) => [
        ...$item,
        'product_id' => '',
    ])->all();
};

$updatedFromLocationId = function () {
    if (! $this->transferSourceLocations()->contains('id', (int) $this->from_location_id)) {
        $this->from_location_id = '';
    }

    if (! $this->transferDestinationLocations()->contains('id', (int) $this->to_location_id)) {
        $this->to_location_id = (string) ($this->transferDestinationLocations()->first()?->id ?? '');
    }
};

$addItem = function () {
    $this->items[] = ['product_id' => '', 'quantity' => '1', 'notes' => ''];
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
};

$availableQuantity = function (?string $productId) {
    if (! $productId || ! $this->from_location_id || ! $this->branch_id) {
        return 0;
    }

    return app(InventoryService::class)->getProductStock((int) $productId, (int) $this->from_location_id, (int) $this->branch_id);
};

$saveTransfer = function (string $status, InventoryService $inventory) {
    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'transfer_number' => ['required', 'string', 'max:255', 'unique:stock_transfers,transfer_number'],
        'from_location_id' => ['required', 'exists:stock_locations,id'],
        'to_location_id' => ['required', 'exists:stock_locations,id', 'different:from_location_id'],
        'transfer_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'exists:products,id'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.notes' => ['nullable', 'string', 'max:1000'],
    ]);

    $productIds = collect($validated['items'])->pluck('product_id');
    if ($productIds->duplicates()->isNotEmpty()) {
        throw ValidationException::withMessages(['items' => 'Duplicate product rows are not allowed.']);
    }

    $from = StockLocation::findOrFail($validated['from_location_id']);
    $to = StockLocation::findOrFail($validated['to_location_id']);
    if (! $this->authorizedBranches()->contains('id', (int) $validated['branch_id'])) {
        throw ValidationException::withMessages(['branch_id' => 'You are not authorized to transfer stock for this branch.']);
    }
    if (! $this->transferSourceLocations()->contains('id', (int) $from->id)) {
        throw ValidationException::withMessages(['from_location_id' => 'You are not authorized to transfer stock from this location.']);
    }
    if (! $this->transferDestinationLocations()->contains('id', (int) $to->id)) {
        throw ValidationException::withMessages(['to_location_id' => 'You are not authorized to receive stock at this location.']);
    }
    if (! $from->isActive() || ! $to->isActive()) {
        throw ValidationException::withMessages(['location' => 'Transfers require active locations.']);
    }
    if (! $from->can_transfer || ! $from->can_issue_stock) {
        throw ValidationException::withMessages(['from_location_id' => 'Source location is not allowed to transfer stock.']);
    }
    if (! $to->can_receive_stock) {
        throw ValidationException::withMessages(['to_location_id' => 'Destination location is not allowed to receive stock.']);
    }
    if (($to->is_dispensing_location || $to->type === 'dispensing') && ! $from->can_transfer_to_dispensing) {
        throw ValidationException::withMessages(['from_location_id' => 'Source location cannot transfer to Dispensing.']);
    }

    foreach ($validated['items'] as $item) {
        $product = Product::findOrFail($item['product_id']);
        if ($product->status !== 'active') {
            throw ValidationException::withMessages(['items' => 'Inactive products cannot be transferred.']);
        }
        $available = $inventory->getProductStock((int) $item['product_id'], (int) $validated['from_location_id'], (int) $validated['branch_id']);
        if ((float) $item['quantity'] > $available) {
            throw ValidationException::withMessages(['items' => "{$product->name} quantity exceeds available stock."]);
        }
    }

    $transfer = DB::transaction(function () use ($validated, $status) {
        $transfer = StockTransfer::create([
            'branch_id' => $validated['branch_id'],
            'transfer_number' => $validated['transfer_number'],
            'from_location_id' => $validated['from_location_id'],
            'to_location_id' => $validated['to_location_id'],
            'transfer_date' => $validated['transfer_date'],
            'status' => 'draft',
            'notes' => $validated['notes'],
            'created_by' => auth()->id(),
        ]);

        foreach ($validated['items'] as $item) {
            $transfer->items()->create($item);
        }

        return $transfer;
    });

    if ($status === 'completed') {
        $inventory->completeStockTransfer($transfer->id, auth()->id());
    }

    session()->flash('success', $status === 'completed' ? 'Transfer completed.' : 'Transfer saved as draft.');
    $this->redirectRoute('stock-transfers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Stock Transfer" description="Move stock between authorized stock locations." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), 'Create' => null]" />

    <x-card>
        <form class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                    <select wire:model.live="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach ($this->authorizedBranches() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <x-form-input label="Transfer Number" name="transfer_number" wire:model="transfer_number" required />
                <x-form-input label="Transfer Date" name="transfer_date" type="date" wire:model="transfer_date" required />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">From Location
                    <select wire:model.live="from_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach ($this->transferSourceLocations() as $location)
                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">To Location
                    <select wire:model.live="to_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach ($this->transferDestinationLocations() as $location)
                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <x-table :headers="['Product', 'Available Source', 'Unit', 'Transfer Qty', 'Notes', '']">
                @foreach ($items as $index => $item)
                    @php
                        $product = $item['product_id'] ? Product::with(['unit', 'size'])->find($item['product_id']) : null;
                        $available = $this->availableQuantity($item['product_id']);
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <select wire:model.live="items.{{ $index }}.product_id" class="w-72 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                <option value="">Select product</option>
                                @foreach (Product::with('size')->where('status', 'active')->orderBy('name')->get() as $productOption)
                                    <option value="{{ $productOption->id }}">{{ $productOption->displayNameWithSize() }} / {{ $productOption->sku }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($available) }}</td>
                        <td class="px-4 py-3">{{ $product?->unit?->short_name ?? '-' }}</td>
                        <td class="px-4 py-3"><input wire:model="items.{{ $index }}.quantity" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3"><input wire:model="items.{{ $index }}.notes" class="w-56 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">Remove</button></td>
                    </tr>
                @endforeach
            </x-table>

            <button type="button" wire:click="addItem" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Add Item</button>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
                <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="saveTransfer('draft')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Save as Draft</button>
                <button type="button" wire:click="saveTransfer('completed')" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Complete Transfer</button>
                <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
