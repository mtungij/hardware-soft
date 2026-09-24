<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    public function eligibleLocations(User $user, int $branchId)
    {
        return AuthorizationScope::stockLocationsForBranch($user, 'can_receive', $branchId)
            ->filter(fn (StockLocation $location): bool => $location->isActive()
                && $location->can_receive_stock
                && app(InventoryService::class)->canUserReceiveIntoLocation($user, $location))
            ->values();
    }

    public function nextReference(User $user, string $openingDate): string
    {
        $date = date('Ymd', strtotime($openingDate));
        $prefix = 'OPEN-'.$date.'-';
        $last = OpeningStock::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('reference_number', 'like', $prefix.'%')
            ->orderByDesc('reference_number')
            ->value('reference_number');
        $number = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): OpeningStock
    {
        if (! $user->can('opening_stock.create')) {
            throw new AuthorizationException('You cannot create Opening Stock.');
        }
        if (! (bool) Setting::withoutGlobalScopes()->where('company_id', $user->company_id)->value('enable_warehouse')) {
            throw ValidationException::withMessages(['opening_stock' => 'Opening Stock is available only when Warehouse is enabled.']);
        }

        $data = Validator::make($input, [
            'branch_id' => ['required', 'integer'],
            'stock_location_id' => ['required', 'integer'],
            'opening_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_unit_conversion_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:255'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($data, $user): OpeningStock {
            $companyId = (int) $user->company_id;
            // Serializes company-scoped reference generation on databases that support row locks.
            Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $branch = Branch::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('status', 'active')->find($data['branch_id']);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'Select an active branch in your company.']);
            }
            $branchId = (int) $branch->id;
            $location = StockLocation::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('status', 'active')->where('is_active', true)
                ->where('can_receive_stock', true)->find($data['stock_location_id']);
            if (! $location || ($location->branch_id !== null && (int) $location->branch_id !== $branchId)
                || ! $this->eligibleLocations($user, $branchId)->contains('id', $location->id)) {
                throw ValidationException::withMessages(['stock_location_id' => 'Select an active receiving location you may use for this branch.']);
            }

            $prepared = [];
            foreach (array_values($data['lines']) as $index => $line) {
                $product = Product::withoutGlobalScopes()->with('unit.measurementType')
                    ->where('company_id', $companyId)->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
                    ->whereKey($line['product_id'])->lockForUpdate()->first();
                if (! $product || ! $product->unit) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Select an active product in this company and branch.']);
                }

                $batchNumber = filled($line['batch_number'] ?? null) ? trim((string) $line['batch_number']) : null;
                $expiryDate = filled($line['expiry_date'] ?? null) ? (string) $line['expiry_date'] : null;
                $traceability = Validator::make(
                    ['batch_number' => $batchNumber, 'expiry_date' => $expiryDate],
                    [
                        'batch_number' => $product->tracks_batch ? ['required', 'string', 'max:255'] : ['nullable'],
                        'expiry_date' => $product->tracks_expiry
                            ? ['required', 'date', 'after_or_equal:'.$data['opening_date']]
                            : ['nullable'],
                    ],
                );
                if ($traceability->fails()) {
                    $messages = [];
                    foreach ($traceability->errors()->messages() as $field => $errors) {
                        $messages["lines.{$index}.{$field}"] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }

                try {
                    $normalized = app(ProductUnitConversionService::class)->normalizePurchase(
                        $product,
                        filled($line['product_unit_conversion_id'] ?? null) ? (int) $line['product_unit_conversion_id'] : null,
                        $line['quantity'],
                        $line['unit_cost'],
                        true,
                    );
                } catch (ValidationException $exception) {
                    $messages = [];
                    foreach ($exception->errors() as $field => $errors) {
                        $target = match ($field) {
                            'quantity' => 'quantity',
                            'cost_price' => 'unit_cost',
                            default => 'product_unit_conversion_id',
                        };
                        $messages["lines.{$index}.{$target}"] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }

                $conversion = $normalized['conversion'];
                $unit = $conversion?->unit ?: $product->unit;
                $baseUnitCost = round($normalized['base_unit_cost'], 2);
                if ($baseUnitCost <= 0) {
                    throw ValidationException::withMessages(["lines.{$index}.unit_cost" => 'Unit cost is too small after conversion to the base unit.']);
                }
                $prepared[] = [
                    'product' => $product,
                    'conversion' => $conversion,
                    'unit' => $unit,
                    'transaction_quantity' => $normalized['transaction_quantity'],
                    'conversion_factor' => $normalized['conversion_factor'],
                    'base_quantity' => $normalized['base_quantity'],
                    'unit_cost' => round((float) $line['unit_cost'], 2),
                    'base_unit_cost' => $baseUnitCost,
                    'total_cost' => round($normalized['base_quantity'] * $baseUnitCost, 2),
                    'batch_number' => $product->tracks_batch || $product->tracks_expiry ? $batchNumber : null,
                    'expiry_date' => $product->tracks_expiry ? $expiryDate : null,
                    'notes' => $line['notes'] ?? null,
                ];
            }

            $reference = $this->nextReference($user, $data['opening_date']);
            $opening = OpeningStock::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'stock_location_id' => $location->id,
                'opening_date' => $data['opening_date'],
                'reference_number' => $reference,
                'notes' => $data['notes'] ?? null,
                'total_products' => count(array_unique(array_map(fn (array $row): int => $row['product']->id, $prepared))),
                'total_base_quantity' => round(array_sum(array_column($prepared, 'base_quantity')), 4),
                'total_value' => round(array_sum(array_column($prepared, 'total_cost')), 2),
                'created_by' => $user->id,
                'posted_at' => now(),
            ]);

            foreach ($prepared as $row) {
                $opening->lines()->create([
                    'product_id' => $row['product']->id,
                    'product_unit_conversion_id' => $row['conversion']?->id,
                    'transaction_unit_id' => $row['unit']->id,
                    'transaction_unit_name_snapshot' => $row['unit']->name,
                    'transaction_unit_code_snapshot' => $row['unit']->short_name,
                    'transaction_quantity' => $row['transaction_quantity'],
                    'conversion_factor_snapshot' => $row['conversion_factor'],
                    'base_quantity' => $row['base_quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'base_unit_cost' => $row['base_unit_cost'],
                    'total_cost' => $row['total_cost'],
                    'batch_number' => $row['batch_number'],
                    'expiry_date' => $row['expiry_date'],
                    'notes' => $row['notes'],
                ]);
                StockMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'product_id' => $row['product']->id,
                    'product_unit_conversion_id' => $row['conversion']?->id,
                    'transaction_unit_id' => $row['unit']->id,
                    'transaction_unit_name_snapshot' => $row['unit']->name,
                    'transaction_unit_code_snapshot' => $row['unit']->short_name,
                    'stock_location_id' => $location->id,
                    'movement_type' => 'opening_stock',
                    'quantity' => $row['base_quantity'],
                    'quantity_in' => $row['base_quantity'],
                    'quantity_out' => 0,
                    'transaction_quantity' => $row['transaction_quantity'],
                    'conversion_factor_snapshot' => $row['conversion_factor'],
                    'unit_cost' => $row['base_unit_cost'],
                    'transaction_unit_cost' => $row['unit_cost'],
                    'reference_type' => OpeningStock::class,
                    'reference_id' => $opening->id,
                    'posting_reference' => $reference,
                    'notes' => $row['notes'] ?: ($data['notes'] ?? null),
                    'created_by' => $user->id,
                    'movement_date' => $data['opening_date'],
                ]);
            }

            return $opening->load(['lines.product', 'branch', 'stockLocation', 'creator']);
        });
    }
}
