<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    <h1>Hardex {{ __('messages.statements.title') }}</h1>
    <p class="muted">{{ __('messages.generated_on') }} {{ now()->format('M d, Y H:i') }}</p>

    <div class="summary">
        <strong>{{ $customer['name'] }}</strong><br>
        {{ __('messages.support.phone') }}: {{ $customer['phone'] ?: '-' }}<br>
        {{ __('messages.support.email') }}: {{ $customer['email'] ?: '-' }}<br>
        {{ __('messages.statements.outstanding_balance') }}: <strong>TZS {{ number_format((float) $outstanding_balance, 2) }}</strong>
    </div>

    <h2>{{ __('messages.statements.debt_history') }}</h2>
    <table>
        <thead><tr><th>{{ __('messages.table.date') }}</th><th>{{ __('messages.debts.invoice_number') }}</th><th class="right">{{ __('messages.table.total') }}</th><th class="right">{{ __('messages.table.paid') }}</th><th class="right">{{ __('messages.table.balance') }}</th><th>{{ __('messages.table.status') }}</th></tr></thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr><td>{{ $sale['date'] }}</td><td>{{ $sale['invoice_number'] }}</td><td class="right">{{ number_format((float) $sale['total_amount'], 2) }}</td><td class="right">{{ number_format((float) $sale['paid_amount'], 2) }}</td><td class="right">{{ number_format((float) $sale['outstanding_balance'], 2) }}</td><td>{{ __("messages.status.{$sale['status']}") }}</td></tr>
            @empty
                <tr><td colspan="6">{{ __('messages.statements.no_purchases') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('messages.statements.payment_history') }}</h2>
    <table>
        <thead><tr><th>{{ __('messages.table.date') }}</th><th>{{ __('messages.table.method') }}</th><th>{{ __('messages.table.reference') }}</th><th class="right">{{ __('messages.table.amount') }}</th></tr></thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr><td>{{ $payment['payment_date'] }}</td><td>{{ __("messages.methods.{$payment['payment_method']}") }}</td><td>{{ $payment['reference_number'] ?: '-' }}</td><td class="right">{{ number_format((float) $payment['amount'], 2) }}</td></tr>
            @empty
                <tr><td colspan="4">{{ __('messages.statements.no_payments') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('messages.statements.deposit_history') }}</h2>
    <table>
        <thead><tr><th>{{ __('messages.table.date') }}</th><th>{{ __('messages.table.reference') }}</th><th>{{ __('messages.table.status') }}</th><th class="right">{{ __('messages.table.amount') }}</th><th class="right">{{ __('messages.table.balance') }}</th></tr></thead>
        <tbody>
            @forelse ($deposits as $deposit)
                <tr><td>{{ $deposit['created_at'] }}</td><td>{{ $deposit['reference_number'] ?: '-' }}</td><td>{{ __("messages.status.{$deposit['status']}") }}</td><td class="right">{{ number_format((float) $deposit['amount'], 2) }}</td><td class="right">{{ number_format((float) $deposit['remaining_balance'], 2) }}</td></tr>
            @empty
                <tr><td colspan="5">{{ __('messages.statements.no_deposits') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
