<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerReceipt;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function customerBalance(Customer $customer): float
    {
        return (float) $customer->opening_balance
            + (float) $customer->sales()->where('company_id', $customer->company_id)->where('status', 'completed')->sum('balance_amount');
    }

    public function supplierBalance(Supplier $supplier): float
    {
        return (float) $supplier->opening_balance
            + (float) $supplier->purchases()->where('status', '!=', 'cancelled')->sum('balance_amount');
    }

    public function recordInitialPurchasePayment(Purchase $purchase, array $data, int $paidBy): ?SupplierPayment
    {
        $amount = (float) ($data['amount'] ?? 0);

        if ($amount <= 0) {
            return null;
        }

        if ($amount > (float) $purchase->total_amount) {
            throw ValidationException::withMessages([
                'paid_amount' => __('application.paid_amount_cannot_exceed_the_purchase_total_e2d552dc'),
            ]);
        }

        return SupplierPayment::query()->firstOrCreate(
            ['purchase_id' => $purchase->id],
            [
                'company_id' => $purchase->company_id,
                'branch_id' => $purchase->branch_id,
                'supplier_id' => $purchase->supplier_id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'] ?? $purchase->purchase_date,
                'paid_by' => $paidBy,
                'notes' => 'Initial payment for '.$purchase->reference_number,
            ],
        );
    }

    public function receiveCustomerPayment(Customer $customer, array $data, int $receivedBy): CustomerPayment
    {
        return DB::transaction(function () use ($customer, $data, $receivedBy) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $actor = User::withoutGlobalScopes()->findOrFail($receivedBy);
            abort_unless((int) $actor->company_id === (int) $customer->company_id && BranchAccess::canAccessBranch($actor, (int) $data['branch_id']), 403);
            if (! empty($data['payment_submission_key'])) {
                $existing = CustomerPayment::query()->where('company_id', $customer->company_id)->where('payment_submission_key', $data['payment_submission_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless((int) $existing->customer_id === (int) $customer->id, 403);

                    return $existing;
                }
            }
            $amount = (float) $data['amount'];
            if (! is_finite($amount) || $amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.positive_amount')]);
            }
            $sales = Sale::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->where('status', 'completed')->where('balance_amount', '>', 0)->oldest('sale_date')->lockForUpdate()->get();
            $balance = (float) $customer->opening_balance + (float) $sales->sum('balance_amount');

            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => __('application.customer_payment_cannot_exceed_outstanding_balance_3910d37e')]);
            }

            $payment = new CustomerPayment([
                'company_id' => $customer->company_id,
                'payment_submission_key' => $data['payment_submission_key'] ?? null,
                'branch_id' => $data['branch_id'],
                'customer_id' => $customer->id,
                'receipt_number' => $this->nextCustomerPaymentReceiptNumber(),
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'],
                'received_by' => $receivedBy,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['source_receipt_id'])) {
                abort_unless($actor->can('approve customer receipts'), 403);
                $source = CustomerReceipt::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)->findOrFail($data['source_receipt_id']);
                abort_unless($source->status === 'pending' && (float) $source->amount === $amount, 403);
                $payment->inheritPaymentMethod($source);
            }
            $payment->save();

            $customer->decrement('balance_amount', min($amount, (float) $customer->balance_amount));

            $remaining = $amount;
            foreach ($sales as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = min($remaining, (float) $sale->balance_amount);
                $newPaid = (float) $sale->paid_amount + $applied;
                $newBalance = max(0, (float) $sale->balance_amount - $applied);

                $allocatedPayment = $sale->payments()->make([
                    'customer_payment_id' => $payment->id,
                    'payment_method' => $data['payment_method'],
                    'amount' => $applied,
                    'reference_number' => $data['reference_number'] ?? null,
                    'received_by' => $receivedBy,
                    'payment_date' => $data['payment_date'],
                ]);

                $allocatedPayment->inheritPaymentMethod($payment)->save();

                CustomerPaymentAllocation::create([
                    'company_id' => $customer->company_id,
                    'branch_id' => $data['branch_id'],
                    'customer_payment_id' => $payment->id,
                    'sale_id' => $sale->id,
                    'allocated_amount' => $applied,
                    'created_by' => $receivedBy,
                ]);

                $sale->update([
                    'paid_amount' => min($newPaid, (float) $sale->total_amount),
                    'balance_amount' => $newBalance,
                    'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);

                $remaining -= $applied;
            }

            return $payment;
        }, attempts: 10);
    }

    private function nextCustomerPaymentReceiptNumber(): string
    {
        // Counting rows races across different customers and tenants. Existing
        // receipt numbers stay intact; new numbers use a unique sortable suffix.
        return 'CPAY-'.now()->format('Ymd').'-'.str()->ulid();
    }

    public function paySupplier(Supplier $supplier, array $data, int $paidBy): SupplierPayment
    {
        return DB::transaction(function () use ($supplier, $data, $paidBy) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $actor = User::withoutGlobalScopes()->findOrFail($paidBy);
            abort_unless((int) $actor->company_id === (int) $supplier->company_id && BranchAccess::canAccessBranch($actor, (int) $data['branch_id']), 403);
            if (! empty($data['payment_submission_key'])) {
                $existing = SupplierPayment::query()->where('company_id', $supplier->company_id)->where('payment_submission_key', $data['payment_submission_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless((int) $existing->supplier_id === (int) $supplier->id, 403);

                    return $existing;
                }
            }
            $amount = (float) $data['amount'];
            if (! is_finite($amount) || $amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.positive_amount')]);
            }
            $purchases = Purchase::query()->where('company_id', $supplier->company_id)->where('supplier_id', $supplier->id)->where('status', '!=', 'cancelled')->where('balance_amount', '>', 0)->oldest('purchase_date')->lockForUpdate()->get();
            $balance = (float) $supplier->opening_balance + (float) $purchases->sum('balance_amount');

            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => __('application.supplier_payment_cannot_exceed_outstanding_balance_8b97bb14')]);
            }

            $payment = SupplierPayment::create([
                'company_id' => $supplier->company_id,
                'payment_submission_key' => $data['payment_submission_key'] ?? null,
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
            foreach ($purchases as $purchase) {
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
        }, attempts: 10);
    }
}
