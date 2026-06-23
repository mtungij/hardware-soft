<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #0f172a; font-size: 12px; }
        h1 { margin-bottom: 4px; font-size: 22px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { background: #0f172a; color: #fff; text-align: left; }
        th, td { border: 1px solid #e2e8f0; padding: 8px; }
        .muted { color: #64748b; }
        .summary { margin-top: 18px; padding: 12px; background: #fff7ed; border: 1px solid #fed7aa; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Hardex Customer Statement</h1>
    <p class="muted">Generated on {{ now()->format('M d, Y H:i') }}</p>

    <div class="summary">
        <strong>{{ $customer['name'] }}</strong><br>
        Phone: {{ $customer['phone'] ?: '-' }}<br>
        Email: {{ $customer['email'] ?: '-' }}<br>
        Outstanding Balance: <strong>TZS {{ number_format((float) $outstanding_balance, 2) }}</strong>
    </div>

    <h2>Debt History</h2>
    <table>
        <thead><tr><th>Date</th><th>Invoice</th><th class="right">Total</th><th class="right">Paid</th><th class="right">Balance</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr><td>{{ $sale['date'] }}</td><td>{{ $sale['invoice_number'] }}</td><td class="right">{{ number_format((float) $sale['total_amount'], 2) }}</td><td class="right">{{ number_format((float) $sale['paid_amount'], 2) }}</td><td class="right">{{ number_format((float) $sale['outstanding_balance'], 2) }}</td><td>{{ ucfirst($sale['status']) }}</td></tr>
            @empty
                <tr><td colspan="6">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Payment History</h2>
    <table>
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr><td>{{ $payment['payment_date'] }}</td><td>{{ ucfirst(str_replace('_', ' ', $payment['payment_method'])) }}</td><td>{{ $payment['reference_number'] ?: '-' }}</td><td class="right">{{ number_format((float) $payment['amount'], 2) }}</td></tr>
            @empty
                <tr><td colspan="4">No payments found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Deposit History</h2>
    <table>
        <thead><tr><th>Date</th><th>Reference</th><th>Status</th><th class="right">Amount</th><th class="right">Remaining</th></tr></thead>
        <tbody>
            @forelse ($deposits as $deposit)
                <tr><td>{{ $deposit['created_at'] }}</td><td>{{ $deposit['reference_number'] ?: '-' }}</td><td>{{ ucfirst($deposit['status']) }}</td><td class="right">{{ number_format((float) $deposit['amount'], 2) }}</td><td class="right">{{ number_format((float) $deposit['remaining_balance'], 2) }}</td></tr>
            @empty
                <tr><td colspan="5">No deposits found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
