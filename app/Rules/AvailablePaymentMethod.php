<?php

namespace App\Rules;

use App\Services\PaymentMethodService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class AvailablePaymentMethod implements ValidationRule
{
    public function __construct(private bool $allowCredit = false, private ?int $branchId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->allowCredit && $value === 'credit') {
            return;
        }
        [$company, $branch] = PaymentMethodService::context();
        try {
            PaymentMethodService::resolve($value, $company, $this->branchId ?? $branch);
        } catch (ValidationException $exception) {
            $fail(__('payments.unavailable'));
        }
    }
}
