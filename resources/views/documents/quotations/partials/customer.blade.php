<div class="label">{{ __('application.prepared_for_e57d8e51') }}</div>
<div class="customer-name">{{ $document['customer']['name'] }}</div>
@foreach (['address', 'phone', 'email'] as $field)
@if ($document['customer'][$field] ?? null)<div>{{ $document['customer'][$field] }}</div>@endif
@endforeach
