<?php

namespace App\Console\Commands;

use App\Models\WhatsAppNotification;
use App\Services\WhatsAppNotificationService;
use Illuminate\Console\Command;

class RecoverQueuedWhatsAppNotifications extends Command
{
    protected $signature = 'whatsapp:recover-queued {--company= : Required company ID} {--dispatch : Re-dispatch eligible missing jobs; otherwise only inspect}';

    protected $description = 'Inspect recent, unattempted queued WhatsApp notifications and safely recover missing jobs';

    public function handle(WhatsAppNotificationService $service): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if (! $companyId || $companyId < 1) {
            $this->error('An explicit positive --company ID is required. No notifications were changed.');

            return self::FAILURE;
        }
        $notifications = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('status', 'queued')->where('attempts', 0)
            ->where('notification_type', '!=', 'debug_after_commit')
            ->where('queued_at', '<=', now()->subMinutes(5))->where('created_at', '>=', now()->subHour())
            ->orderBy('id')->limit(100)->get(['id']);
        foreach ($notifications as $notification) {
            $result = $service->recoverQueued($companyId, $notification->id, (bool) $this->option('dispatch'));
            $this->line("Notification {$notification->id}: {$result}");
            if ($result === 'unverified') {
                $this->warn('The queue backend could not be verified. Further recovery was stopped.');

                return self::FAILURE;
            }
        }
        $this->info($this->option('dispatch') ? 'Recovery complete. Existing and ineligible jobs were left untouched.' : 'Inspection only. Use --dispatch after reviewing eligible IDs.');

        return self::SUCCESS;
    }
}
