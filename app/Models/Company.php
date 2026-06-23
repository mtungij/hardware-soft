<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'company_name',
    'business_type',
    'tin_number',
    'vrn_number',
    'phone',
    'whatsapp_number',
    'email',
    'address',
    'region',
    'district',
    'country',
    'logo',
    'description',
    'currency',
    'timezone',
    'language',
])]
class Company extends Model
{
    use HasFactory;

    public static function current(): ?self
    {
        try {
            if (! Schema::hasTable('companies')) {
                return null;
            }

            return self::query()->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function whatsappLink(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->whatsapp_number);

        return $number ? 'https://wa.me/'.$number : null;
    }
}
