@php
    $companyName = $company?->company_name ?: ($settings?->company_name ?: config('app.name'));
@endphp

<x-mail::message>
# Karibu {{ $companyName }}

Habari {{ $account->name }},

Karibu kwenye huduma ya {{ $companyName }} Customer Portal. Kupitia portal hii unaweza kuona taarifa zako za madeni, malipo, risiti, amana, na statement ya akaunti yako.

<x-mail::panel>
**Link ya Customer Portal:** {{ $portalUrl }}

**Email utakayotumia:** {{ $account->email }}

**Password ya kuingia:** {{ $temporaryPassword }}
</x-mail::panel>

<x-mail::button :url="$portalUrl">
Fungua Customer Portal
</x-mail::button>

Namna ya kuanza:

1. Fungua link ya Customer Portal hapo juu.
2. Weka email hii: **{{ $account->email }}**.
3. Weka password hii: **{{ $temporaryPassword }}**.
4. Bonyeza **Login / Ingia**.
5. Baada ya kuingia, unaweza kuona madeni, malipo, risiti, amana, na statement yako.

Tafadhali hifadhi taarifa hizi kwa usalama. Kama umeshindwa kuingia, wasiliana na huduma kwa wateja.

Asante,<br>
{{ $companyName }}
</x-mail::message>
