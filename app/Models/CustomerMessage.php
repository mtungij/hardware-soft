<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMessage extends Model
{
    protected $fillable = [
        'customer_id',
        'customer_account_id',
        'subject',
        'message',
        'attachment',
        'priority',
        'status',
        'channels',
        'sent_by',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class, 'notifiable_id')
            ->where('notifiable_type', self::class);
    }
}
