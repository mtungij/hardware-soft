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
<h1>{{ __('application.hardex_daily_summary_c723f870') }}</h1>
<div class="meta">{{ $data['company_name'] }} · {{ $data['scope_label'] }} · {{ \Illuminate\Support\Carbon::parse($data['report_date'])->locale(app()->getLocale())->translatedFormat('d M Y') }}</div>

<h2>{{ __('application.sales_summary_5e65952d') }}</h2>
<table>
    <tr><td>{{ __('application.total_sales_6f9d99c8') }}</td><td>TZS {{ number_format($data['sales']['total'], 0) }}</td></tr>
    <tr><td>{{ __('application.cash_sales_68b06689') }}</td><td>TZS {{ number_format($data['sales']['cash'], 0) }}</td></tr>
    <tr><td>{{ __('application.credit_sales_e10abc1a') }}</td><td>TZS {{ number_format($data['sales']['credit'], 0) }}</td></tr>
    <tr><td>{{ __('application.number_of_sales_d71471d9') }}</td><td>{{ $data['sales']['transactions'] }}</td></tr>
    @isset($data['sales']['discounts'])<tr><td>{{ __('application.discounts_e5a40077') }}</td><td>TZS {{ number_format($data['sales']['discounts'], 0) }}</td></tr>@endisset
    @isset($data['sales']['cancelled'])<tr><td>{{ __('application.returns_cancellations_03c04324') }}</td><td>{{ $data['sales']['cancelled'] }}</td></tr>@endisset
    @isset($data['receivables'])<tr><td>{{ __('application.payments_received_91316df3') }}</td><td>TZS {{ number_format($data['receivables']['payments_received'], 0) }}</td></tr>@endisset
</table>

<h2>{{ __('application.product_performance_ec06c388') }}</h2>
<table><tr><th>{{ __('application.product_dd3b86d1') }}</th><th>{{ __('application.quantity_44f6af69') }}</th><th>{{ __('application.sales_amount_d77ceb67') }}</th></tr>
@forelse($data['top_products'] as $product)
    <tr><td>{{ $product['name'] }}</td><td>{{ number_format($product['quantity'], 2) }}</td><td>TZS {{ number_format($product['amount'], 0) }}</td></tr>
@empty
    <tr><td colspan="3">{{ __('application.no_completed_sales_cbd93621') }}</td></tr>
@endforelse
</table>

@isset($data['stock'])
<h2>{{ __('application.stock_summary_f7aaca97') }}</h2>
<table><tr><td>{{ __('application.low_stock_023d1181') }}</td><td>{{ $data['stock']['low'] }}</td></tr><tr><td>{{ __('application.out_of_stock_bc67a8ae') }}</td><td>{{ $data['stock']['out'] }}</td></tr></table>
@endisset

@isset($data['purchases'])
<h2>{{ __('application.purchases_6c4e94f1') }}</h2>
<table>
    @if($data['purchases']['amount'] !== null)<tr><td>{{ __('application.purchases_for_the_day_ca838b27') }}</td><td>TZS {{ number_format($data['purchases']['amount'], 0) }}</td></tr>@endif
    <tr><td>{{ __('application.goods_received_ed8d4e70') }}</td><td>{{ $data['purchases']['goods_received'] }}</td></tr>
    <tr><td>{{ __('application.outstanding_purchase_orders_97be406d') }}</td><td>{{ $data['purchases']['outstanding_orders'] }}</td></tr>
</table>
@endisset

@isset($data['purchase_orders_today'])
<h2>{{ __('application.purchase_orders_created_today_9fb18afe') }}</h2>
<table>
    <tr><td>{{ __('application.purchase_orders_1ea7ee5d') }}</td><td>{{ $data['purchase_orders_today']['count'] }}</td></tr>
    @if($data['purchase_orders_today']['value'] !== null)<tr><td>{{ __('application.total_ordered_value_aa9164b4') }}</td><td>TZS {{ number_format($data['purchase_orders_today']['value'], 0) }}</td></tr>@endif
    @foreach($data['purchase_orders_today']['orders'] as $order)
        <tr><td>{{ $order['number'] }} · {{ $order['supplier'] }}</td><td>{{ $order['value'] !== null ? 'TZS '.number_format($order['value'], 0) : '' }}</td></tr>
    @endforeach
</table>
@endisset

@isset($data['goods_received_today'])
<h2>{{ __('application.goods_received_today_4e758706') }}</h2>
<table>
    <tr><td>{{ __('application.grns_08b8a2c4') }}</td><td>{{ $data['goods_received_today']['count'] }}</td></tr>
    @foreach($data['goods_received_today']['numbers'] as $number)
        <tr><td>{{ $number }}</td><td></td></tr>
    @endforeach
    @if($data['goods_received_today']['goods_value'] !== null)
        <tr><td>{{ __('application.goods_value_e621e6b5') }}</td><td>TZS {{ number_format($data['goods_received_today']['goods_value'], 0) }}</td></tr>
        <tr><td>{{ __('application.additional_costs_9c8e58f4') }}</td><td>TZS {{ number_format($data['goods_received_today']['additional_costs'], 0) }}</td></tr>
        <tr><td>{{ __('application.total_landed_value_b637e406') }}</td><td>TZS {{ number_format($data['goods_received_today']['landed_value'], 0) }}</td></tr>
    @endif
    @foreach($data['goods_received_today']['products'] as $product)
        <tr><td>{{ $product }}</td><td></td></tr>
    @endforeach
</table>
@endisset

@isset($data['receivables'])
<h2>{{ __('application.customers_credit_5e64eacc') }}</h2>
<table><tr><td>{{ __('application.amount_due_96173bea') }}</td><td>TZS {{ number_format($data['receivables']['amount_due'], 0) }}</td></tr><tr><td>{{ __('application.amount_overdue_d6f9311a') }}</td><td>TZS {{ number_format($data['receivables']['amount_overdue'], 0) }}</td></tr></table>
@endisset

@isset($data['financial'])
<h2>{{ __('application.financial_section_021e42b7') }}</h2>
<table><tr><td>{{ __('application.cogs_7e86884c') }}</td><td>TZS {{ number_format($data['financial']['cogs'], 0) }}</td></tr><tr><td>{{ __('application.gross_profit_11f53e51') }}</td><td>TZS {{ number_format($data['financial']['gross_profit'], 0) }}</td></tr><tr><td>{{ __('application.expenses_8d437dbf') }}</td><td>TZS {{ number_format($data['financial']['expenses'], 0) }}</td></tr><tr><td>{{ __('application.net_profit_8bebc63c') }}</td><td>TZS {{ number_format($data['financial']['net_profit'], 0) }}</td></tr><tr><td>{{ __('application.profit_margin_f3417ddf') }}</td><td>{{ number_format($data['financial']['profit_margin'], 1) }}%</td></tr></table>
@endisset
@if(array_key_exists('stock_valuation', $data))
<table><tr><td>{{ __('application.stock_valuation_capital_42b716c3') }}</td><td>TZS {{ number_format($data['stock_valuation'], 0) }}</td></tr></table>
@endif
</body>
</html>
