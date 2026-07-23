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
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function getMainStoreLocation(int $branchId): StockLocation
    {
        $location = StockLocation::query()->firstOrCreate(
            [
                'branch_id' => $branchId,
                'type' => 'store',
                'code' => 'MAIN-STORE',
            ],
            [
                'name' => 'Main Store',
                'status' => 'active',
                'is_active' => true,
                'is_default' => true,
                'can_receive_stock' => true,
                'can_issue_stock' => true,
                'can_sell' => true,
                'can_transfer' => true,
                'can_transfer_to_dispensing' => true,
            ]
        );

        return $this->ensureLocationIsActive($location);
    }

    public function getDispensingLocation(int $branchId): StockLocation
    {
        $location = StockLocation::query()->firstOrCreate(
            [
                'branch_id' => $branchId,
                'type' => 'dispensing',
                'code' => 'DISPENSING',
            ],
            [
                'name' => 'Dispensing Area',
                'status' => 'active',
                'is_active' => true,
                'is_dispensing_location' => true,
                'can_receive_stock' => true,
                'can_issue_stock' => true,
                'can_sell' => true,
            ]
        );

        return $this->ensureLocationIsActive($location);
    }

    private function ensureLocationIsActive(StockLocation $location): StockLocation
    {
        if ($location->status !== 'active' || ! $location->is_active) {
            $location->forceFill(['status' => 'active', 'is_active' => true])->save();
        }

        return $location;
    }

    public function getProductStock(int $productId, int $stockLocationId, int $branchId): float
    {
        return $this->getProductStocks([$productId], $stockLocationId, $branchId)[$productId] ?? 0;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function getProductStocks(array $productIds, int $stockLocationId, int $branchId): array
    {
        if ($productIds === []) {
            return [];
        }

        $negativeTypePlaceholders = implode(',', array_fill(0, count(StockMovement::NEGATIVE_TYPES), '?'));

        return StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->where('stock_location_id', $stockLocationId)
            ->where('branch_id', $branchId)
            ->select('product_id')
            ->selectRaw(
                "SUM(CASE WHEN quantity_in <> 0 OR quantity_out <> 0 THEN quantity_in - quantity_out WHEN movement_type IN ({$negativeTypePlaceholders}) THEN -quantity ELSE quantity END) as available_quantity",
                StockMovement::NEGATIVE_TYPES,
            )
            ->groupBy('product_id')
            ->pluck('available_quantity', 'product_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
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
        return 'GRN-'.now()->format('Y').'-'.str_pad((string) (GoodsReceivingNote::whereYear('created_at', now()->year)->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    public function generateTransferNumber(): string
    {
        return 'TRF-'.now()->format('Ymd').'-'.str_pad((string) (StockTransfer::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    public function generateSaleNumber(): string
    {
        $prefix = 'SALE-'.now()->format('Ymd').'-';
        $nextNumber = Sale::whereDate('created_at', today())->count() + 1;

        do {
            $saleNumber = $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Sale::where('sale_number', $saleNumber)->exists());

        return $saleNumber;
    }

    public function directStockIn(array $data, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $product = Product::query()->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $location = StockLocation::query()->whereKey($data['stock_location_id'])->lockForUpdate()->firstOrFail();
            $quantity = (float) $data['quantity'];

            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
            }

            if ($location->status !== 'active') {
                throw ValidationException::withMessages(['stock_location_id' => 'Stock location must be active.']);
            }

            if (array_key_exists('selling_price', $data) && filled($data['selling_price'])) {
                $product->update(['selling_price' => (float) $data['selling_price']]);
            }

            return StockMovement::create([
                'branch_id' => (int) $data['branch_id'],
                'product_id' => $product->id,
                'stock_location_id' => $location->id,
                'movement_type' => 'direct_stock_in',
                'quantity' => $quantity,
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'unit_cost' => (float) $data['cost_price'],
                'unit_price' => filled($data['selling_price'] ?? null) ? (float) $data['selling_price'] : (float) $product->selling_price,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => trim(($data['reason'] ?? 'Direct Stock In').($data['notes'] ? ' - '.$data['notes'] : '')),
                'created_by' => $createdBy,
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            ]);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function completeSale(array $cart, array $payments, ?int $customerId, int $stockLocationId, int $branchId, int $createdBy, ?string $notes = null, bool $overrideCreditLimit = false, array $creditDetails = []): Sale
    {
        return DB::transaction(function () use ($cart, $payments, $customerId, $stockLocationId, $branchId, $createdBy, $notes, $creditDetails) {
            if ($cart === []) {
                throw ValidationException::withMessages(['cart' => 'Cart is required.']);
            }

            $location = StockLocation::query()->whereKey($stockLocationId)->lockForUpdate()->firstOrFail();

            if (! $location->isActive()) {
                throw ValidationException::withMessages(['stock_source' => 'Cannot sell from an inactive stock location.']);
            }

            if (! $location->can_sell) {
                throw ValidationException::withMessages(['stock_source' => 'Selected stock location is not allowed for sales.']);
            }

            if ((int) $location->branch_id !== $branchId) {
                throw ValidationException::withMessages(['stock_source' => 'Selected stock location does not belong to this branch.']);
            }

            $containsCredit = collect($payments)->contains(fn ($payment) => ($payment['payment_method'] ?? null) === 'credit');

            $creditCustomerUnassigned = false;

            if ($containsCredit && ! $customerId) {
                if (! (bool) (InventorySettings::current()->allow_credit_sale_without_customer ?? true)) {
                    throw ValidationException::withMessages(['customer_id' => \App\Support\UiText::translate('Select a customer or create a customer before completing this credit sale.')]);
                }

                $customerId = app(UnassignedCreditCustomerService::class)->forLocation($location)->id;
                $creditCustomerUnassigned = true;
            }

            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $preparedItems = [];

            foreach ($cart as $row) {
                $product = Product::query()->with(["category", "size"])->whereKey($row['product_id'] ?? null)->lockForUpdate()->firstOrFail();

                if ($product->status !== 'active') {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' is inactive.']);
                }

                $quantity = (float) ($row['quantity'] ?? 0);
                $saleType = ($row['sale_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
                $unitPrice = (float) ($saleType === 'wholesale' ? $product->wholesale_price : $product->selling_price);
                $discountPerUnit = (float) ($row['discount_per_unit'] ?? $row['discount_amount'] ?? 0);
                $taxPerUnit = (float) ($row['tax_amount'] ?? 0);
                $allowsFractionalSale = $product->supportsFractionalSales();
                $conversionFactor = $allowsFractionalSale ? max(0.0001, (float) ($product->conversion_factor ?: 1)) : 1.0;
                $baseQuantity = round($quantity / $conversionFactor, 4);
                $minimumSaleQuantity = $allowsFractionalSale ? (float) ($product->minimum_sale_quantity ?: 1) : 1.0;
                $quantityStep = $allowsFractionalSale ? (float) ($product->quantity_step ?: 1) : 1.0;

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['cart' => 'Quantity must be greater than zero.']);
                }

                if (! $allowsFractionalSale && abs($quantity - round($quantity)) > 0.0001) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' must be sold in whole quantities.']);
                }

                if ($quantity < $minimumSaleQuantity) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' minimum sale quantity is '.$minimumSaleQuantity.'.']);
                }

                if ($quantityStep > 0) {
                    $steps = ($quantity - $minimumSaleQuantity) / $quantityStep;

                    if (abs($steps - round($steps)) > 0.0001) {
                        throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' quantity must follow the configured step of '.$quantityStep.'.']);
                    }
                }

                if ($baseQuantity <= 0) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' converted base quantity must be greater than zero.']);
                }

                if ($saleType === 'wholesale' && blank($product->wholesale_price)) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' does not have a wholesale price.']);
                }

                $baseUnitCost = (float) $product->buying_price;
                $sellingUnitCost = $baseUnitCost / $conversionFactor;

                if ($unitPrice < $sellingUnitCost) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' price cannot be below buying price.']);
                }

                if ($discountPerUnit < 0) {
                    throw ValidationException::withMessages(['cart' => 'Discount per unit cannot be negative.']);
                }

                if ($discountPerUnit >= $unitPrice && $unitPrice > 0) {
                    throw ValidationException::withMessages(['cart' => \App\Support\UiText::translate('The discount per unit must be less than the unit price.')]);
                }

                $gross = $quantity * $unitPrice;
                $itemDiscount = $quantity * $discountPerUnit;
                $itemTax = $quantity * $taxPerUnit;
                $netUnitPrice = $unitPrice - $discountPerUnit;
                $netTotal = $quantity * $netUnitPrice;

                $available = $this->getProductStock($product->id, $location->id, $branchId);
                $availableSellingQuantity = $available * $conversionFactor;

                if ($baseQuantity > $available) {
                    throw ValidationException::withMessages(['cart' => $product->displayNameWithSize().' quantity exceeds available stock.']);
                }

                $lineTotal = $netTotal + $itemTax;
                $subtotal += $gross;
                $discount += $itemDiscount;
                $tax += $itemTax;

                $preparedItems[] = [
                    'product' => $product,
                    'sale_type' => $saleType,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'selling_unit_id' => $allowsFractionalSale ? ($product->selling_unit_id ?: $product->unit_id) : $product->unit_id,
                    'base_unit_id' => $product->unit_id,
                    'conversion_factor' => $conversionFactor,
                    'unit_price' => $unitPrice,
                    'discount_per_unit' => $discountPerUnit,
                    'discount_amount' => $itemDiscount,
                    'discount_total' => $itemDiscount,
                    'gross_total' => $gross,
                    'net_unit_price' => $netUnitPrice,
                    'net_total' => $netTotal,
                    'tax_amount' => $itemTax,
                    'line_total' => $lineTotal,
                    'unit_cost' => $this->getAverageCost($product->id, $location->id, $branchId) / $conversionFactor,
                    'base_unit_cost' => $this->getAverageCost($product->id, $location->id, $branchId),
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

            if ($containsCredit) {
                if ($creditAmount <= 0) {
                    $creditAmount = max(0, $total - $paid);
                }

                if ($paid + $creditAmount < $total) {
                    throw ValidationException::withMessages(['payments' => \App\Support\UiText::translate('Credit amount must cover the outstanding sale balance.')]);
                }

                if ($paid + $creditAmount > $total) {
                    throw ValidationException::withMessages(['payments' => \App\Support\UiText::translate('Paid amount cannot exceed total for credit sales.')]);
                }
            }

            $balance = max(0, $total - $paid);
            $change = max(0, $paid - $total);
            $paymentStatus = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
            $saleType = collect($preparedItems)->contains(fn (array $item) => $item['sale_type'] === 'wholesale') ? 'wholesale' : 'retail';

            $sale = Sale::create([
                'branch_id' => $branchId,
                'stock_location_id' => $location->id,
                'customer_id' => $customerId,
                'credit_customer_unassigned' => $creditCustomerUnassigned,
                'credit_assignment_status' => $creditCustomerUnassigned ? 'unassigned' : 'assigned',
                'temporary_customer_name' => $creditCustomerUnassigned ? ($creditDetails['temporary_customer_name'] ?? null) : null,
                'temporary_customer_phone' => $creditCustomerUnassigned ? ($creditDetails['temporary_customer_phone'] ?? null) : null,
                'project_name' => $creditCustomerUnassigned ? ($creditDetails['project_name'] ?? null) : null,
                'vehicle_number' => $creditCustomerUnassigned ? ($creditDetails['vehicle_number'] ?? null) : null,
                'expected_payment_date' => $creditCustomerUnassigned ? ($creditDetails['expected_payment_date'] ?? null) : null,
                'credit_notes' => $creditCustomerUnassigned ? ($creditDetails['credit_notes'] ?? null) : null,
                'sale_number' => $this->generateSaleNumber(),
                'sale_date' => now()->toDateString(),
                'sale_type' => $saleType,
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
                'sold_by' => $createdBy,
            ]);

            foreach ($preparedItems as $item) {
                $saleItem = $sale->items()->create([
                    'product_id' => $item['product']->id,
                    'stock_location_id' => $location->id,
                    'sold_from_label' => InventorySettings::stockLocationLabel($location),
                    'sale_type' => $item['sale_type'],
                    'quantity' => $item['quantity'],
                    'base_quantity' => $item['base_quantity'],
                    'selling_unit_id' => $item['selling_unit_id'],
                    'base_unit_id' => $item['base_unit_id'],
                    'conversion_factor' => $item['conversion_factor'],
                    'unit_cost' => $item['unit_cost'],
                    'unit_price' => $item['unit_price'],
                    'discount_per_unit' => $item['discount_per_unit'],
                    'discount_amount' => $item['discount_amount'],
                    'discount_total' => $item['discount_total'],
                    'gross_total' => $item['gross_total'],
                    'net_unit_price' => $item['net_unit_price'],
                    'net_total' => $item['net_total'],
                    'tax_amount' => $item['tax_amount'],
                    'line_total' => $item['line_total'],
                ]);

                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_id' => $item['product']->id,
                    'stock_location_id' => $location->id,
                    'movement_type' => 'sale_out',
                    'quantity' => $item['base_quantity'],
                    'quantity_in' => 0,
                    'quantity_out' => $item['base_quantity'],
                    'unit_cost' => $item['base_unit_cost'],
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
                $returnQuantity = (float) ($item->base_quantity ?: $item->quantity);

                StockMovement::create([
                    'branch_id' => $sale->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $item->stock_location_id,
                    'movement_type' => 'return_in',
                    'quantity' => $returnQuantity,
                    'quantity_in' => $returnQuantity,
                    'quantity_out' => 0,
                    'unit_cost' => (float) $item->unit_cost * (float) ($item->conversion_factor ?: 1),
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

    public function canUserReceiveIntoLocation(?\App\Models\User $user, StockLocation $location): bool
    {
        if (! $location->isActive() || ! $location->can_receive_stock) {
            return false;
        }

        if (! $user) {
            return false;
        }

        $hasPivotAccess = $user->stockLocations()
            ->where('stock_locations.id', $location->id)
            ->wherePivot('can_receive', true)
            ->exists();

        if ($hasPivotAccess) {
            return true;
        }

        return $user->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
    }

    /**
     * @param  array<int, mixed>  $receivedLines
     * @param  array<string, mixed>  $header
     */
    public function receivePurchase(Purchase $purchase, array $receivedLines, string $receivedDate, int $receivedBy, ?string $notes = null, array $header = []): GoodsReceivingNote
    {
        return DB::transaction(function () use ($purchase, $receivedLines, $receivedDate, $receivedBy, $notes, $header) {
            $purchase = Purchase::query()
                ->with(['items'])
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status === 'cancelled') {
                throw ValidationException::withMessages(['purchase' => 'Cancelled purchases cannot be received.']);
            }

            $items = PurchaseItem::query()
                ->where('purchase_id', $purchase->id)
                ->lockForUpdate()
                ->get();

            $receivable = [];

            foreach ($items as $item) {
                $rawLine = $receivedLines[$item->id] ?? null;
                $line = is_array($rawLine) ? $rawLine : ['quantity' => $rawLine];
                $quantity = (float) ($line['quantity'] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $previouslyReceived = (float) $item->received_quantity;
                $remaining = max(0, (float) $item->ordered_quantity - $previouslyReceived);

                if ($quantity > $item->remainingQuantity()) {
                    throw ValidationException::withMessages([
                        "lines.{$item->id}.quantity" => 'Receiving quantity cannot exceed remaining quantity.',
                    ]);
                }

                $locationId = (int) ($line['stock_location_id'] ?? $header['default_stock_location_id'] ?? InventorySettings::receivingLocation($purchase->branch_id)->id);
                $location = StockLocation::query()->whereKey($locationId)->lockForUpdate()->firstOrFail();

                if ((int) $location->branch_id !== (int) $purchase->branch_id) {
                    throw ValidationException::withMessages(["lines.{$item->id}.stock_location_id" => 'Receiving location does not belong to this purchase branch.']);
                }

                if (! $this->canUserReceiveIntoLocation(auth()->user(), $location)) {
                    throw ValidationException::withMessages(["lines.{$item->id}.stock_location_id" => 'You are not allowed to receive stock into this location.']);
                }

                $unitCost = (float) ($line['unit_cost'] ?? $item->cost_price);

                $receivable[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'previously_received' => $previouslyReceived,
                    'remaining' => $remaining,
                    'location' => $location,
                    'unit_cost' => $unitCost,
                    'batch_number' => $line['batch_number'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ];
            }

            if ($receivable === []) {
                throw ValidationException::withMessages(['lines' => 'Enter at least one quantity to receive.']);
            }

            $defaultLocationId = (int) ($header['default_stock_location_id'] ?? $receivable[0]['location']->id);

            $grn = GoodsReceivingNote::create([
                'branch_id' => $purchase->branch_id,
                'purchase_id' => $purchase->id,
                'grn_number' => $header['grn_number'] ?? $this->generateGrnNumber(),
                'stock_location_id' => $defaultLocationId,
                'default_stock_location_id' => $defaultLocationId,
                'received_date' => $receivedDate,
                'supplier_delivery_note_number' => $header['supplier_delivery_note_number'] ?? null,
                'supplier_invoice_number' => $header['supplier_invoice_number'] ?? null,
                'status' => $header['status'] ?? 'posted',
                'received_by' => $receivedBy,
                'posted_by' => ($header['status'] ?? 'posted') === 'posted' ? $receivedBy : null,
                'posted_at' => ($header['status'] ?? 'posted') === 'posted' ? now() : null,
                'notes' => $notes,
            ]);

            foreach ($receivable as $line) {
                /** @var PurchaseItem $item */
                $item = $line['item'];
                /** @var StockLocation $location */
                $location = $line['location'];
                $quantity = (float) $line['quantity'];
                $unitCost = (float) $line['unit_cost'];

                $grnItem = $grn->items()->create([
                    'branch_id' => $purchase->branch_id,
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $location->id,
                    'ordered_quantity' => $item->ordered_quantity,
                    'previously_received_quantity' => $line['previously_received'],
                    'received_quantity' => $quantity,
                    'cost_price' => $unitCost,
                    'unit_cost' => $unitCost,
                    'total_cost' => $quantity * $unitCost,
                    'batch_number' => $line['batch_number'],
                    'expiry_date' => $line['expiry_date'],
                    'notes' => $line['notes'],
                ]);

                if ($grn->status === 'posted') {
                    StockMovement::create([
                        'branch_id' => $purchase->branch_id,
                        'product_id' => $item->product_id,
                        'stock_location_id' => $location->id,
                        'movement_type' => 'purchase_receipt',
                        'quantity' => $quantity,
                        'quantity_in' => $quantity,
                        'quantity_out' => 0,
                        'unit_cost' => $unitCost,
                        'unit_price' => $item->selling_price,
                        'reference_type' => GoodsReceivingNote::class,
                        'reference_id' => $grn->id,
                        'notes' => "Purchase {$purchase->reference_number} / {$grnItem->id}",
                        'created_by' => $receivedBy,
                        'movement_date' => $receivedDate,
                    ]);

                    $item->increment('received_quantity', $quantity);
                }
            }

            $purchase->refresh();
            $fullyReceived = $purchase->items()->get()->every(fn (PurchaseItem $item) => (float) $item->received_quantity >= (float) $item->ordered_quantity);
            $partiallyReceived = $purchase->items()->where('received_quantity', '>', 0)->exists();

            $purchase->update([
                'status' => $fullyReceived ? 'received' : 'ordered',
                'received_by' => $partiallyReceived ? $receivedBy : $purchase->received_by,
                'received_at' => $partiallyReceived ? now() : $purchase->received_at,
            ]);

            return $grn->refresh();
        });
    }

    public function approveAdjustment(StockAdjustment $adjustment, int $approvedBy): StockAdjustment|StockMovement
    {
        return DB::transaction(function () use ($adjustment, $approvedBy) {
            $adjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($adjustment->status, ['pending', 'pending_approval'], true)) {
                throw ValidationException::withMessages(['adjustment' => 'Only pending adjustments can be approved.']);
            }

            if ($adjustment->lines()->exists()) {
                $adjustment->update([
                    'status' => 'approved',
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                ]);

                return $this->postStockAdjustment($adjustment, $approvedBy);
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
                'quantity_in' => in_array($adjustment->adjustment_type, StockMovement::POSITIVE_TYPES, true) ? $adjustment->quantity : 0,
                'quantity_out' => in_array($adjustment->adjustment_type, StockMovement::NEGATIVE_TYPES, true) ? $adjustment->quantity : 0,
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

    public function canUserAdjustLocation(?\App\Models\User $user, StockLocation $location): bool
    {
        if (! $user || ! $location->isActive()) {
            return false;
        }

        if ($user->can('view all stock locations') || $user->hasAnyRole(['Super Admin', 'Admin', 'Manager'])) {
            return true;
        }

        return $user->stockLocations()
            ->where('stock_locations.id', $location->id)
            ->wherePivot('can_adjust', true)
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createStockAdjustment(array $header, array $lines, int $createdBy): StockAdjustment
    {
        return DB::transaction(function () use ($header, $lines, $createdBy) {
            $location = StockLocation::query()->whereKey($header['stock_location_id'])->lockForUpdate()->firstOrFail();

            if (! $this->canUserAdjustLocation(auth()->user(), $location)) {
                throw ValidationException::withMessages(['stock_location_id' => 'You are not allowed to adjust stock in this location.']);
            }

            $prepared = [];
            $seenProducts = [];

            foreach ($lines as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                if (! $productId) {
                    continue;
                }

                if (in_array($productId, $seenProducts, true)) {
                    throw ValidationException::withMessages(['lines' => 'Duplicate product lines are not allowed.']);
                }

                $seenProducts[] = $productId;
                $product = Product::query()->whereKey($productId)->firstOrFail();

                if ((int) $product->company_id !== (int) $location->company_id) {
                    throw ValidationException::withMessages(['lines' => $product->displayNameWithSize().' does not belong to this company.']);
                }

                $systemQuantity = $this->getProductStock($product->id, $location->id, (int) $header['branch_id']);
                $physicalQuantity = (float) ($line['physical_quantity'] ?? 0);

                if ($physicalQuantity < 0) {
                    throw ValidationException::withMessages(['lines' => 'Physical quantity cannot be negative.']);
                }

                $difference = round($physicalQuantity - $systemQuantity, 2);
                $adjustmentType = $difference > 0 ? 'adjustment_in' : ($difference < 0 ? 'adjustment_out' : null);
                $reason = trim((string) ($line['reason'] ?? ''));
                $notes = trim((string) ($line['notes'] ?? ''));

                if ($reason === '') {
                    throw ValidationException::withMessages(['lines' => 'Reason is required for every adjustment line.']);
                }

                if (strtolower($reason) === 'other' && $notes === '') {
                    throw ValidationException::withMessages(['lines' => 'Written explanation is required when reason is Other.']);
                }

                $prepared[] = compact('product', 'systemQuantity', 'physicalQuantity', 'difference', 'adjustmentType', 'reason', 'notes');
            }

            if (collect($prepared)->every(fn (array $line) => (float) $line['difference'] === 0.0)) {
                throw ValidationException::withMessages(['lines' => 'At least one line must have a non-zero difference.']);
            }

            $firstMovementLine = collect($prepared)->first(fn (array $line) => (float) $line['difference'] !== 0.0);
            $approvalRequired = (bool) (\App\Support\InventorySettings::current()->stock_adjustment_approval_required ?? true);

            $adjustment = StockAdjustment::create([
                'company_id' => $location->company_id,
                'branch_id' => (int) $header['branch_id'],
                'stock_location_id' => $location->id,
                'product_id' => $firstMovementLine['product']->id,
                'reference_number' => $header['reference_number'],
                'adjustment_date' => $header['adjustment_date'],
                'adjustment_type' => $firstMovementLine['adjustmentType'],
                'quantity' => abs((float) $firstMovementLine['difference']),
                'reason' => $firstMovementLine['reason'],
                'notes' => $header['notes'] ?? null,
                'status' => $approvalRequired ? 'pending_approval' : 'pending',
                'requested_by' => $createdBy,
            ]);

            foreach ($prepared as $line) {
                $adjustment->lines()->create([
                    'product_id' => $line['product']->id,
                    'system_quantity' => $line['systemQuantity'],
                    'physical_quantity' => $line['physicalQuantity'],
                    'difference_quantity' => $line['difference'],
                    'adjustment_type' => $line['adjustmentType'],
                    'reason' => $line['reason'],
                    'notes' => $line['notes'] ?: null,
                ]);
            }

            if (! $approvalRequired) {
                $this->postStockAdjustment($adjustment, $createdBy);
            }

            return $adjustment->refresh();
        });
    }

    public function postStockAdjustment(StockAdjustment $adjustment, int $postedBy): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $postedBy) {
            $adjustment = StockAdjustment::query()->with(['lines.product'])->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if (in_array($adjustment->status, ['posted', 'cancelled', 'rejected'], true)) {
                throw ValidationException::withMessages(['adjustment' => 'Posted, cancelled, or rejected adjustments cannot be posted again.']);
            }

            $lines = $adjustment->lines;
            if ($lines->isEmpty()) {
                $lines = collect([(object) [
                    'product_id' => $adjustment->product_id,
                    'difference_quantity' => in_array($adjustment->adjustment_type, StockMovement::NEGATIVE_TYPES, true) ? -1 * (float) $adjustment->quantity : (float) $adjustment->quantity,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'reason' => $adjustment->reason,
                    'notes' => $adjustment->notes,
                ]]);
            }

            foreach ($lines as $line) {
                $difference = (float) $line->difference_quantity;
                if ($difference === 0.0) {
                    continue;
                }

                $type = $difference > 0 ? 'adjustment_in' : 'adjustment_out';
                $quantity = abs($difference);

                StockMovement::create([
                    'company_id' => $adjustment->company_id,
                    'branch_id' => $adjustment->branch_id,
                    'product_id' => $line->product_id,
                    'stock_location_id' => $adjustment->stock_location_id,
                    'movement_type' => $type,
                    'quantity' => $quantity,
                    'quantity_in' => $difference > 0 ? $quantity : 0,
                    'quantity_out' => $difference < 0 ? $quantity : 0,
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'notes' => trim($line->reason.($line->notes ? ' - '.$line->notes : '')),
                    'created_by' => $postedBy,
                    'movement_date' => $adjustment->adjustment_date ?: now()->toDateString(),
                ]);
            }

            $adjustment->update([
                'status' => 'posted',
                'approved_by' => $adjustment->approved_by ?: $postedBy,
                'approved_at' => $adjustment->approved_at ?: now(),
                'posted_by' => $postedBy,
                'posted_at' => now(),
            ]);

            return $adjustment->refresh();
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

            if (in_array($transfer->status, ['completed', 'received'], true)) {
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

            if (! $fromLocation->isActive() || ! $toLocation->isActive()) {
                throw ValidationException::withMessages(['location' => 'Transfers require active stock locations.']);
            }

            if (! $fromLocation->can_transfer || ! $fromLocation->can_issue_stock) {
                throw ValidationException::withMessages(['from_location_id' => 'Source location is not allowed to issue transfers.']);
            }

            if (! $toLocation->can_receive_stock) {
                throw ValidationException::withMessages(['to_location_id' => 'Destination location is not allowed to receive stock.']);
            }

            $user = \App\Models\User::query()->find($completedBy);
            if ($user?->stockLocations()->exists()) {
                $canTransferSource = $user->stockLocations()
                    ->where('stock_locations.id', $fromLocation->id)
                    ->wherePivot('can_transfer', true)
                    ->exists();
                $canReceiveDestination = $user->stockLocations()
                    ->where('stock_locations.id', $toLocation->id)
                    ->wherePivot('can_receive', true)
                    ->exists();

                if (! $canTransferSource) {
                    throw ValidationException::withMessages(['from_location_id' => 'You are not allowed to transfer stock from this location.']);
                }
                if (! $canReceiveDestination) {
                    throw ValidationException::withMessages(['to_location_id' => 'You are not allowed to receive stock into this destination.']);
                }
            }

            if (($toLocation->is_dispensing_location || $toLocation->type === 'dispensing') && ! $fromLocation->can_transfer_to_dispensing) {
                throw ValidationException::withMessages(['from_location_id' => 'Source location is not allowed to transfer to Dispensing.']);
            }

            $alreadyPosted = StockMovement::query()
                ->where('reference_type', StockTransfer::class)
                ->where('reference_id', $transfer->id)
                ->exists();

            if ($alreadyPosted) {
                throw ValidationException::withMessages(['transfer' => 'Transfer movements have already been posted.']);
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
                        'quantity' => $item->product?->displayNameWithSize().' transfer quantity exceeds available stock.',
                    ]);
                }
            }

            foreach ($items as $item) {
                StockMovement::create([
                    'branch_id' => $transfer->branch_id,
                    'product_id' => $item->product_id,
                    'stock_location_id' => $fromLocation->id,
                    'source_location_id' => $fromLocation->id,
                    'destination_location_id' => $toLocation->id,
                    'movement_type' => 'transfer_out',
                    'quantity' => $item->quantity,
                    'quantity_in' => 0,
                    'quantity_out' => $item->quantity,
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
                    'source_location_id' => $fromLocation->id,
                    'destination_location_id' => $toLocation->id,
                    'movement_type' => 'transfer_in',
                    'quantity' => $item->quantity,
                    'quantity_in' => $item->quantity,
                    'quantity_out' => 0,
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
