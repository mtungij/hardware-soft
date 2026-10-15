<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { background: #fff; color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 12px; }
        .brand { font-size: 18px; font-weight: 700; }
        .muted { color: #4b5563; }
        .meta { width: 100%; margin-top: 8px; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.data th { background: #f3f4f6; color: #111827; font-weight: 700; border: 1px solid #d1d5db; padding: 6px; text-align: left; }
        table.data td { border: 1px solid #e5e7eb; padding: 5px; }
        table.data.cyan th { background: #0891b2; color: #ffffff; border-color: #0e7490; }
        table.data.cyan td { border-color: #a5f3fc; }
        table.data.cyan tbody tr:nth-child(even) td { background: #ecfeff; }
        .totals { margin-top: 12px; width: 40%; border-collapse: collapse; }
        .totals td { border: 1px solid #e5e7eb; padding: 6px; }
        .signature { margin-top: 36px; width: 100%; }
        .signature td { padding-top: 24px; border-top: 1px solid #9ca3af; width: 33%; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td width="12%">
                    @if (($header['logo'] ?? null) && file_exists($header['logo']))
                        <img src="{{ $header['logo'] }}" style="max-height:60px; max-width:90px;">
                    @endif
                </td>
                <td>
                    <div class="brand">{{ $header['company_name'] }}</div>
                    <div class="muted">
                        {{ __('application.phone_daeea4d0') }} {{ $header['phone'] ?: '-' }} |
                        {{ \App\Support\UiText::translate(__('application.whatsapp_b336fc55')) }}: {{ $header['whatsapp'] ?: '-' }} |
                        {{ \App\Support\UiText::translate(__('application.email_84add5b2')) }}: {{ $header['email'] ?: '-' }}
                    </div>
                    <div class="muted">
                        {{ $header['address'] ?: '-' }} |
                        {{ $header['region'] ?: '-' }} / {{ $header['district'] ?: '-' }}
                    </div>
                </td>
                <td width="30%" align="right">
                    <h2>{{ $title }}</h2>
                    <div class="muted">{{ __('application.printed_c6fd1db5') }} {{ $header['printed_date'] }}</div>
                </td>
            </tr>
        </table>

        <table class="meta">
            <tr>
                <td><strong>{{ __('application.date_range_e85bcb56') }}</strong> {{ $header['date_range'] }}</td>
                <td><strong>{{ __('application.branch_8b31d33d') }}</strong> {{ $header['branch_name'] }}</td>
                <td><strong>{{ __('application.printed_by_fc3328e4') }}</strong> {{ $header['printed_by'] }}</td>
            </tr>
        </table>
    </div>

    <table class="data{{ ($table_theme ?? null) === 'cyan' ? ' cyan' : '' }}">
        <thead>
            <tr>
                @foreach ($headers as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{!! nl2br(e($value)) !!}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">{{ __('application.no_records_found_7cd1d44b') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (($totals ?? []) !== [])
        <table class="totals">
            @foreach ($totals as $label => $value)
                <tr><td><strong>{{ $label }}</strong></td><td align="right">{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    <table class="signature">
        <tr>
            <td>{{ __('application.prepared_by_447abf81') }}</td>
            <td>{{ __('application.checked_by_80f2a295') }}</td>
            <td>{{ __('application.approved_by_2b62a43b') }}</td>
        </tr>
    </table>
</body>
</html>
