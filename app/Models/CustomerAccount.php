<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class CustomerAccount extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'customer_id',
        'name',
        'phone',
        'email',
        'password',
        'status',
        'preferred_locale',
        'approved_at',
        'approved_by',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => str($this->status)->replace('_', ' ')->title());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CustomerDeposit::class);
    }

    public function portalNotifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CustomerMessage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
