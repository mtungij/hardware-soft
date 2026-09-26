<?php

namespace App\Services;

use App\Models\GoodsReceivingNote;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockLedgerReadService
{
    public function authorize(User $user, Product $product, StockLocation $location): void
    {
        abort_unless($user->can('stock.view'), 403);
        abort_unless((int) $product->company_id === (int) $user->company_id && (int) $location->company_id === (int) $user->company_id, 404);
        abort_unless($product->status === 'active' && $location->status === 'active' && $location->is_active, 404);
        abort_unless(AuthorizationScope::canAccessStockLocation($user, $location->id), 403);
    }

    public function key(int $productId, int $locationId): string
    {
        return $productId.':'.$locationId;
    }

    /**
     * Latest movement metadata for an already-scoped page or export result.
     * The supplied row quantity remains the authoritative current balance.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, array<string, mixed>>
     */
    public function latestForRows(Collection $rows, User $user): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ranked = DB::table('stock_movements as movements')
            ->join('stock_locations as locations', 'locations.id', '=', 'movements.stock_location_id')
            ->whereColumn('movements.branch_id', 'locations.branch_id')
            ->where('movements.company_id', $user->company_id)
            ->where('locations.company_id', $user->company_id)
            ->whereIn('movements.product_id', $rows->pluck('product_id')->unique()->all())
            ->whereIn('movements.stock_location_id', $rows->pluck('stock_location_id')->unique()->all())
            ->select('movements.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY movements.product_id, movements.stock_location_id ORDER BY movements.movement_date DESC, movements.id DESC) as movement_rank');

        $ids = DB::query()->fromSub($ranked, 'ranked_movements')->where('movement_rank', 1)->pluck('id')->all();
        $movements = StockMovement::query()->whereIn('id', $ids)->get();
        $references = $this->referenceLabels($movements);
        $byPair = $movements->keyBy(fn (StockMovement $movement) => $this->key($movement->product_id, $movement->stock_location_id));
        $result = [];

        foreach ($rows as $row) {
            $key = $this->key((int) $row->product_id, (int) $row->stock_location_id);
            $movement = $byPair->get($key);
            $result[$key] = $this->summary($movement, (float) $row->quantity, $references);
        }

        return $result;
    }

    /** @return array{current: float, latest: array<string, mixed>, history: Collection<int, array<string, mixed>>} */
    public function ledger(Product $product, StockLocation $location, User $user): array
    {
        $this->authorize($user, $product, $location);
        $movements = StockMovement::query()
            ->where('company_id', $user->company_id)
            ->where('product_id', $product->id)
            ->where('stock_location_id', $location->id)
            ->when($location->branch_id !== null, fn ($query) => $query->where('branch_id', $location->branch_id))
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
        $references = $this->referenceLabels($movements);
        $balance = 0.0;
        $history = $movements->map(function (StockMovement $movement) use (&$balance, $references): array {
            $before = $balance;
            $change = $movement->signedQuantity();
            $balance = round($balance + $change, 4);

            return [
                'movement' => $movement,
                'before' => $before,
                'change' => $change,
                'after' => $balance,
                'action' => $this->actionLabel($movement->movement_type),
                'reference' => $references[$movement->id] ?? '—',
            ];
        });
        $current = app(InventoryService::class)->getProductStock($product->id, $location->id, $location->branch_id);

        return [
            'current' => $current,
            'latest' => $this->summary($movements->last(), $current, $references),
            'history' => $history,
        ];
    }

    public function actionLabel(string $type): string
    {
        return match ($type) {
            'purchase_in', 'purchase_receipt' => 'Purchase Receipt',
            'purchase_receipt_reversal' => 'Purchase Receipt Reversal',
            'sale_out' => 'Sale Out',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            'opening_stock' => 'Opening Stock',
            'adjustment_in', 'adjustment_out' => 'Stock Adjustment',
            'production_output' => 'Production In',
            'production_consumption' => 'Production Consumption',
            'curing_release_in' => 'Curing Release In',
            'curing_release_out' => 'Curing Release Out',
            'curing_damage' => 'Curing Damage',
            'return_in' => 'Return In',
            'direct_stock_in' => 'Direct Stock In',
            'damage_out' => 'Damage Out',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }

    /** @param array<int, string> $references
     * @return array<string, mixed>
     */
    private function summary(?StockMovement $movement, float $current, array $references): array
    {
        if (! $movement) {
            return ['action' => 'No movement', 'before' => null, 'change' => null, 'after' => $current, 'reference' => '—', 'date' => null];
        }

        $change = $movement->signedQuantity();

        return [
            'action' => $this->actionLabel($movement->movement_type),
            'before' => round($current - $change, 4),
            'change' => $change,
            'after' => $current,
            'reference' => $references[$movement->id] ?? '—',
            'date' => $movement->movement_date,
        ];
    }

    /** @param Collection<int, StockMovement> $movements
     * @return array<int, string>
     */
    private function referenceLabels(Collection $movements): array
    {
        $models = [
            Sale::class => ['sale_number', 'Sale'],
            GoodsReceivingNote::class => ['grn_number', 'GRN'],
            StockTransfer::class => ['transfer_number', 'Transfer'],
            OpeningStock::class => ['reference_number', 'Opening Stock'],
            StockAdjustment::class => ['reference_number', 'Adjustment'],
            Purchase::class => ['reference_number', 'Purchase'],
        ];
        $numbers = [];
        foreach ($models as $type => [$column]) {
            $ids = $movements->where('reference_type', $type)->pluck('reference_id')->filter()->unique()->all();
            if ($ids !== []) {
                $numbers[$type] = $type::query()->whereIn('id', $ids)->pluck($column, 'id')->all();
            }
        }

        $labels = [];
        foreach ($movements as $movement) {
            $stored = $numbers[$movement->reference_type][$movement->reference_id] ?? null;
            $labels[$movement->id] = filled($stored)
                ? (string) $stored
                : (filled($movement->posting_reference)
                    ? (string) $movement->posting_reference
                    : ($movement->reference_type && $movement->reference_id
                        ? class_basename($movement->reference_type).' #'.$movement->reference_id
                        : '—'));
        }

        return $labels;
    }
}
