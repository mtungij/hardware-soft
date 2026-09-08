<!DOCTYPE html>
<html lang="{{ $messageLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f1f5f9; color: #334155; font-family: Arial, Helvetica, sans-serif; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 8px;">
                    <tr>
                        <td style="padding: 32px;">
                            <h1 style="margin: 0 0 24px; color: #0f172a; font-size: 22px;">{{ $greeting }}</h1>
                            @foreach ($introLines as $line)
                                <p style="margin: 0 0 16px;">{{ $line }}</p>
                            @endforeach
                            <p style="margin: 24px 0; text-align: center;">
                                <a href="{{ $actionUrl }}" style="display: inline-block; padding: 12px 24px; border-radius: 6px; background-color: #0891b2; color: #ffffff; font-weight: bold; text-decoration: none;">{{ $actionText }}</a>
                            </p>
                            @foreach ($outroLines as $line)
                                <p style="margin: 0 0 16px;">{{ $line }}</p>
                            @endforeach
                            <p style="margin: 24px 0; font-weight: bold;">{{ $salutation }}</p>
                            <div style="border-top: 1px solid #e2e8f0; padding-top: 16px; font-size: 13px;">
                                <p>{{ $fallback }}</p>
                                <p style="overflow-wrap: anywhere; word-break: break-all;"><a href="{{ $actionUrl }}" style="color: #0891b2;">{{ $actionUrl }}</a></p>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
