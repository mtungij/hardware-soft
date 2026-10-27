<?php

namespace App\Models\Concerns;

use App\Services\PaymentMethodService;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait HasPaymentMethod
{
    private ?array $inheritedPaymentMethod = null;

    public function inheritPaymentMethod(Model $source): static
    {
        $this->inheritedPaymentMethod = $source->only(['company_id', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot']);

        return $this;
    }

    public static function bootHasPaymentMethod(): void
    {
        static::saving(function ($model) {
            if ((! $model->exists || $model->isDirty('amount')) && $model->payment_method !== 'credit' && (filled($model->payment_method) || $model->payment_method_id)) {
                if (! is_finite((float) $model->amount) || (float) $model->amount <= 0) {
                    throw ValidationException::withMessages(['amount' => __('payments.positive_amount')]);
                }
            }
            if ($model->exists && ! $model->isDirty('payment_method') && ! $model->isDirty('payment_method_id')) {
                return;
            }
            if (blank($model->payment_method) && ! $model->payment_method_id) {
                return;
            }
            if ($model->payment_method === 'credit') {
                // Legacy markers remain readable; new payment flows never create these markers.
                return;
            }
            $companyId = (int) ($model->company_id ?: $model->sale?->company_id ?: $model->goodsReceivingNote?->company_id ?: PaymentMethodService::context()[0]);
            $branchId = $model->branch_id ?? $model->sale?->branch_id ?? $model->goodsReceivingNote?->branch_id;
            $value = $model->isDirty('payment_method') && filled($model->payment_method) ? $model->payment_method : 'method:'.$model->payment_method_id;
            $source = $model->inheritedPaymentMethod;
            if ($source) {
                abort_unless((int) $source['company_id'] === $companyId, 403);
                $value = 'method:'.$source['payment_method_id'];
            }
            $method = PaymentMethodService::resolve($value, $companyId, $branchId, $source !== null);
            $sameMethod = $model->exists && (int) $model->getRawOriginal('payment_method_id') === (int) $method->id;
            $name = $source['payment_method_name_snapshot'] ?? ($sameMethod ? $model->getRawOriginal('payment_method_name_snapshot') : null) ?? $method->display_name;
            $type = $source['payment_method_type_snapshot'] ?? ($sameMethod ? $model->getRawOriginal('payment_method_type_snapshot') : null) ?? $method->type;
            if (! is_finite((float) $model->amount) || (float) $model->amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.positive_amount')]);
            }
            $model->company_id = $companyId;
            $model->payment_method = $type;
            $model->payment_method_id = $method->id;
            $model->payment_method_name_snapshot = $name;
            $model->payment_method_type_snapshot = $type;
        });
    }

    public function paymentMethodLabel(): string
    {
        return $this->payment_method_name_snapshot ?: UiText::label($this->payment_method);
    }
}
