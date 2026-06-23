<?php

namespace App\Services;

use App\Models\CashbookSession;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\SalePayment;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashbookService
{
    public function totals(int $branchId, string $date): array
    {
        $cashSales = SalePayment::query()
            ->where('payment_method', 'cash')
            ->whereDate('payment_date', $date)
            ->whereHas('sale', fn ($query) => $query->where('branch_id', $branchId)->where('status', 'completed'))
            ->sum('amount');

        $customerPayments = CustomerPayment::query()
            ->where('branch_id', $branchId)
            ->where('payment_method', 'cash')
            ->whereDate('payment_date', $date)
            ->sum('amount');

        $supplierPayments = SupplierPayment::query()
            ->where('branch_id', $branchId)
            ->where('payment_method', 'cash')
            ->whereDate('payment_date', $date)
            ->sum('amount');

        $expenses = Expense::query()
            ->where('branch_id', $branchId)
            ->where('payment_method', 'cash')
            ->whereDate('expense_date', $date)
            ->sum('amount');

        return [
            'cash_sales' => (float) $cashSales,
            'customer_payments' => (float) $customerPayments,
            'supplier_payments' => (float) $supplierPayments,
            'expenses' => (float) $expenses,
            'cash_in' => (float) $cashSales + (float) $customerPayments,
            'cash_out' => (float) $supplierPayments + (float) $expenses,
        ];
    }

    public function openSession(int $branchId, string $date, float $openingCash, int $openedBy, ?string $notes = null): CashbookSession
    {
        return DB::transaction(function () use ($branchId, $date, $openingCash, $openedBy, $notes) {
            if (CashbookSession::query()->where('branch_id', $branchId)->whereDate('session_date', $date)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['session_date' => 'This branch already has an open cashbook session for the selected date.']);
            }

            $totals = $this->totals($branchId, $date);
            $expected = $openingCash + $totals['cash_in'] - $totals['cash_out'];

            return CashbookSession::create([
                'branch_id' => $branchId,
                'session_date' => $date,
                'opening_cash' => $openingCash,
                ...$totals,
                'expected_cash' => $expected,
                'status' => 'open',
                'opened_by' => $openedBy,
                'notes' => $notes,
            ]);
        });
    }

    public function refreshSession(CashbookSession $session): CashbookSession
    {
        if ($session->status === 'closed') {
            return $session;
        }

        $totals = $this->totals($session->branch_id, $session->session_date->toDateString());
        $expected = (float) $session->opening_cash + $totals['cash_in'] - $totals['cash_out'];
        $session->update([...$totals, 'expected_cash' => $expected]);

        return $session->refresh();
    }

    public function closeSession(CashbookSession $session, float $actualCash, int $closedBy, ?string $notes = null): CashbookSession
    {
        return DB::transaction(function () use ($session, $actualCash, $closedBy, $notes) {
            $session = CashbookSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status === 'closed') {
                throw ValidationException::withMessages(['cashbook' => 'Closed cashbook sessions cannot be edited.']);
            }

            $session = $this->refreshSession($session);
            $session->update([
                'actual_cash' => $actualCash,
                'difference' => $actualCash - (float) $session->expected_cash,
                'status' => 'closed',
                'closed_by' => $closedBy,
                'closed_at' => now(),
                'notes' => $notes ?? $session->notes,
            ]);

            return $session->refresh();
        });
    }
}
