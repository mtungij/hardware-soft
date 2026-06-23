<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php
        $themeColor = is_string($settings?->theme_color ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $settings->theme_color) ? $settings->theme_color : '#f97316';
    @endphp
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .header { border-bottom: 3px solid {{ $themeColor }}; padding-bottom: 14px; margin-bottom: 18px; }
        .brand { font-size: 22px; font-weight: bold; color: #0d2e50; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th { background: #0d2e50; color: white; padding: 8px; text-align: left; }
        td { border-bottom: 1px solid #e2e8f0; padding: 8px; }
        .right { text-align: right; }
        .box { border: 1px solid #e2e8f0; padding: 10px; border-radius: 6px; }
        .grid { width: 100%; }
        .grid td { border: 0; vertical-align: top; }
        .total { font-size: 16px; font-weight: bold; color: #0d2e50; }
        .signature { margin-top: 50px; width: 45%; border-top: 1px solid #334155; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        @if ($settings?->company_logo)
            <img src="{{ public_path('storage/'.$settings->company_logo) }}" style="max-height: 60px; margin-bottom: 8px;" alt="{{ $settings->company_name }}">
        @endif
        <div class="brand">{{ $settings?->company_name ?? 'Hardex POS' }}</div>
        <div class="muted">{{ $settings?->company_phone }} | {{ $settings?->company_email }}</div>
        <div class="muted">{{ $settings?->company_address }}</div>
    </div>

    <table class="grid">
        <tr>
            <td width="50%">
                <div class="box">
                    <strong>Supplier</strong><br>
                    {{ $purchase->supplier?->name }}<br>
                    {{ $purchase->supplier?->phone }}<br>
                    {{ $purchase->supplier?->email }}<br>
                    {{ $purchase->supplier?->address }}
                </div>
            </td>
            <td width="50%">
                <div class="box">
                    <strong>Purchase Order</strong><br>
                    Reference: {{ $purchase->reference_number }}<br>
                    Invoice: {{ $purchase->invoice_number ?: '-' }}<br>
                    Date: {{ $purchase->purchase_date?->format('M d, Y') }}<br>
                    Branch: {{ $purchase->branch?->name }}
                </div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th class="right">Quantity</th>
                <th>Unit</th>
                <th class="right">Cost</th>
                <th class="right">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($purchase->items as $item)
                <tr>
                    <td>{{ $item->product?->name }}</td>
                    <td>{{ $item->product?->sku }}</td>
                    <td class="right">{{ number_format((float) $item->ordered_quantity, 2) }}</td>
                    <td>{{ $item->product?->unit?->short_name }}</td>
                    <td class="right">{{ number_format((float) $item->cost_price, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $subtotal = (float) $purchase->items->sum('line_total');
        $tax = 0;
    @endphp
    <table>
        <tr><td class="right">Subtotal</td><td class="right" width="25%">{{ $settings?->currency ?? 'TZS' }} {{ number_format($subtotal, 2) }}</td></tr>
        <tr><td class="right">Tax</td><td class="right">{{ $settings?->currency ?? 'TZS' }} {{ number_format($tax, 2) }}</td></tr>
        <tr><td class="right total">Grand Total</td><td class="right total">{{ $settings?->currency ?? 'TZS' }} {{ number_format((float) $purchase->total_amount, 2) }}</td></tr>
    </table>

    <p class="muted">Thank you for supplying Hardex POS. Please confirm availability and expected delivery date.</p>

    <div class="signature">Authorized Signature</div>
</body>
</html>
