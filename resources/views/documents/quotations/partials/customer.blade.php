<div class="label">Prepared for</div>
<div class="customer-name">{{ $document['customer']['name'] }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($document['customer'][$field] ?? null)<div>{{ $document['customer'][$field] }}</div>@endif
@endforeach
