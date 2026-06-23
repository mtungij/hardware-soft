<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerDeposit;
use App\Models\CustomerNotification;
use App\Models\CustomerReceipt;
use App\Models\Sale;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File as ValidationFile;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class CustomerPortalApiController extends Controller
{
    public function dashboard(Request $request, AccountingService $accounting): JsonResponse
    {
        $account = $request->user()->load('customer');
        $customer = $account->customer;
        $outstanding = $accounting->customerBalance($customer);
        $depositBalance = CustomerDeposit::where('customer_id', $customer->id)->whereIn('status', ['approved', 'partial'])->sum('balance_amount');
        $lastPayment = $customer->payments()->latest('payment_date')->first();
        $lastPurchase = $customer->sales()->where('status', 'completed')->latest('sale_date')->first();

        return response()->json([
            'total_outstanding_debt' => (float) $outstanding,
            'available_deposit_balance' => (float) $depositBalance,
            'credit_limit' => (float) $customer->credit_limit,
            'available_credit' => max(0, (float) $customer->credit_limit - (float) $outstanding),
            'last_payment' => $lastPayment ? $this->paymentPayload($lastPayment) : null,
            'last_purchase' => $lastPurchase ? $this->salePayload($lastPurchase) : null,
            'pending_receipts' => CustomerReceipt::where('customer_id', $customer->id)->where('status', 'pending')->count(),
            'pending_deposits' => CustomerDeposit::where('customer_id', $customer->id)->where('status', 'pending')->count(),
        ]);
    }

    public function debts(Request $request): JsonResponse
    {
        $sales = Sale::with('items.product')
            ->where('customer_id', $request->user()->customer_id)
            ->where('status', 'completed')
            ->latest('sale_date')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($sales->through(fn (Sale $sale) => $this->salePayload($sale, true)));
    }

    public function debt(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->customer_id === $request->user()->customer_id, 403);

        return response()->json($this->salePayload($sale->load('items.product', 'payments'), true, true));
    }

    public function receipts(Request $request): JsonResponse
    {
        $receipts = CustomerReceipt::with('sale')
            ->where('customer_account_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($receipts->through(fn (CustomerReceipt $receipt) => $this->receiptPayload($receipt)));
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $account = $request->user()->load('customer');
        $data = $request->validate([
            'invoice_id' => ['nullable', Rule::exists('sales', 'id')->where('customer_id', $account->customer_id)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:mobile_money,bank,cash_deposit'],
            'reference_number' => ['nullable', 'string', 'max:255', Rule::unique('customer_receipts', 'reference_number')->where('customer_id', $account->customer_id)],
            'receipt' => ['required', ValidationFile::types(['jpg', 'jpeg', 'png', 'pdf'])->max(5 * 1024)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $sale = filled($data['invoice_id'] ?? null) ? Sale::where('customer_id', $account->customer_id)->findOrFail($data['invoice_id']) : null;

        if ($sale && (float) $data['amount'] > (float) $sale->balance_amount) {
            throw ValidationException::withMessages(['amount' => 'Receipt amount cannot exceed the selected invoice balance.']);
        }

        $receipt = CustomerReceipt::create([
            'customer_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'sale_id' => $sale?->id,
            'branch_id' => $sale?->branch_id ?? $account->customer?->branch_id,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'receipt_file' => $request->file('receipt')->store('customer-receipts', 'local'),
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json($this->receiptPayload($receipt), 201);
    }

    public function deposits(Request $request): JsonResponse
    {
        $deposits = CustomerDeposit::where('customer_account_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($deposits->through(fn (CustomerDeposit $deposit) => $this->depositPayload($deposit)));
    }

    public function storeDeposit(Request $request): JsonResponse
    {
        $account = $request->user()->load('customer');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:mobile_money,bank,cash_deposit'],
            'reference_number' => ['nullable', 'string', 'max:255', Rule::unique('customer_deposits', 'reference_number')->where('customer_id', $account->customer_id)],
            'receipt' => ['required', ValidationFile::types(['jpg', 'jpeg', 'png', 'pdf'])->max(5 * 1024)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $deposit = CustomerDeposit::create([
            'customer_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'branch_id' => $account->customer?->branch_id,
            'amount' => $data['amount'],
            'used_amount' => 0,
            'balance_amount' => 0,
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'receipt_file' => $request->file('receipt')->store('customer-deposits', 'local'),
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json($this->depositPayload($deposit), 201);
    }

    public function statements(Request $request, AccountingService $accounting): JsonResponse|Response
    {
        $customer = $request->user()->customer;
        $payload = [
            'customer' => $this->customerPayload($customer),
            'outstanding_balance' => $accounting->customerBalance($customer),
            'sales' => $customer->sales()->where('status', 'completed')->latest('sale_date')->get()->map(fn (Sale $sale) => $this->salePayload($sale))->values(),
            'payments' => $customer->payments()->latest('payment_date')->get()->map(fn ($payment) => $this->paymentPayload($payment))->values(),
            'deposits' => CustomerDeposit::where('customer_id', $customer->id)->latest()->get()->map(fn (CustomerDeposit $deposit) => $this->depositPayload($deposit))->values(),
        ];

        if ($request->query('format') === 'pdf') {
            File::ensureDirectoryExists(storage_path('app/mpdf'));
            $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
            $mpdf->WriteHTML(view('pdf.customer-statement', $payload)->render());

            return response($mpdf->Output('', 'S'), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="customer-statement.pdf"',
            ]);
        }

        return response()->json($payload);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'account' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'phone' => $request->user()->phone,
                'email' => $request->user()->email,
                'status' => $request->user()->status,
            ],
            'customer' => $this->customerPayload($request->user()->customer),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email,'.$account->id],
        ]);

        $account->update($data);
        $account->customer()->update(['phone' => $data['phone'], 'email' => $data['email']]);

        return $this->profile($request);
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = CustomerNotification::where('customer_id', $request->user()->customer_id)
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json($notifications->through(fn (CustomerNotification $notification) => [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ]));
    }

    private function salePayload(Sale $sale, bool $withProducts = false, bool $withPayments = false): array
    {
        $payload = [
            'id' => $sale->id,
            'invoice_number' => $sale->sale_number,
            'date' => $sale->sale_date?->toDateString(),
            'total_amount' => (float) $sale->total_amount,
            'paid_amount' => (float) $sale->paid_amount,
            'outstanding_balance' => (float) $sale->balance_amount,
            'status' => $sale->payment_status,
        ];

        if ($withProducts) {
            $payload['products'] = $sale->items->map(fn ($item) => [
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values();
        }

        if ($withPayments) {
            $payload['payments'] = $sale->payments->map(fn ($payment) => $this->paymentPayload($payment))->values();
        }

        return $payload;
    }

    private function receiptPayload(CustomerReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'invoice_number' => $receipt->sale?->sale_number,
            'amount' => (float) $receipt->amount,
            'payment_method' => $receipt->payment_method,
            'reference_number' => $receipt->reference_number,
            'status' => $receipt->status,
            'notes' => $receipt->notes,
            'created_at' => $receipt->created_at?->toISOString(),
            'reviewed_at' => $receipt->approved_at?->toISOString() ?: $receipt->rejected_at?->toISOString(),
            'rejection_reason' => $receipt->rejection_reason,
        ];
    }

    private function depositPayload(CustomerDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'amount' => (float) $deposit->amount,
            'used_amount' => (float) $deposit->used_amount,
            'remaining_balance' => (float) $deposit->balance_amount,
            'payment_method' => $deposit->payment_method,
            'reference_number' => $deposit->reference_number,
            'status' => $deposit->status,
            'notes' => $deposit->notes,
            'created_at' => $deposit->created_at?->toISOString(),
            'reviewed_at' => $deposit->approved_at?->toISOString() ?: $deposit->rejected_at?->toISOString(),
            'rejection_reason' => $deposit->rejection_reason,
        ];
    }

    private function paymentPayload($payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'reference_number' => $payment->reference_number,
            'payment_date' => $payment->payment_date?->toDateString(),
        ];
    }

    private function customerPayload($customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'credit_limit' => (float) $customer->credit_limit,
            'status' => $customer->status,
        ];
    }
}
