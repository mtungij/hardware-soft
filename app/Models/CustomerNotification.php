<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustomerNotification extends Model
{
    use HasCompany;

    protected $fillable = [
        'company_id',
        'customer_account_id',
        'customer_id',
        'type',
        'title',
        'message',
        'priority',
        'notifiable_type',
        'notifiable_id',
        'read_at',
        'delivered_at',
        'channels',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'delivered_at' => 'datetime',
            'channels' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function displayTitle(): string
    {
        return $this->isSystemNotice() ? __('portal_notifications.'.$this->type.'_title') : (string) $this->title;
    }

    public function displayMessage(): string
    {
        return $this->isSystemNotice() ? UiText::feedback($this->message) : (string) $this->message;
    }

    private function isSystemNotice(): bool
    {
        return in_array($this->type, ['deposit_approved', 'deposit_rejected', 'receipt_approved', 'receipt_rejected'], true);
    }
}
