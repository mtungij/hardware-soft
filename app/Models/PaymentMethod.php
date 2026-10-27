<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasCompany;

    protected $table = 'payment_methods';

    protected $guarded = ['id'];

    public const TYPES = ['cash', 'mobile_money', 'bank', 'card', 'other'];

    protected static function booted(): void
    {
        static::saving(function (self $method) {
            if (! $method->is_active) {
                $method->is_default = false;
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'sort_order' => 'integer', 'show_on_quotation' => 'boolean', 'show_on_proforma' => 'boolean', 'show_on_invoice' => 'boolean'];
    }
}
