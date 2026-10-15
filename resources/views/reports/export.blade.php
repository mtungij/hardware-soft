<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; }
        table { border-collapse: collapse; width: 100%; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        th { background: #0d2e50; color: white; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>{{ __('application.printed_by_b2addf5f') }} {{ auth()->user()?->name }} {{ __('application.on_db3d405b') }} {{ now()->format('Y-m-d H:i') }}</p>
    <table>
        <thead><tr>@foreach ($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
