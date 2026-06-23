<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function customerBalance(Customer $customer): float
    {
        return (float) $customer->opening_balance
            + (float) $customer->sales()->where('status', 'completed')->sum('balance_amount');
    }

    public function supplierBalance(Supplier $supplier): float
    {
        return (float) $supplier->opening_balance
            + (float) $supplier->purchases()->where('status', '!=', 'cancelled')->sum('balance_amount');
    }

    public function receiveCustomerPayment(Customer $customer, array $data, int $receivedBy): CustomerPayment
    {
        return DB::transaction(function () use ($customer, $data, $receivedBy) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $data['amount'];
            $balance = $this->customerBalance($customer);

            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Customer payment cannot exceed outstanding balance.']);
            }

            $payment = CustomerPayment::create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $customer->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'],
                'received_by' => $receivedBy,
                'notes' => $data['notes'] ?? null,
            ]);

            $customer->decrement('balance_amount', min($amount, (float) $customer->balance_amount));

            $remaining = $amount;
            foreach (Sale::query()->where('customer_id', $customer->id)->where('status', 'completed')->where('balance_amount', '>', 0)->oldest('sale_date')->lockForUpdate()->get() as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (float) $sale->balance_amount);
                $newPaid = (float) $sale->paid_amount + $applied;
                $newBalance = max(0, (float) $sale->balance_amount - $applied);

                $sale->payments()->create([
                    'payment_method' => $data['payment_method'],
                    'amount' => $applied,
                    'reference_number' => $data['reference_number'] ?? null,
                    'received_by' => $receivedBy,
                    'payment_date' => $data['payment_date'],
                ]);

                $sale->update([
                    'paid_amount' => min($newPaid, (float) $sale->total_amount),
                    'balance_amount' => $newBalance,
                    'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);

                $remaining -= $applied;
            }

            return $payment;
        });
    }

    public function paySupplier(Supplier $supplier, array $data, int $paidBy): SupplierPayment
    {
        return DB::transaction(function () use ($supplier, $data, $paidBy) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $data['amount'];
            $balance = $this->supplierBalance($supplier);

            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Supplier payment cannot exceed outstanding balance.']);
            }

            $payment = SupplierPayment::create([
                'branch_id' => $data['branch_id'],
                'supplier_id' => $supplier->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'],
                'paid_by' => $paidBy,
                'notes' => $data['notes'] ?? null,
            ]);

            $remaining = $amount;
            foreach (Purchase::query()->where('supplier_id', $supplier->id)->where('status', '!=', 'cancelled')->where('balance_amount', '>', 0)->oldest('purchase_date')->lockForUpdate()->get() as $purchase) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (float) $purchase->balance_amount);
                $newPaid = (float) $purchase->paid_amount + $applied;
                $newBalance = max(0, (float) $purchase->balance_amount - $applied);

                $purchase->update([
                    'paid_amount' => min($newPaid, (float) $purchase->total_amount),
                    'balance_amount' => $newBalance,
                    'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);

                $remaining -= $applied;
            }

            return $payment;
        });
    }
}
