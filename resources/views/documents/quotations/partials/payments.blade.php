@if ($document['payments'])
<div class="section"><h3>{{ __('application.payment_instructions_f3fa021f') }}</h3><p class="muted">{{ __('application.use_payment_reference_e3549df1') }} <b>{{ $document['number'] }}</b></p>
@foreach ($document['payments'] as $method)
<div class="payment"><b>{{ $method['display_name'] }}</b>
@foreach (['provider' => 'Provider', 'bank_name' => 'Bank', 'account_name' => 'Account name', 'account_number' => 'Account', 'phone_or_business_number' => 'Number', 'branch_name' => 'Branch'] as $field => $label)
@if ($method[$field] ?? null)<p>{{ \App\Support\UiText::label($label) }}: {{ $method[$field] }}</p>@endif
@endforeach
@if ($method['instructions'] ?? null)<p class="prose">{{ $method['instructions'] }}</p>@endif
</div>
@endforeach
</div>
@endif
