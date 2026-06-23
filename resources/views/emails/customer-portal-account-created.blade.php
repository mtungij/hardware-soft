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
</x-mail::panel>

<x-mail::button :url="$portalUrl">
Fungua Customer Portal
</x-mail::button>

Namna ya kuanza:

1. Fungua link ya Customer Portal hapo juu.
2. Kama bado hujawahi kuingia, bonyeza **Create Account** au **Fungua Akaunti**.
3. Tumia email hii: **{{ $account->email }}**.
4. Weka namba yako ya simu na tengeneza password yako.
5. Baada ya kufungua akaunti, rudi kwenye ukurasa wa login na uingie kwa email na password uliyoweka.

Kama tayari umefungua akaunti, ingia moja kwa moja kupitia link ya Customer Portal.

Asante,<br>
{{ $companyName }}
</x-mail::message>
