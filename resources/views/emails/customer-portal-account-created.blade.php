@php($companyName = $company?->company_name ?: ($settings?->company_name ?: config('app.name')))
<x-mail::message>
# {{ __('mail.portal.welcome', ['company' => $companyName]) }}

{{ __('mail.hello', ['name' => $account->name]) }}

{{ __('mail.portal.intro', ['company' => $companyName]) }}

<x-mail::panel>
**{{ __('mail.portal.url') }}:** {{ $portalUrl }}

**{{ __('mail.portal.email') }}:** {{ $account->email }}

**{{ __('mail.portal.password') }}:** {{ $temporaryPassword }}
</x-mail::panel>

<x-mail::button :url="$portalUrl">
{{ __('mail.portal.open') }}
</x-mail::button>

{{ __('mail.portal.start') }}

1. {{ __('mail.portal.step_open') }}
2. {{ __('mail.portal.step_email') }} **{{ $account->email }}**.
3. {{ __('mail.portal.step_password') }} **{{ $temporaryPassword }}**.
4. {{ __('mail.portal.step_login') }}
5. {{ __('mail.portal.step_account') }}

{{ __('mail.portal.security') }}

{{ __('mail.thanks') }},<br>
{{ $companyName }}
</x-mail::message>
