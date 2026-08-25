<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppNotificationService;
use App\Support\AuthorizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueueWhatsAppStockAlerts extends Command
{
    protected $signature = 'whatsapp:stock-alerts {--company=}';

    protected $description = 'Aggregate and queue low/out-of-stock alerts';

    public function handle(WhatsAppNotificationService $notifications): int
    {
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('enabled', true)->where('sending_paused', false)
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->get()->each(function (CompanyWhatsAppSetting $setting) use ($notifications): void {
                $company = Company::query()->find($setting->company_id);
                if (! $company) {
                    return;
                }

                $rowsByRecipient = [];
                $recipients = WhatsAppRecipient::withoutGlobalScopes()->with(['user.roles', 'branch'])
                    ->where('company_id', $company->id)->where('active', true)->get()
                    ->filter(fn (WhatsAppRecipient $recipient) => $recipient->accepts('stock_alerts', $recipient->branch_id));

                foreach ($recipients as $recipient) {
                    $rowsByRecipient[$recipient->id] = $this->stockRows($company->id, $recipient);
                }

                if (collect($rowsByRecipient)->every(fn (Collection $rows) => $rows->isEmpty())) {
                    return;
                }

                $state = collect($rowsByRecipient)->flatten(1)->map(fn ($row) => [$row->product_id, $row->stock_location_id, (string) $row->quantity])->unique()->sort()->values()->toJson();
                $bucket = intdiv(now()->timestamp, max(1, (int) $setting->low_stock_cooldown_hours) * 3600);

                $notifications->queueForRecipients(
                    $company, 'stock_alerts', 'low_stock_aggregate',
                    'stock-alert:'.hash('sha256', $state).':'.$bucket,
                    fn (WhatsAppRecipient $recipient): string => $this->stockMessage($rowsByRecipient[$recipient->id] ?? collect()),
                );
            });

        return self::SUCCESS;
    }

    private function stockRows(int $companyId, WhatsAppRecipient $recipient): Collection
    {
        $locationIds = $recipient->user
            ? AuthorizationScope::stockLocationIds($recipient->user)->all()
            : null;

        return DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->join('stock_locations', 'stock_locations.company_id', '=', 'products.company_id')
            ->leftJoin('stock_movements', function ($join): void {
                $join->on('stock_movements.product_id', '=', 'products.id')
                    ->on('stock_movements.stock_location_id', '=', 'stock_locations.id');
            })
            ->where('products.company_id', $companyId)
            ->where('products.status', 'active')->where('stock_locations.is_active', true)->where('stock_locations.status', 'active')
            ->when($recipient->scope === 'branch', fn ($query) => $query->where('stock_locations.branch_id', $recipient->branch_id))
            ->when($locationIds !== null, fn ($query) => $query->whereIn('stock_locations.id', $locationIds ?: [0]))
            ->select('products.id as product_id', 'products.name', 'products.reorder_level', 'units.short_name as unit', 'stock_locations.id as stock_location_id', 'stock_locations.name as location', DB::raw('COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) as quantity'))
            ->groupBy('products.id', 'products.name', 'products.reorder_level', 'units.short_name', 'stock_locations.id', 'stock_locations.name')
            ->havingRaw('COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) <= products.reorder_level')
            ->orderBy('products.name')->limit(50)->get();
    }

    private function stockMessage(Collection $rows): string
    {
        $lines = ['HARDEX STOCK ALERT', $rows->count().' products require attention:', ''];
        foreach ($rows as $index => $row) {
            $status = (float) $row->quantity <= 0 ? 'OUT' : 'LOW';
            $lines[] = ($index + 1).". {$row->name} — {$row->quantity} {$row->unit} ({$status}) @ {$row->location}";
        }

        return implode("\n", $lines);
    }
}
