<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMaterialAccount;
use App\Models\CustomerMaterialAudit;
use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\CustomerMaterialPlanLine;
use App\Models\CustomerMaterialTransaction;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerMaterialAccountService
{
    public function create(array $data, array $lines, int $actorId): CustomerMaterialAccount
    {
        return DB::transaction(function () use ($data, $lines, $actorId): CustomerMaterialAccount {
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Add at least one planned material.']);
            }

            $companyId = (int) $data['company_id'];
            $branchId = (int) $data['branch_id'];
            Branch::query()->where('company_id', $companyId)->findOrFail($branchId);
            Customer::query()->where('company_id', $companyId)->findOrFail($data['customer_id']);

            $account = CustomerMaterialAccount::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'customer_id' => $data['customer_id'],
                'reference_number' => $this->nextNumber($companyId, 'customer_material_account', 'CMA'),
                'project_name' => $data['project_name'],
                'description' => $data['description'] ?? null,
                'project_location' => $data['project_location'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'activated_at' => ($data['status'] ?? 'draft') === 'active' ? now() : null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            foreach ($lines as $index => $line) {
                $this->createPlanLine($account, $line, $index, $actorId);
            }

            $this->audit($account, 'account_created', $account, null, $account->only(['reference_number', 'project_name', 'status']), null, $actorId);

            return $account->load(['customer', 'branch', 'planLines.product']);
        });
    }

    public function activate(CustomerMaterialAccount $account, int $actorId): CustomerMaterialAccount
    {
        return DB::transaction(function () use ($account, $actorId): CustomerMaterialAccount {
            $account = CustomerMaterialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($account->status !== 'draft' || ! $account->planLines()->exists()) {
                throw ValidationException::withMessages(['account' => 'Only a draft account with a material plan can be activated.']);
            }
            $account->update(['status' => 'active', 'activated_at' => now(), 'updated_by' => $actorId]);
            $this->audit($account, 'account_activated', $account, ['status' => 'draft'], ['status' => 'active'], null, $actorId);

            return $account;
        });
    }

    public function amendPlanLine(CustomerMaterialPlanLine $line, float $plannedQuantity, float $agreedUnitPrice, ?string $reason, int $actorId): CustomerMaterialPlanLine
    {
        return DB::transaction(function () use ($line, $plannedQuantity, $agreedUnitPrice, $reason, $actorId): CustomerMaterialPlanLine {
            $account = CustomerMaterialAccount::query()->whereKey($line->customer_material_account_id)->lockForUpdate()->firstOrFail();
            if (! in_array($account->status, ['draft', 'active'], true)) {
                throw ValidationException::withMessages(['plan' => 'Only draft or active accounts can be amended.']);
            }
            $line = CustomerMaterialPlanLine::query()->where('customer_material_account_id', $account->id)->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $issuedQuantity = (float) $line->issueLines()->lockForUpdate()->sum('quantity');
            if ($plannedQuantity <= 0 || $plannedQuantity + 0.0000001 < $issuedQuantity) {
                throw ValidationException::withMessages(['planned_quantity' => "Planned quantity cannot be below the already issued quantity of {$issuedQuantity}."]);
            }
            if ($agreedUnitPrice < 0) {
                throw ValidationException::withMessages(['agreed_unit_price' => 'Agreed unit price cannot be negative.']);
            }
            if ($account->transactions()->exists() && blank($reason)) {
                throw ValidationException::withMessages(['amendment_reason' => 'A reason is required after a deposit or material issue exists.']);
            }
            $old = $line->only(['planned_quantity', 'planned_base_quantity', 'agreed_unit_price', 'planned_line_total', 'revision']);
            $line->update([
                'planned_quantity' => $plannedQuantity,
                'planned_base_quantity' => round($plannedQuantity * (float) $line->conversion_factor_snapshot, 12),
                'agreed_unit_price' => round($agreedUnitPrice, 2),
                'planned_line_total' => round($plannedQuantity * $agreedUnitPrice, 2),
                'revision' => $line->revision + 1,
                'amendment_reason' => $reason,
                'updated_by' => $actorId,
            ]);
            $this->audit($account, 'plan_line_amended', $line, $old, $line->only(array_keys($old)), $reason, $actorId);

            return $line;
        });
    }

    public function recordDeposit(CustomerMaterialAccount $account, array $data, int $actorId, string $idempotencyKey): CustomerMaterialCashTransaction
    {
        return DB::transaction(function () use ($account, $data, $actorId, $idempotencyKey): CustomerMaterialCashTransaction {
            $existing = CustomerMaterialCashTransaction::query()->where('company_id', $account->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_material_account_id !== (int) $account->id || $existing->transaction_type !== 'deposit') {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for another transaction.']);
                }

                return $existing;
            }

            $account = CustomerMaterialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->requireActive($account);
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Deposit amount must be greater than zero.']);
            }
            $branchId = (int) ($data['branch_id'] ?? $account->branch_id);
            $this->validateBranch($account, $branchId);
            $reference = $this->nextNumber($account->company_id, 'customer_material_deposit', 'CMD');
            $cash = CustomerMaterialCashTransaction::create([
                'company_id' => $account->company_id, 'customer_material_account_id' => $account->id,
                'branch_id' => $branchId, 'transaction_type' => 'deposit', 'reference_number' => $reference,
                'idempotency_key' => $idempotencyKey, 'amount' => $amount,
                'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null,
                'transacted_at' => $data['transacted_at'] ?? now(), 'notes' => $data['notes'] ?? null, 'received_by' => $actorId,
            ]);
            $this->statement($account, 'deposit', $reference, $amount, 0, $cash, 'Customer material deposit', $data['notes'] ?? null, $cash->transacted_at, $actorId, $branchId);
            $this->audit($account, 'deposit_recorded', $cash, null, ['amount' => $amount, 'reference' => $reference], null, $actorId);

            return $cash;
        });
    }

    public function refund(CustomerMaterialAccount $account, array $data, int $actorId, string $idempotencyKey): CustomerMaterialCashTransaction
    {
        return DB::transaction(function () use ($account, $data, $actorId, $idempotencyKey): CustomerMaterialCashTransaction {
            $existing = CustomerMaterialCashTransaction::query()->where('company_id', $account->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_material_account_id !== (int) $account->id || $existing->transaction_type !== 'refund') {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for another transaction.']);
                }

                return $existing;
            }
            $account = CustomerMaterialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! in_array($account->status, ['active', 'cancelled'], true)) {
                throw ValidationException::withMessages(['account' => 'Refunds are allowed only on active or cancelled accounts.']);
            }
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $available = $this->lockedFundedBalance($account);
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Refund cannot exceed the unused funded balance of TZS '.number_format($available, 2).'.']);
            }
            if (blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'A refund reason is required.']);
            }
            $branchId = (int) ($data['branch_id'] ?? $account->branch_id);
            $this->validateBranch($account, $branchId);
            $reference = $this->nextNumber($account->company_id, 'customer_material_refund', 'CMR');
            $cash = CustomerMaterialCashTransaction::create([
                'company_id' => $account->company_id, 'customer_material_account_id' => $account->id,
                'branch_id' => $branchId, 'transaction_type' => 'refund', 'reference_number' => $reference,
                'idempotency_key' => $idempotencyKey, 'amount' => $amount,
                'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null,
                'transacted_at' => $data['transacted_at'] ?? now(), 'notes' => $data['notes'] ?? null,
                'reason' => $data['reason'], 'received_by' => $actorId,
            ]);
            $this->statement($account, 'deposit_refund', $reference, 0, $amount, $cash, 'Unused deposit refund', $data['reason'], $cash->transacted_at, $actorId, $branchId);
            $this->audit($account, 'deposit_refunded', $cash, null, ['amount' => $amount, 'reference' => $reference], $data['reason'], $actorId);

            return $cash;
        });
    }

    public function issue(CustomerMaterialAccount $account, array $rows, int $stockLocationId, array $data, int $actorId, string $idempotencyKey): CustomerMaterialIssue
    {
        return DB::transaction(function () use ($account, $rows, $stockLocationId, $data, $actorId, $idempotencyKey): CustomerMaterialIssue {
            $existing = CustomerMaterialIssue::query()->where('company_id', $account->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_material_account_id !== (int) $account->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for another transaction.']);
                }

                return $existing->load('lines');
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['lines' => 'Add at least one material to issue.']);
            }

            $account = CustomerMaterialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->requireActive($account);
            $location = StockLocation::query()->where('company_id', $account->company_id)->whereKey($stockLocationId)->lockForUpdate()->firstOrFail();
            if ((int) $location->branch_id !== (int) $account->branch_id || ! $location->isActive() || ! $location->can_issue_stock) {
                throw ValidationException::withMessages(['stock_location_id' => 'Select an active issue-enabled stock location in the account branch.']);
            }

            $prepared = [];
            $totalValue = 0.0;
            $totalCost = 0.0;
            foreach ($rows as $index => $row) {
                $line = CustomerMaterialPlanLine::query()->where('customer_material_account_id', $account->id)->whereKey($row['plan_line_id'] ?? null)->lockForUpdate()->firstOrFail();
                $quantity = (float) ($row['quantity'] ?? 0);
                $issued = (float) $line->issueLines()->lockForUpdate()->sum('quantity');
                $remaining = round((float) $line->planned_quantity - $issued, 12);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Issue quantity must be greater than zero.']);
                }
                if ($quantity > $remaining + 0.0000001) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "Issue quantity exceeds the remaining planned quantity of {$remaining} {$line->unit_code_snapshot}."]);
                }
                $baseQuantity = round($quantity * (float) $line->conversion_factor_snapshot, 12);
                $product = Product::query()->where('company_id', $account->company_id)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                if (! $product->acceptsStockQuantity($baseQuantity)) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => $product->displayNameWithSize().' requires a valid base-stock quantity.']);
                }
                StockMovement::query()->where('company_id', $account->company_id)->where('branch_id', $account->branch_id)->where('product_id', $product->id)->where('stock_location_id', $location->id)->lockForUpdate()->get();
                $available = app(InventoryService::class)->getProductStock($product->id, $location->id, $account->branch_id);
                if ($baseQuantity > $available + 0.0000001) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "Insufficient stock. Available: {$available} {$line->base_unit_code_snapshot}; required: {$baseQuantity}."]);
                }
                $value = round($quantity * (float) $line->agreed_unit_price, 2);
                $baseCost = app(InventoryService::class)->getAverageCost($product->id, $location->id, $account->branch_id);
                $cost = round($baseQuantity * $baseCost, 2);
                $prepared[] = compact('line', 'product', 'quantity', 'baseQuantity', 'value', 'baseCost', 'cost');
                $totalValue += $value;
                $totalCost += $cost;
            }

            $availableFunds = $this->lockedFundedBalance($account);
            if ($totalValue > $availableFunds + 0.001) {
                throw ValidationException::withMessages(['funded_balance' => 'Insufficient funded balance. Available: TZS '.number_format($availableFunds, 2).'. Requested material value: TZS '.number_format($totalValue, 2).'.']);
            }

            $reference = $this->nextNumber($account->company_id, 'customer_material_issue', 'CMI');
            $issue = CustomerMaterialIssue::create([
                'company_id' => $account->company_id, 'customer_material_account_id' => $account->id,
                'branch_id' => $account->branch_id, 'stock_location_id' => $location->id,
                'reference_number' => $reference, 'posting_reference' => 'CMA-ISSUE:'.$reference,
                'idempotency_key' => $idempotencyKey, 'total_value' => round($totalValue, 2), 'total_cost' => round($totalCost, 2),
                'collected_by' => $data['collected_by'] ?? null, 'issued_at' => $data['issued_at'] ?? now(),
                'notes' => $data['notes'] ?? null, 'issued_by' => $actorId,
            ]);

            foreach ($prepared as $offset => $item) {
                $line = $item['line'];
                $issueLine = $issue->lines()->create([
                    'company_id' => $account->company_id, 'customer_material_plan_line_id' => $line->id,
                    'product_id' => $line->product_id, 'transaction_unit_id' => $line->transaction_unit_id, 'base_unit_id' => $line->base_unit_id,
                    'product_name_snapshot' => $line->product_name_snapshot, 'unit_name_snapshot' => $line->unit_name_snapshot,
                    'unit_code_snapshot' => $line->unit_code_snapshot, 'base_unit_name_snapshot' => $line->base_unit_name_snapshot,
                    'base_unit_code_snapshot' => $line->base_unit_code_snapshot, 'conversion_factor_snapshot' => $line->conversion_factor_snapshot,
                    'quantity' => $item['quantity'], 'base_quantity' => $item['baseQuantity'], 'agreed_unit_price' => $line->agreed_unit_price,
                    'line_value' => $item['value'], 'base_unit_cost' => $item['baseCost'], 'line_cost' => $item['cost'],
                ]);
                StockMovement::create([
                    'company_id' => $account->company_id, 'branch_id' => $account->branch_id, 'product_id' => $line->product_id,
                    'stock_location_id' => $location->id, 'movement_type' => 'sale_out', 'quantity' => $item['baseQuantity'],
                    'quantity_in' => 0, 'quantity_out' => $item['baseQuantity'], 'unit_cost' => $item['baseCost'],
                    'unit_price' => $line->agreed_unit_price, 'reference_type' => CustomerMaterialIssue::class, 'reference_id' => $issue->id,
                    'posting_reference' => $issue->posting_reference.':'.($offset + 1), 'notes' => "Material issue {$reference} / line {$issueLine->id}",
                    'created_by' => $actorId, 'movement_date' => $issue->issued_at->toDateString(),
                ]);
            }

            $description = $issue->lines->map(fn ($line) => $line->product_name_snapshot.' '.rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.').' '.$line->unit_code_snapshot)->join(', ');
            $this->statement($account, 'material_issue', $reference, 0, $totalValue, $issue, $description, $data['notes'] ?? null, $issue->issued_at, $actorId, $account->branch_id);
            $this->audit($account, 'materials_issued', $issue, null, ['value' => $totalValue, 'cost' => $totalCost, 'reference' => $reference], null, $actorId);

            if ($account->remainingProjectCommitment() <= 0.001 && $account->availableFundedBalance() <= 0.001) {
                $account->update(['status' => 'completed', 'closed_at' => now(), 'closed_by' => $actorId, 'updated_by' => $actorId]);
            }

            return $issue->load('lines');
        });
    }

    public function cancel(CustomerMaterialAccount $account, string $reason, int $actorId): CustomerMaterialAccount
    {
        return DB::transaction(function () use ($account, $reason, $actorId): CustomerMaterialAccount {
            $account = CustomerMaterialAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! in_array($account->status, ['draft', 'active'], true) || blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'An active or draft account requires a cancellation reason.']);
            }
            $old = $account->status;
            $account->update(['status' => 'cancelled', 'closed_at' => now(), 'closed_by' => $actorId, 'updated_by' => $actorId]);
            $this->audit($account, 'account_cancelled', $account, ['status' => $old], ['status' => 'cancelled'], $reason, $actorId);

            return $account;
        });
    }

    private function createPlanLine(CustomerMaterialAccount $account, array $data, int $index, int $actorId): CustomerMaterialPlanLine
    {
        $product = Product::query()->with('unit')->where('company_id', $account->company_id)->findOrFail($data['product_id'] ?? null);
        $conversion = app(ProductUnitConversionService::class)->resolveForSale($product, filled($data['product_unit_conversion_id'] ?? null) ? (int) $data['product_unit_conversion_id'] : null);
        $quantity = (float) ($data['planned_quantity'] ?? 0);
        $price = round((float) ($data['agreed_unit_price'] ?? 0), 2);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(["lines.{$index}.planned_quantity" => 'Planned quantity must be greater than zero.']);
        }
        if ($price < 0) {
            throw ValidationException::withMessages(["lines.{$index}.agreed_unit_price" => 'Agreed price cannot be negative.']);
        }
        $factor = $conversion ? (float) $conversion->conversion_factor : 1.0;
        $unit = $conversion?->unit ?: $product->unit;
        $baseQuantity = round($quantity * $factor, 12);
        if (! $product->acceptsStockQuantity($baseQuantity)) {
            throw ValidationException::withMessages(["lines.{$index}.planned_quantity" => 'Planned quantity does not convert to a valid base-stock quantity.']);
        }

        return $account->planLines()->create([
            'company_id' => $account->company_id, 'product_id' => $product->id, 'product_unit_conversion_id' => $conversion?->id,
            'transaction_unit_id' => $unit->id, 'base_unit_id' => $product->unit_id,
            'product_name_snapshot' => $product->displayNameWithSize(), 'unit_name_snapshot' => $unit->name,
            'unit_code_snapshot' => $unit->short_name, 'base_unit_name_snapshot' => $product->unit->name,
            'base_unit_code_snapshot' => $product->unit->short_name, 'conversion_factor_snapshot' => $factor,
            'planned_quantity' => $quantity, 'planned_base_quantity' => $baseQuantity,
            'agreed_unit_price' => $price, 'planned_line_total' => round($quantity * $price, 2),
            'created_by' => $actorId, 'updated_by' => $actorId,
        ]);
    }

    private function lockedFundedBalance(CustomerMaterialAccount $account): float
    {
        $rows = CustomerMaterialTransaction::query()->where('customer_material_account_id', $account->id)->lockForUpdate()->get();

        return round((float) $rows->sum('credit_amount') - (float) $rows->sum('debit_amount'), 2);
    }

    private function requireActive(CustomerMaterialAccount $account): void
    {
        if ($account->status !== 'active') {
            throw ValidationException::withMessages(['account' => 'Deposits and material issues are allowed only on active accounts.']);
        }
    }

    private function validateBranch(CustomerMaterialAccount $account, int $branchId): void
    {
        if ($branchId !== (int) $account->branch_id) {
            throw ValidationException::withMessages(['branch_id' => 'Transaction branch must match the material account branch.']);
        }
    }

    private function statement(CustomerMaterialAccount $account, string $type, string $reference, float $credit, float $debit, Model $source, string $description, ?string $notes, mixed $at, int $actorId, int $branchId): void
    {
        CustomerMaterialTransaction::create(['company_id' => $account->company_id, 'customer_material_account_id' => $account->id, 'branch_id' => $branchId, 'transaction_type' => $type, 'reference_number' => $reference, 'credit_amount' => $credit, 'debit_amount' => $debit, 'source_type' => $source::class, 'source_id' => $source->getKey(), 'description' => $description, 'notes' => $notes, 'transacted_at' => $at, 'created_by' => $actorId]);
    }

    private function audit(CustomerMaterialAccount $account, string $action, Model $subject, ?array $old, ?array $new, ?string $reason, int $actorId): void
    {
        CustomerMaterialAudit::create(['company_id' => $account->company_id, 'customer_material_account_id' => $account->id, 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'old_values' => $old, 'new_values' => $new, 'reason' => $reason, 'actor_id' => $actorId]);
    }

    private function nextNumber(int $companyId, string $type, string $prefix): string
    {
        $year = (int) now()->format('Y');
        DocumentSequence::query()->firstOrCreate(['company_id' => $companyId, 'document_type' => $type, 'year' => $year], ['last_number' => 0]);
        $sequence = DocumentSequence::query()->where('company_id', $companyId)->where('document_type', $type)->where('year', $year)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return $prefix.'-'.$year.'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
    }
}
