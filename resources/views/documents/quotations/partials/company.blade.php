@if ($document['company']['logo'])<img class="logo" src="{{ $document['company']['logo'] }}" alt="Company logo"><br>@endif
<div class="company-name">{{ $document['company']['company_name'] }}</div>
<div class="contact">
@foreach (['address', 'phone', 'email'] as $field)
@if ($document['company'][$field] ?? null)<div>{{ $document['company'][$field] }}</div>@endif
@endforeach
@if ($document['company']['tin_number'] ?? null)<div>TIN: {{ $document['company']['tin_number'] }}</div>@endif
@if ($document['company']['vrn_number'] ?? null)<div>VRN: {{ $document['company']['vrn_number'] }}</div>@endif
</div>
