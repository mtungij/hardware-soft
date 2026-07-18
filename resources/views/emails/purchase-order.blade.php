@component('mail::message')
@if ($settings?->company_logo)
<p style="text-align:center;"><img src="{{ asset('storage/'.$settings->company_logo) }}" alt="{{ $settings->company_name }}" style="max-height:70px;"></p>
@endif

# Purchase Order {{ $purchase->reference_number }}

Hello {{ $purchase->supplier?->name }},

Please find attached the official purchase order from **{{ $settings?->company_name ?? config('app.name') }}**.

@component('mail::panel')
**Purchase Date:** {{ $purchase->purchase_date?->format('M d, Y') }}  
**Reference Number:** {{ $purchase->reference_number }}  
**Supplier:** {{ $purchase->supplier?->name }}  
**Grand Total:** {{ $settings?->currency ?? 'TZS' }} {{ \App\Support\NumberFormatter::money($purchase->total_amount) }}
@endcomponent

@component('mail::table')
| Product | SKU | Qty | Unit | Cost | Total |
| --- | --- | ---: | --- | ---: | ---: |
@foreach ($purchase->items as $item)
| {{ $item->product?->name }} | {{ $item->product?->sku }} | {{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} | {{ $item->product?->unit?->short_name }} | {{ \App\Support\NumberFormatter::money($item->cost_price) }} | {{ \App\Support\NumberFormatter::money($item->line_total) }} |
@endforeach
@endcomponent

Thank you for your continued partnership.

Regards,  
{{ $settings?->company_name ?? config('app.name') }} Purchasing Team
@endcomponent
