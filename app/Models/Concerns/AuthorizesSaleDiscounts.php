<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait AuthorizesSaleDiscounts
{
    protected static function bootAuthorizesSaleDiscounts(): void
    {
        static::saving(function ($model) {
            $fields = ['discount_amount', 'discount_per_unit', 'discount_total', 'allocated_order_discount', 'order_discount_value', 'item_discount_value'];
            $changed = $model->isDirty([...$fields, 'discount_mode', 'order_discount_type', 'item_discount_type']);
            $hasDiscount = collect($fields)->contains(fn ($field) => (float) $model->getAttribute($field) > 0
                || ($model->exists && (float) $model->getRawOriginal($field) > 0));
            if (! $changed || ! $hasDiscount) {
                return;
            }
            $actor = auth()->id() ? User::withoutGlobalScopes()->find(auth()->id()) : null;
            $companyId = $model->company_id ?: ($model instanceof SaleItem
                ? $model->sale()->value('company_id') : Branch::query()->whereKey($model->branch_id)->value('company_id'));
            if (! $actor || (int) $actor->company_id !== (int) $companyId || ! $actor->can('sales.discount')) {
                throw ValidationException::withMessages(['discount_mode' => __('application.you_are_not_authorized_to_apply_discounts_ba8e3486')]);
            }
        });
    }
}
