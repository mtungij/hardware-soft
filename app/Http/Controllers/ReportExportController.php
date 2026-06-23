<?php

namespace App\Http\Controllers;

use App\Models\CashbookSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format)
    {
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);

        [$headers, $rows] = $this->rows($request, $report);
        $filename = str($report)->replace('-', '_')->append('_report.')->append($format === 'excel' ? 'csv' : 'html')->toString();

        if ($format === 'pdf') {
            return response()->view('reports.export', [
                'title' => str($report)->replace('-', ' ')->title().' Report',
                'headers' => $headers,
                'rows' => $rows,
            ])->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return new StreamedResponse(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function rows(Request $request, string $report): array
    {
        $branchId = $request->integer('branch_id') ?: null;
        $from = $request->string('date_from', now()->startOfMonth()->toDateString())->toString();
        $to = $request->string('date_to', today()->toDateString())->toString();
        $accounting = app(AccountingService::class);

        return match ($report) {
            'sales' => [
                ['Date', 'Sale', 'Customer', 'Total', 'Paid', 'Balance'],
                Sale::with('customer')->whereBetween('sale_date', [$from, $to])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->get()->map(fn ($sale) => [$sale->sale_date?->toDateString(), $sale->sale_number, $sale->customer?->name ?? 'Walk-in', $sale->total_amount, $sale->paid_amount, $sale->balance_amount])->all(),
            ],
            'purchases' => [
                ['Date', 'Reference', 'Supplier', 'Total', 'Paid', 'Balance'],
                Purchase::with('supplier')->whereBetween('purchase_date', [$from, $to])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->get()->map(fn ($purchase) => [$purchase->purchase_date?->toDateString(), $purchase->reference_number, $purchase->supplier?->name, $purchase->total_amount, $purchase->paid_amount, $purchase->balance_amount])->all(),
            ],
            'expenses' => [
                ['Date', 'Category', 'Amount', 'Method'],
                Expense::with('category')->whereBetween('expense_date', [$from, $to])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->get()->map(fn ($expense) => [$expense->expense_date?->toDateString(), $expense->category?->name, $expense->amount, $expense->payment_method])->all(),
            ],
            'customers' => [
                ['Customer', 'Phone', 'Credit Limit', 'Outstanding'],
                Customer::all()->map(fn ($customer) => [$customer->name, $customer->phone, $customer->credit_limit, $accounting->customerBalance($customer)])->all(),
            ],
            'suppliers' => [
                ['Supplier', 'Phone', 'Opening', 'Outstanding'],
                Supplier::all()->map(fn ($supplier) => [$supplier->name, $supplier->phone, $supplier->opening_balance, $accounting->supplierBalance($supplier)])->all(),
            ],
            'stock-valuation' => [
                ['Branch', 'Location', 'Product', 'Category', 'Quantity', 'Average Cost', 'Value'],
                collect(app(FinancialReportService::class)->stockValuation($branchId))->map(fn ($row) => [$row['branch'], $row['location'], $row['product'], $row['category'], $row['quantity'], $row['average_cost'], $row['value']])->all(),
            ],
            'profit-loss' => [
                ['Metric', 'Amount'],
                collect(app(FinancialReportService::class)->profitLoss($branchId, $from, $to))->map(fn ($value, $key) => [str($key)->replace('_', ' ')->title()->toString(), $value])->values()->all(),
            ],
            'cashbook' => [
                ['Date', 'Branch', 'Opening', 'Cash In', 'Cash Out', 'Expected', 'Actual', 'Difference'],
                CashbookSession::with('branch')->whereBetween('session_date', [$from, $to])->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->get()->map(fn ($session) => [$session->session_date?->toDateString(), $session->branch?->name, $session->opening_cash, $session->cash_in, $session->cash_out, $session->expected_cash, $session->actual_cash, $session->difference])->all(),
            ],
            default => abort(404),
        };
    }
}
