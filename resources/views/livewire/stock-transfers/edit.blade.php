<?php

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['stockTransfer' => null, 'branch_id' => '', 'transfer_number' => '', 'from_location_id' => '', 'to_location_id' => '', 'transfer_date' => '', 'notes' => '', 'items' => []]);

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
        throw ValidationException::withMessages(['items' => 'Duplicate product rows are not allowed.']);
    }

    foreach ($validated['items'] as $item) {
        $available = $inventory->getProductStock((int) $item['product_id'], (int) $validated['from_location_id'], (int) $this->branch_id);
        if ((float) $item['quantity'] > $available) {
            throw ValidationException::withMessages(['items' => 'Transfer quantity cannot exceed available Main Store stock.']);
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

    session()->flash('success', $status === 'completed' ? 'Transfer completed.' : 'Transfer updated.');
    $this->redirectRoute('stock-transfers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Edit Stock Transfer" description="Only draft transfers can be edited." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), 'Edit' => null]" />

    @include('livewire.stock-transfers.transfer-form')
</div>
