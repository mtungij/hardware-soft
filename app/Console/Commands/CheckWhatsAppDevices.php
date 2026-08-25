<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppNotification;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppNotification;
use App\Services\Gowa;
use Illuminate\Console\Command;
use Throwable;

class CheckWhatsAppDevices extends Command
{
    protected $signature = 'whatsapp:check-devices {--company=}';

    protected $description = 'Check configured company WhatsApp devices and resume eligible pending messages';

    public function handle(Gowa $gowa): int
    {
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('enabled', true)
            ->whereNotNull('device_id')
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->each(function (CompanyWhatsAppSetting $setting) use ($gowa): void {
                try {
                    $state = $gowa->deviceState($gowa->deviceStatus($setting->device_id));
                    $setting->update(['last_device_state' => $state, 'last_checked_at' => now()]);

                    if ($state === 'logged_in' && ! $setting->sending_paused) {
                        WhatsAppNotification::withoutGlobalScopes()
                            ->where('company_id', $setting->company_id)
                            ->where('device_id', $setting->device_id)
                            ->where('status', 'pending')
                            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                            ->orderBy('id')->limit(100)->get()
                            ->each(function (WhatsAppNotification $notification): void {
                                $notification->update(['status' => 'queued', 'queued_at' => now(), 'failure_reason' => null]);
                                SendWhatsAppNotification::dispatch($notification->id)->onQueue('whatsapp');
                            });
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $setting->update(['last_device_state' => 'unknown', 'last_checked_at' => now()]);
                }
            });

        return self::SUCCESS;
    }
}
