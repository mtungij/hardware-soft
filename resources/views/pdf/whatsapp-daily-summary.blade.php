<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #172033; font-size: 10pt; }
        h1 { color: #f97316; font-size: 20pt; margin-bottom: 2mm; }
        h2 { border-bottom: 1px solid #d9dee8; font-size: 12pt; margin-top: 7mm; padding-bottom: 2mm; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border-bottom: 1px solid #e5e7eb; padding: 2.5mm; text-align: left; }
        td:last-child, th:last-child { text-align: right; }
        .meta { color: #64748b; }
    </style>
</head>
<body>
<h1>HARDEX DAILY SUMMARY</h1>
<div class="meta">{{ $data['company_name'] }} · {{ $data['scope_label'] }} · {{ \Illuminate\Support\Carbon::parse($data['report_date'])->format('d M Y') }}</div>

<h2>Sales Summary</h2>
<table>
    <tr><td>Total Sales</td><td>TZS {{ number_format($data['sales']['total'], 0) }}</td></tr>
    <tr><td>Cash Sales</td><td>TZS {{ number_format($data['sales']['cash'], 0) }}</td></tr>
    <tr><td>Credit Sales</td><td>TZS {{ number_format($data['sales']['credit'], 0) }}</td></tr>
    <tr><td>Number of Sales</td><td>{{ $data['sales']['transactions'] }}</td></tr>
    @isset($data['sales']['discounts'])<tr><td>Discounts</td><td>TZS {{ number_format($data['sales']['discounts'], 0) }}</td></tr>@endisset
    @isset($data['sales']['cancelled'])<tr><td>Returns / Cancellations</td><td>{{ $data['sales']['cancelled'] }}</td></tr>@endisset
    @isset($data['receivables'])<tr><td>Payments Received</td><td>TZS {{ number_format($data['receivables']['payments_received'], 0) }}</td></tr>@endisset
</table>

<h2>Product Performance</h2>
<table><tr><th>Product</th><th>Quantity</th><th>Sales Amount</th></tr>
@forelse($data['top_products'] as $product)
    <tr><td>{{ $product['name'] }}</td><td>{{ number_format($product['quantity'], 2) }}</td><td>TZS {{ number_format($product['amount'], 0) }}</td></tr>
@empty
    <tr><td colspan="3">No completed sales.</td></tr>
@endforelse
</table>

@isset($data['stock'])
<h2>Stock Summary</h2>
<table><tr><td>Low Stock</td><td>{{ $data['stock']['low'] }}</td></tr><tr><td>Out of Stock</td><td>{{ $data['stock']['out'] }}</td></tr></table>
@endisset

@isset($data['purchases'])
<h2>Purchases</h2>
<table><tr><td>Purchases for the Day</td><td>TZS {{ number_format($data['purchases']['amount'], 0) }}</td></tr><tr><td>Goods Received</td><td>{{ $data['purchases']['goods_received'] }}</td></tr><tr><td>Outstanding Purchase Orders</td><td>{{ $data['purchases']['outstanding_orders'] }}</td></tr></table>
@endisset

@isset($data['receivables'])
<h2>Customers / Credit</h2>
<table><tr><td>Amount Due</td><td>TZS {{ number_format($data['receivables']['amount_due'], 0) }}</td></tr><tr><td>Amount Overdue</td><td>TZS {{ number_format($data['receivables']['amount_overdue'], 0) }}</td></tr></table>
@endisset

@isset($data['financial'])
<h2>Financial Section</h2>
<table><tr><td>COGS</td><td>TZS {{ number_format($data['financial']['cogs'], 0) }}</td></tr><tr><td>Gross Profit</td><td>TZS {{ number_format($data['financial']['gross_profit'], 0) }}</td></tr><tr><td>Expenses</td><td>TZS {{ number_format($data['financial']['expenses'], 0) }}</td></tr><tr><td>Net Profit</td><td>TZS {{ number_format($data['financial']['net_profit'], 0) }}</td></tr><tr><td>Profit Margin</td><td>{{ number_format($data['financial']['profit_margin'], 1) }}%</td></tr></table>
@endisset
@if(array_key_exists('stock_valuation', $data))
<table><tr><td>Stock Valuation / Capital</td><td>TZS {{ number_format($data['stock_valuation'], 0) }}</td></tr></table>
@endif
</body>
</html>
