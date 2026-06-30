<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
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

            $user = Auth::guard('web')->user();

            if ($user instanceof User && ! $user->is_system_owner && $user->company_id) {
                return self::query()->find($user->company_id);
            }

            return self::query()->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function whatsappLink(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->whatsapp_number);

        return $number ? 'https://wa.me/'.$number : null;
    }
}
