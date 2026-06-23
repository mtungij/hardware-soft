@php
    $companyName = $company?->company_name ?: ($settings?->company_name ?: config('app.name'));
@endphp

<x-mail::message>
# Customer Portal Account Created

Hello {{ $account->name }},

Your {{ $companyName }} customer portal account has been created. You can now log in to check your debts, deposits, receipts, payments, and account statements.

<x-mail::panel>
**Login URL:** {{ $portalUrl }}

**Email:** {{ $account->email }}

**Temporary Password:** {{ $temporaryPassword }}
</x-mail::panel>

<x-mail::button :url="$portalUrl">
Open Customer Portal
</x-mail::button>

Please log in and change your password after your first access.

Thanks,<br>
{{ $companyName }}
</x-mail::message>
