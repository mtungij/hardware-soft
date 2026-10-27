@props(['branchId' => null, 'history' => false, 'allowCredit' => false])
@php
    [$paymentCompany, $paymentBranch] = \App\Services\PaymentMethodService::context();
    $paymentOptions = \App\Services\PaymentMethodService::available($paymentCompany, $branchId ?: $paymentBranch, $history)->get();
@endphp
@foreach($paymentOptions as $paymentOption)
    <option value="method:{{ $paymentOption->id }}">{{ $paymentOption->display_name }}</option>
@endforeach
@if($allowCredit)<option value="credit">{{ __('payments.credit') }}</option>@endif
