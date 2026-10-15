<?php

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['stockTransfer' => null, 'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'transfer_number' => '', 'from_location_id' => '', 'to_location_id' => '', 'transfer_date' => '', 'notes' => '', 'items' => []]);

mount(function (StockTransfer $stockTransfer) {
    abort_unless($stockTransfer->canBeModified(), 403);

    $this->stockTransfer = $stockTransfer->load('items');
    $this->branch_id = (string) $stockTransfer->branch_id;
    $this->transfer_number = $stockTransfer->transfer_number;
    $this->from_location_id = (string) $stockTransfer->from_location_id;
    $this->to_location_id = (string) $stockTransfer->to_location_id;
    $this->transfer_date = $stockTransfer->transfer_date->toDateString();
    $this->notes = $stockTransfer->notes;
    $this->items = $stockTransfer->items->map(fn ($item) => [
        'product_id' => (string) $item->product_id,
        'quantity' => (string) $item->quantity,
        'notes' => $item->notes,
    ])->all();
});

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

$transferSourceLocations = function () {
    $locations = auth()->user()?->permittedStockLocations('can_transfer', (int) $this->branch_id)
        ->filter(fn (StockLocation $location) => $location->can_transfer && $location->can_issue_stock && $location->isActive())
        ->values() ?? collect();

    if ($locations->isNotEmpty() || auth()->user()?->stockLocations()->exists()) {
        return $locations;
    }

    return StockLocation::where('branch_id', $this->branch_id)->where('status', 'active')->where('is_active', true)->where('can_transfer', true)->where('can_issue_stock', true)->orderBy('name')->get();
};

$transferDestinationLocations = function () {
    $locations = auth()->user()?->permittedStockLocations('can_receive', (int) $this->branch_id)
        ->filter(fn (StockLocation $location) => $location->can_receive_stock && $location->isActive())
        ->values() ?? collect();

    if ($locations->isNotEmpty() || auth()->user()?->stockLocations()->exists()) {
        return $locations;
    }

    return StockLocation::where('branch_id', $this->branch_id)->where('status', 'active')->where('is_active', true)->where('can_receive_stock', true)->orderBy('name')->get();
};

$saveTransfer = function (string $status, InventoryService $inventory) {
    abort_unless($this->stockTransfer->canBeModified(), 403);

    $validated = $this->validate([
        'transfer_number' => ['required', 'string', 'max:255', Rule::unique('stock_transfers', 'transfer_number')->ignore($this->stockTransfer->id)],
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
        throw ValidationException::withMessages(['items' => __('application.duplicate_product_rows_are_not_allowed_432e383d')]);
    }

    $from = StockLocation::findOrFail($validated['from_location_id']);
    $to = StockLocation::findOrFail($validated['to_location_id']);
    if (! $from->isActive() || ! $to->isActive()) {
        throw ValidationException::withMessages(['location' => __('application.transfers_require_active_locations_a1972fdf')]);
    }
    if (! $from->can_transfer || ! $from->can_issue_stock) {
        throw ValidationException::withMessages(['from_location_id' => __('application.source_location_is_not_allowed_to_transfer_stock_84226d24')]);
    }
    if (! $to->can_receive_stock) {
        throw ValidationException::withMessages(['to_location_id' => __('application.destination_location_is_not_allowed_to_receive_stock_7912fd1f')]);
    }
    if (($to->is_dispensing_location || $to->type === 'dispensing') && ! $from->can_transfer_to_dispensing) {
        throw ValidationException::withMessages(['from_location_id' => __('terminology.source_location_cannot_transfer_to_dispensing_3a9f5e91')]);
    }

    foreach ($validated['items'] as $item) {
        $available = $inventory->getProductStock((int) $item['product_id'], (int) $validated['from_location_id'], (int) $this->branch_id);
        if ((float) $item['quantity'] > $available) {
            throw ValidationException::withMessages(['items' => __('application.transfer_quantity_cannot_exceed_available_stock_d275529b')]);
        }
    }

    DB::transaction(function () use ($validated) {
        $this->stockTransfer->update([
            'transfer_number' => $validated['transfer_number'],
            'from_location_id' => $validated['from_location_id'],
            'to_location_id' => $validated['to_location_id'],
            'transfer_date' => $validated['transfer_date'],
            'notes' => $validated['notes'],
        ]);
        $this->stockTransfer->items()->delete();
        foreach ($validated['items'] as $item) {
            $this->stockTransfer->items()->create($item);
        }
    });

    if ($status === 'completed') {
        $inventory->completeStockTransfer($this->stockTransfer->id, auth()->id());
    }

    session()->flash('success', $status === 'completed' ? __('terminology.transfer_completed_8bf4316b') : __('terminology.transfer_updated_cf6835eb'));
    $this->redirectRoute('stock-transfers.index', navigate: true);
};

?>

<div>
    <x-page-header title="{{ __('application.edit_stock_transfer_e2e87d3f') }}" description="{{ __('application.only_draft_transfers_can_be_edited_d91ce782') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), 'Edit' => null]" />

    @include('livewire.stock-transfers.transfer-form')
</div>
