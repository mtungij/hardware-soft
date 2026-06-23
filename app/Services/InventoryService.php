<?php

namespace App\Services;

use App\Models\GoodsReceivingNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function getMainStoreLocation(int $branchId): StockLocation
    {
        return StockLocation::query()
            ->where('branch_id', $branchId)
            ->where('type', 'store')
            ->where('code', 'MAIN-STORE')
            ->firstOrFail();
    }

    public function getDispensingLocation(int $branchId): StockLocation
    {
        return StockLocation::query()
            ->where('branch_id', $branchId)
            ->where('type', 'dispensing')
            ->where('code', 'DISPENSING')
            ->firstOrFail();
    }

    public function getProductStock(int $productId, int $stockLocationId, int $branchId): float
    {
        return (float) StockMovement::query()
            ->where('product_id', $productId)
            ->where('stock_location_id', $stockLocationId)
            ->where('branch_id', $branchId)
            ->get()
            ->sum(fn (StockMovement $movement) => $movement->signedQuantity());
    }

    public function getProductTotalStock(int $productId, int $branchId): float
    {
        return (float) StockMovement::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->get()
            ->sum(fn (StockMovement $movement) => $movement->signedQuantity());
    }

    public function getStoreStock(int $productId, int $branchId): float
    {
        $location = $this->getMainStoreLocation($branchId);

        return $this->getProductStock($productId, $location->id, $branchId);
    }

    public function getDispensingStock(int $productId, int $branchId): float
    {
        $location = $this->getDispensingLocation($branchId);

        return $this->getProductStock($productId, $location->id, $branchId);
    }

    public function getAverageCost(int $productId, int $stockLocationId, int $branchId): float
    {
        $incoming = StockMovement::query()
            ->where('product_id', $productId)
            ->where('stock_location_id', $stockLocationId)
            ->where('branch_id', $branchId)
            ->whereIn('movement_type', StockMovement::POSITIVE_TYPES)
            ->whereNotNull('unit_cost')
            ->get();

        $quantity = (float) $incoming->sum('quantity');

        if ($quantity <= 0) {
            return 0;
        }

        $value = $incoming->sum(fn (StockMovement $movement) => (float) $movement->quantity * (float) $movement->unit_cost);

        return round($value / $quantity, 2);
    }

    public function generatePurchaseReference(): string
    {
        return 'PO-'.now()->format('Ymd').'-'.str_pad((string) (Purchase::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    public function generateGrnNumber(): string
    {
        return 'GRN-'.now()->format('Ymd').'-'.str_pad((string) (GoodsReceivingNote::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    public function generateTransferNumber(): string
    {
        return 'TRF-'.now()->format('Ymd').'-'.str_pad((string) (StockTransfer::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    public function generateSaleNumber(): string
    {
        return 'SALE-'.now()->format('Ymd').'-'.str_pad((string) (Sale::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function completeSale(array $cart, array $payments, ?int $customerId, int $stockLocationId, int $branchId, int $createdBy, ?string $notes = null): Sale
    {
        return DB::transaction(function () use ($cart, $payments, $customerId, $stockLocationId, $branchId, $createdBy, $notes) {
            if ($cart === []) {
                throw ValidationException::withMessages(['cart' => 'Cart is required.']);
            }

            $location = StockLocation::query()->whereKey($stockLocationId)->lockForUpdate()->firstOrFail();

            if ($location->status !== 'active') {
                throw ValidationException::withMessages(['stock_source' => 'Cannot sell from an inactive stock location.']);
            }

            $containsCredit = collect($payments)->contains(fn ($payment) => ($payment['payment_method'] ?? null) === 'credit');

            if ($containsCredit && ! $customerId) {
                throw ValidationException::withMessages(['customer_id' => 'Credit sale requires a customer.']);
            }

            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $preparedItems = [];

            foreach ($cart as $row) {
                $product = Product::query()->whereKey($row['product_id'] ?? null)->lockForUpdate()->firstOrFail();

                if ($product->status !== 'active') {
                    throw ValidationException::withMessages(['cart' => "{$product->name} is inactive."]);
                }

                $quantity = (float) ($row['quantity'] ?? 0);
                $unitPrice = (float) ($row['unit_price'] ?? $product->selling_price);
                $itemDiscount = (float) ($row['discount_amount'] ?? 0);
                $itemTax = (float) ($row['tax_amount'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['cart' => 'Quantity must be greater than zero.']);
                }

                $gross = $quantity * $unitPrice;

                if ($itemDiscount > $gross) {
                    throw ValidationException::withMessages(['cart' => 'Discount cannot exceed item total.']);
                }

                $available = $this->getProductStock($product->id, $location->id, $branchId);

                if ($quantity > $available) {
                    throw ValidationException::withMessages(['cart' => "{$product->name} quantity exceeds available stock."]);
                }

                $lineTotal = $gross - $itemDiscount + $itemTax;
                $subtotal += $gross;
                $discount += $itemDiscount;
                $tax += $itemTax;

                $preparedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $itemDiscount,
                    'tax_amount' => $itemTax,
                    'line_total' => $lineTotal,
                    'unit_cost' => $this->getAverageCost($product->id, $location->id, $branchId),
                ];
            }

            $total = max(0, $subtotal - $discount + $tax);
            $paid = collect($payments)
                ->reject(fn ($payment) => ($payment['payment_method'] ?? null) === 'credit')
                ->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));
            $creditAmount = collect($payments)
                ->where('payment_method', 'credit')
                ->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));
            $paymentTotal = $paid + $creditAmount;

            if ($paid < 0 || $creditAmount < 0) {
                throw ValidationException::withMessages(['payments' => 'Paid amount cannot be negative.']);
            }

            if ($containsCredit && $customerId) {
                $customer = \App\Models\Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

                if ($creditAmount <= 0) {
                    $creditAmount = max(0, $total - $paid);
                }

                if ($paid + $creditAmount < $total) {
                    throw ValidationException::withMessages(['payments' => 'Credit amount must cover the outstanding sale balance.']);
                }

                if ($paid + $creditAmount > $total) {
                    throw ValidationException::withMessages(['payments' => 'Paid amount cannot exceed total for credit sales.']);
                }

                if ((float) $customer->balance_amount + $creditAmount > (float) $customer->credit_limit) {
                    throw ValidationException::withMessages(['customer_id' => 'Customer credit limit exceeded.']);
                }
            }

            $balance = max(0, $total - $paid);
            $change = max(0, $paid - $total);
            $paymentStatus = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

            $sale = Sale::create([
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'sale_number' => $this->generateSaleNumber(),
                'sale_date' => now()->toDateString(),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'paid_amount' => min($paid, $total),
                'balance_amount' => $balance,
                'change_amount' => $change,
                'payment_status' => $paymentStatus,
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            foreach ($preparedItems as $item) {
                $saleItem = $sale->items()->create([
                    'product_id' => $item['product']->id,
                    'stock_location_id' => $location->id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => $item['line_total'],
                ]);

                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $item['product']->id,
                    'stock_location_id' => $location->id,
                    'movement_type' => 'sale_out',
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'unit_price' => $item['unit_price'],
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'notes' => "Sale {$sale->sale_number} / item {$saleItem->id}",
                    'created_by' => $createdBy,
                    'movement_date' => $sale->sale_date,
                ]);
            }

            foreach ($payments as $payment) {
                $method = $payment['payment_method'] ?? null;
                $amount = (float) ($payment['amount'] ?? 0);

                if (! $method || $amount <= 0) {
                    continue;
                }

                $sale->payments()->create([
                    'payment_method' => $method,
                    'amount' => $amount,
                    'reference_number' => $payment['reference_number'] ?? null,
                    'received_by' => $createdBy,
                    'payment_date' => now()->toDateString(),
                ]);
            }

            if ($containsCredit && $customerId && $balance > 0) {
                \App\Models\Customer::whereKey($customerId)->increment('balance_amount', $balance);
            }

            return $sale->refresh();
        });
    }

    public function cancelSale(int $saleId, int $cancelledBy): Sale
    {
        return DB::transaction(function () use ($saleId, $cancelledBy) {
            $sale = Sale::query()->with(['items'])->whereKey($saleId)->lockForUpdate()->firstOrFail();

            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Only completed sales can be cancelled.']);
            }

            foreach ($sale->items as $item) {
                StockMovement::create([
                    'branch_id' => $sale->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $item->stock_location_id,
                    'movement_type' => 'return_in',
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                    'unit_price' => $item->unit_price,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'notes' => "Cancelled sale {$sale->sale_number}",
                    'created_by' => $cancelledBy,
                    'movement_date' => now()->toDateString(),
                ]);
            }

            if ($sale->customer_id && (float) $sale->balance_amount > 0) {
                \App\Models\Customer::whereKey($sale->customer_id)->decrement('balance_amount', (float) $sale->balance_amount);
            }

            $sale->update([
                'status' => 'cancelled',
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => now(),
            ]);

            return $sale->refresh();
        });
    }

    /**
     * @param  array<int, float|int|string|null>  $receivedQuantities
     */
    public function receivePurchase(Purchase $purchase, array $receivedQuantities, string $receivedDate, int $receivedBy, ?string $notes = null): GoodsReceivingNote
    {
        return DB::transaction(function () use ($purchase, $receivedQuantities, $receivedDate, $receivedBy, $notes) {
            $purchase = Purchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status === 'cancelled') {
                throw ValidationException::withMessages(['purchase' => 'Cancelled purchases cannot be received.']);
            }

            $location = $this->getMainStoreLocation($purchase->branch_id);

            if ($location->type !== 'store') {
                throw ValidationException::withMessages(['location' => 'Phase 3 receiving is limited to Main Store only.']);
            }

            $items = PurchaseItem::query()
                ->where('purchase_id', $purchase->id)
                ->lockForUpdate()
                ->get();

            $receivable = [];

            foreach ($items as $item) {
                $quantity = (float) ($receivedQuantities[$item->id] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $item->remainingQuantity()) {
                    throw ValidationException::withMessages([
                        "receivedQuantities.{$item->id}" => 'Receiving quantity cannot exceed remaining quantity.',
                    ]);
                }

                $receivable[] = [$item, $quantity];
            }

            if ($receivable === []) {
                throw ValidationException::withMessages(['receivedQuantities' => 'Enter at least one quantity to receive.']);
            }

            $grn = GoodsReceivingNote::create([
                'branch_id' => $purchase->branch_id,
                'purchase_id' => $purchase->id,
                'grn_number' => $this->generateGrnNumber(),
                'stock_location_id' => $location->id,
                'received_date' => $receivedDate,
                'received_by' => $receivedBy,
                'notes' => $notes,
            ]);

            foreach ($receivable as [$item, $quantity]) {
                $grnItem = $grn->items()->create([
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'received_quantity' => $quantity,
                    'cost_price' => $item->cost_price,
                ]);

                StockMovement::create([
                    'branch_id' => $purchase->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $location->id,
                    'movement_type' => 'purchase_in',
                    'quantity' => $quantity,
                    'unit_cost' => $item->cost_price,
                    'unit_price' => $item->selling_price,
                    'reference_type' => GoodsReceivingNote::class,
                    'reference_id' => $grn->id,
                    'notes' => "Purchase {$purchase->reference_number} / {$grnItem->id}",
                    'created_by' => $receivedBy,
                    'movement_date' => $receivedDate,
                ]);

                $item->increment('received_quantity', $quantity);
            }

            $purchase->refresh();
            $fullyReceived = $purchase->items()->get()->every(fn (PurchaseItem $item) => (float) $item->received_quantity >= (float) $item->ordered_quantity);

            $purchase->update([
                'status' => $fullyReceived ? 'received' : 'ordered',
                'received_by' => $receivedBy,
                'received_at' => now(),
            ]);

            return $grn;
        });
    }

    public function approveAdjustment(StockAdjustment $adjustment, int $approvedBy): StockMovement
    {
        return DB::transaction(function () use ($adjustment, $approvedBy) {
            $adjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== 'pending') {
                throw ValidationException::withMessages(['adjustment' => 'Only pending adjustments can be approved.']);
            }

            if (in_array($adjustment->adjustment_type, StockMovement::NEGATIVE_TYPES, true)) {
                $available = $this->getProductStock($adjustment->product_id, $adjustment->stock_location_id, $adjustment->branch_id);

                if ((float) $adjustment->quantity > $available) {
                    throw ValidationException::withMessages(['quantity' => 'Adjustment would create negative stock.']);
                }
            }

            $movement = StockMovement::create([
                'branch_id' => $adjustment->branch_id,
                'product_id' => $adjustment->product_id,
                'stock_location_id' => $adjustment->stock_location_id,
                'movement_type' => $adjustment->adjustment_type,
                'quantity' => $adjustment->quantity,
                'reference_type' => StockAdjustment::class,
                'reference_id' => $adjustment->id,
                'notes' => $adjustment->reason,
                'created_by' => $approvedBy,
                'movement_date' => now()->toDateString(),
            ]);

            $adjustment->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $movement;
        });
    }

    public function completeStockTransfer(int $stockTransferId, int $completedBy): StockTransfer
    {
        return DB::transaction(function () use ($stockTransferId, $completedBy) {
            $transfer = StockTransfer::query()
                ->with(['items.product'])
                ->whereKey($stockTransferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status === 'completed') {
                throw ValidationException::withMessages(['transfer' => 'Transfer has already been completed.']);
            }

            if ($transfer->status === 'cancelled') {
                throw ValidationException::withMessages(['transfer' => 'Cancelled transfers cannot be completed.']);
            }

            if ($transfer->from_location_id === $transfer->to_location_id) {
                throw ValidationException::withMessages(['to_location_id' => 'From and To locations must be different.']);
            }

            $fromLocation = StockLocation::query()->whereKey($transfer->from_location_id)->lockForUpdate()->firstOrFail();
            $toLocation = StockLocation::query()->whereKey($transfer->to_location_id)->lockForUpdate()->firstOrFail();

            if ($fromLocation->status !== 'active' || $toLocation->status !== 'active') {
                throw ValidationException::withMessages(['location' => 'Transfers require active stock locations.']);
            }

            if ($fromLocation->type !== 'store' || $toLocation->type !== 'dispensing') {
                throw ValidationException::withMessages(['location' => 'Phase 4 transfers must move from Main Store to Dispensing Area.']);
            }

            $items = StockTransferItem::query()
                ->with('product')
                ->where('stock_transfer_id', $transfer->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Transfer requires at least one item.']);
            }

            foreach ($items as $item) {
                if ($item->product?->status !== 'active') {
                    throw ValidationException::withMessages(['product' => 'Inactive products cannot be transferred.']);
                }

                $available = $this->getProductStock($item->product_id, $fromLocation->id, $transfer->branch_id);

                if ((float) $item->quantity > $available) {
                    throw ValidationException::withMessages([
                        'quantity' => "{$item->product?->name} transfer quantity exceeds Main Store stock.",
                    ]);
                }
            }

            foreach ($items as $item) {
                StockMovement::create([
                    'branch_id' => $transfer->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $fromLocation->id,
                    'movement_type' => 'transfer_out',
                    'quantity' => $item->quantity,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'notes' => "Transfer {$transfer->transfer_number} out",
                    'created_by' => $completedBy,
                    'movement_date' => $transfer->transfer_date,
                ]);

                StockMovement::create([
                    'branch_id' => $transfer->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $toLocation->id,
                    'movement_type' => 'transfer_in',
                    'quantity' => $item->quantity,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'notes' => "Transfer {$transfer->transfer_number} in",
                    'created_by' => $completedBy,
                    'movement_date' => $transfer->transfer_date,
                ]);
            }

            $transfer->update([
                'status' => 'completed',
                'completed_by' => $completedBy,
                'completed_at' => now(),
            ]);

            return $transfer->refresh();
        });
    }
}
