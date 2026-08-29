<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppNotificationService;
use App\Services\WhatsAppStockAlertPdfService;
use App\Services\WhatsAppStockAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class QueueWhatsAppStockAlerts extends Command
{
    protected $signature = 'whatsapp:stock-alerts {--company=}';

    protected $description = 'Aggregate and queue low/out-of-stock alerts';

    public function handle(
        WhatsAppNotificationService $notifications,
        WhatsAppStockAlertService $alerts,
        WhatsAppStockAlertPdfService $pdfs,
    ): int {
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('enabled', true)->where('sending_paused', false)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->get()->each(function (CompanyWhatsAppSetting $setting) use ($notifications, $alerts, $pdfs): void {
                $company = Company::query()->find($setting->company_id);
                if (! $company) {
                    return;
                }

                $generatedAt = CarbonImmutable::now($setting->timezone);
                $rowsByRecipient = [];
                $recipients = WhatsAppRecipient::withoutGlobalScopes()->with(['user.roles', 'branch'])
                    ->where('company_id', $company->id)->where('active', true)->get()
                    ->filter(fn (WhatsAppRecipient $recipient) => $recipient->accepts('stock_alerts', $recipient->branch_id));

                foreach ($recipients as $recipient) {
                    $rowsByRecipient[$recipient->id] = $alerts->rows($company, $recipient);
                }

                if (collect($rowsByRecipient)->every(fn (Collection $rows) => $rows->isEmpty())) {
                    return;
                }

                $state = collect($rowsByRecipient)->flatten(1)
                    ->map(fn ($row) => [$row->product_id, $row->stock_location_id, (string) $row->quantity])
                    ->unique()->sort()->values()->toJson();
                $bucket = intdiv(now()->timestamp, max(1, (int) $setting->low_stock_cooldown_hours) * 3600);
                $stateHash = hash('sha256', $state);
                $eventKey = 'stock-alert:'.$stateHash.':'.$bucket;

                foreach ($recipients as $recipient) {
                    $rows = $rowsByRecipient[$recipient->id] ?? collect();
                    if ($rows->isEmpty()) {
                        continue;
                    }

                    $attachment = null;
                    $pdfFailure = null;
                    if ($setting->attach_stock_alert_pdf) {
                        try {
                            $attachment = $pdfs->generate($company, $recipient, $generatedAt, $rows, substr($stateHash, 0, 16).'-'.$bucket);
                        } catch (Throwable $exception) {
                            report($exception);
                            $pdfFailure = 'Recipient-scoped stock PDF generation failed; the text summary remains queued.';
                        }
                    }

                    $notifications->queueRecipient(
                        $company,
                        $setting,
                        $recipient,
                        'stock_alerts',
                        'low_stock_aggregate',
                        $eventKey,
                        $alerts->message($company, $recipient, $generatedAt, $rows, $attachment !== null, $setting->attach_stock_alert_pdf),
                        $recipient->scope === 'branch' ? (int) $recipient->branch_id : null,
                        $attachment,
                        $attachment ? 'file' : null,
                        [
                            'generated_at' => $generatedAt->toIso8601String(),
                            'scope' => $alerts->scopeLabel($company, $recipient),
                            'total_count' => $rows->count(),
                            'out_of_stock_count' => $rows->where('status', 'OUT OF STOCK')->count(),
                            'low_stock_count' => $rows->where('status', 'LOW STOCK')->count(),
                            'pdf_generation_failure' => $pdfFailure,
                        ],
                    );
                }
            });

        return self::SUCCESS;
    }
}
