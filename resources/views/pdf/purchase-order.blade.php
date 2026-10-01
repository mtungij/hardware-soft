<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">

    @php
        $themeColor = is_string($settings?->theme_color ?? null)
            && preg_match('/^#[0-9A-Fa-f]{6}$/', $settings->theme_color)
                ? $settings->theme_color
                : '#0891b2';

        $logoPath = null;

        if (!empty($settings?->company_logo)) {
            $candidate = public_path('storage/'.$settings->company_logo);

            if (is_file($candidate)) {
                $logoPath = $candidate;
            }
        }
    @endphp

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            font-size: 11px;
            margin: 0;
            padding: 0;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 3px solid #0891b2;
            margin-bottom: 18px;
        }

        .header-table td {
            border: none;
            padding-bottom: 14px;
            vertical-align: middle;
        }

        .logo {
            max-height: 65px;
            max-width: 140px;
        }

        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .company-info {
            font-size: 10px;
            color: #64748b;
            line-height: 1.6;
        }

        .document-title {
            text-align: right;
            font-size: 20px;
            font-weight: bold;
            color: #0891b2;
        }

        .document-reference {
            text-align: right;
            font-size: 10px;
            color: #475569;
            margin-top: 4px;
        }

        .info-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin: 0 -8px 18px -8px;
        }

        .info-table td {
            width: 50%;
            border: none;
            vertical-align: top;
        }

        .card {
            border: 1px solid #a5f3fc;
            background: #f0fdff;
            padding: 12px;
            border-radius: 6px;
            line-height: 1.6;
        }

        .card-title {
            color: #0891b2;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            border: 1px solid #67e8f9;
        }

        .items-table th {
            background: #0891b2;
            color: #ffffff;
            padding: 10px 8px;
            font-size: 10px;
            font-weight: bold;
            text-align: left;
            border: 1px solid #67e8f9;
        }

        .items-table td {
            padding: 10px 8px;
            border: 1px solid #a5f3fc;
            vertical-align: middle;
        }

        .items-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }

        .items-table tbody tr:nth-child(even) {
            background: #ecfeff;
        }

        .product-name {
            font-weight: bold;
            color: #0f172a;
        }

        .size {
            font-size: 9px;
            color: #64748b;
            margin-top: 2px;
        }

        .qty {
            text-align: right;
            font-weight: bold;
        }

        .unit {
            font-weight: 600;
        }

        .note {
            margin-top: 22px;
            padding: 11px 13px;
            background: #f0fdff;
            border-left: 4px solid #0891b2;
            color: #475569;
            line-height: 1.6;
        }

        .signature-table {
            width: 100%;
            margin-top: 42px;
            border-collapse: collapse;
        }

        .signature-table td {
            border: none;
        }

        .signature-line {
            width: 220px;
            border-top: 1px solid #334155;
            padding-top: 7px;
            color: #475569;
            font-size: 10px;
        }

        .footer {
            margin-top: 30px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            text-align: center;
            font-size: 9px;
            color: #94a3b8;
        }
    </style>
</head>

<body>

<table class="header-table">
    <tr>
        <td width="20%">
            @if ($logoPath)
                <img src="{{ $logoPath }}" class="logo" alt="Company Logo">
            @endif
        </td>

        <td width="50%">
            <div class="company-name">
                {{ $settings?->company_name ?? 'HARDEX ERP' }}
            </div>

            <div class="company-info">
                @if ($settings?->company_phone)
                    {{ $settings->company_phone }}
                @endif

                @if ($settings?->company_email)
                    @if ($settings?->company_phone) | @endif
                    {{ $settings->company_email }}
                @endif

                @if ($settings?->company_address)
                    <br>{{ $settings->company_address }}
                @endif
            </div>
        </td>

        <td width="30%">
            <div class="document-title">PURCHASE ORDER</div>
            <div class="document-reference">
                {{ $purchase->reference_number }}
            </div>
        </td>
    </tr>
</table>


<table class="info-table">
    <tr>
        <td>
            <div class="card">
                <div class="card-title">Supplier</div>

                <strong>{{ $purchase->supplier?->name ?: '-' }}</strong>

                @if ($purchase->supplier?->phone)
                    <br>{{ $purchase->supplier->phone }}
                @endif

                @if ($purchase->supplier?->email)
                    <br>{{ $purchase->supplier->email }}
                @endif

                @if ($purchase->supplier?->address)
                    <br>{{ $purchase->supplier->address }}
                @endif
            </div>
        </td>

        <td>
            <div class="card">
                <div class="card-title">Order Details</div>

                <strong>Reference:</strong>
                {{ $purchase->reference_number }}

                <br>

                <strong>Date:</strong>
                {{ $purchase->purchase_date?->format('d M Y') }}

                <br>

            </div>
        </td>
    </tr>
</table>


<table class="items-table">
    <thead>
        <tr>
            <th style="width: 10%; text-align: center;">S/NO.</th>
            <th style="width: 54%;">PRODUCT</th>
            <th style="width: 18%; text-align: right;">QUANTITY</th>
            <th style="width: 18%;">UNIT</th>
        </tr>
    </thead>

    <tbody>
        @foreach ($purchase->items as $index => $item)
            <tr>
                <td style="text-align: center;">
                    {{ $index + 1 }}
                </td>

                <td>
                    <div class="product-name">
                        {{ $item->product?->displayName() ?: '-' }}
                    </div>

                    @if ($item->sizeLabel())
                        <div class="size">
                            Size: {{ $item->sizeLabel() }}
                        </div>
                    @endif
                </td>

                <td class="qty">
                    {{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }}
                </td>

                <td class="unit">
                    {{ $item->purchaseUnit?->short_name
                        ?: $item->purchase_unit_code_snapshot
                        ?: $item->purchase_unit_name_snapshot
                        ?: '-' }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>


<div class="note">
    <strong>Supplier Action Required</strong><br>
    Please confirm product availability, your unit prices, and the expected delivery date.
</div>


<table class="signature-table">
    <tr>
        <td width="60%">
            <div class="signature-line">
                Authorized Signature
            </div>
        </td>

        <td width="40%" style="text-align:right; color:#64748b;">
            {{ $settings?->company_name }}
        </td>
    </tr>
</table>


<div class="footer">
    Purchase Order generated by HARDEX ERP
</div>

</body>
</html>
