@component('mail::message')
@if ($settings?->company_logo)
<p style="text-align:center;"><img src="{{ asset('storage/'.$settings->company_logo) }}" alt="{{ $settings->company_name }}" style="max-height:70px;"></p>
@endif

# {{ __('mail.purchase.title', ['reference' => $purchase->reference_number]) }}

{{ __('mail.hello', ['name' => $purchase->supplier?->name]) }}

{{ __('mail.purchase.intro', ['company' => $settings?->company_name ?? config('app.name')]) }}

@component('mail::panel')
**{{ __('application.purchase_date_74feb2ca') }}:** {{ $purchase->purchase_date?->locale(app()->getLocale())->translatedFormat('d F Y') }}
**{{ __('mail.purchase.reference') }}:** {{ $purchase->reference_number }}
**{{ __('mail.purchase.supplier') }}:** {{ $purchase->supplier?->name }}
**{{ __('mail.purchase.total') }}:** {{ $settings?->currency ?? 'TZS' }} {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}
@endcomponent

@component('mail::table')
| {{ __('mail.purchase.product') }} | {{ __('mail.purchase.sku') }} | {{ __('mail.purchase.quantity') }} | {{ __('mail.purchase.unit') }} | {{ __('mail.purchase.cost') }} | {{ __('mail.purchase.total') }} |
| --- | --- | ---: | --- | ---: | ---: |
@foreach ($purchase->items as $item)
| {{ $item->productDisplayNameWithSize() }} | {{ $item->product?->sku }} | {{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} | {{ $item->purchaseUnit?->short_name }} | {{ \App\Support\NumberFormatter::money($item->cost_price) }} / {{ $item->purchaseUnit?->short_name }} | {{ \App\Support\NumberFormatter::money($item->line_total) }} |
@endforeach
@endcomponent

{{ __('mail.purchase.thanks') }}

{{ __('mail.regards') }},
{{ $settings?->company_name ?? config('app.name') }} — {{ __('mail.purchase.team') }}
@endcomponent
