<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementCustomer extends Model
{
    use HasCompany;

    protected $fillable = [
        'company_id',
        'announcement_id',
        'customer_id',
        'customer_account_id',
        'is_delivered',
        'delivered_at',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_delivered' => 'boolean',
            'delivered_at' => 'datetime',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
