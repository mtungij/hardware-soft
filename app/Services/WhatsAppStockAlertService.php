<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsAppRecipient;
use App\Support\AuthorizationScope;
use App\Support\NumberFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WhatsAppStockAlertService
{
    /** @return Collection<int, object> */
    public function rows(Company $company, WhatsAppRecipient $recipient): Collection
    {
        $locationIds = $recipient->user
            ? AuthorizationScope::stockLocationIds($recipient->user)->all()
            : null;

        return DB::table('stock_movements')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'stock_movements.product_id')
                    ->on('products.company_id', '=', 'stock_movements.company_id');
            })
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->join('stock_locations', function ($join): void {
                $join->on('stock_locations.id', '=', 'stock_movements.stock_location_id')
                    ->on('stock_locations.company_id', '=', 'stock_movements.company_id');
            })
            ->leftJoin('branches', function ($join): void {
                $join->on('branches.id', '=', 'stock_locations.branch_id')
                    ->on('branches.company_id', '=', 'stock_movements.company_id');
            })
            ->where('stock_movements.company_id', $company->id)
            ->where('products.status', 'active')
            ->whereNull('products.deleted_at')
            ->where('stock_locations.is_active', true)
            ->where('stock_locations.status', 'active')
            ->whereNull('stock_locations.deleted_at')
            ->when($recipient->scope === 'branch', fn ($query) => $query->where('stock_locations.branch_id', $recipient->branch_id))
            ->when($locationIds !== null, fn ($query) => $query->whereIn('stock_locations.id', $locationIds ?: [0]))
            ->select(
                'products.id as product_id',
                'products.name',
                'products.sku',
                'products.reorder_level',
                'units.short_name as unit',
                'stock_locations.id as stock_location_id',
                'stock_locations.name as location',
                'stock_locations.branch_id',
                'branches.name as branch',
                DB::raw('COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) as quantity'),
            )
            ->groupBy(
                'products.id', 'products.name', 'products.sku', 'products.reorder_level',
                'units.short_name', 'stock_locations.id', 'stock_locations.name',
                'stock_locations.branch_id', 'branches.name',
            )
            ->havingRaw('COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) <= products.reorder_level')
            ->orderByRaw('CASE WHEN COALESCE(SUM(stock_movements.quantity_in - stock_movements.quantity_out), 0) <= 0 THEN 0 ELSE 1 END')
            ->orderBy('products.name')
            ->orderBy('stock_locations.name')
            ->get()
            ->each(function (object $row): void {
                $row->status = (float) $row->quantity <= 0 ? 'OUT OF STOCK' : 'LOW STOCK';
            });
    }

    public function message(
        Company $company,
        WhatsAppRecipient $recipient,
        CarbonInterface $generatedAt,
        Collection $rows,
        bool $hasAttachment,
        bool $attachmentEnabled,
    ): string {
        $out = $rows->where('status', 'OUT OF STOCK')->count();
        $low = $rows->where('status', 'LOW STOCK')->count();

        return implode("\n", [
            '*HARDEX STOCK ALERT*',
            '',
            $rows->count().' stock items require attention.',
            '',
            'Out of Stock: '.$out,
            'Low Stock: '.$low,
            '',
            ($recipient->scope === 'branch' ? 'Branch: ' : 'Scope: ').$this->scopeLabel($company, $recipient),
            'Generated: '.$generatedAt->format('d M Y H:i'),
            '',
            match (true) {
                $hasAttachment => 'Full Low / Out of Stock report is attached.',
                $attachmentEnabled => 'The full report could not be attached; view HARDEX POS for details.',
                default => 'PDF attachment is disabled; view HARDEX POS for details.',
            },
            '',
            'HARDEX POS',
        ]);
    }

    public function scopeLabel(Company $company, WhatsAppRecipient $recipient): string
    {
        return $recipient->scope === 'branch'
            ? ($recipient->branch?->name ?: 'Branch')
            : $company->company_name.' (All authorized locations)';
    }

    public function quantity(float|int|string|null $value): string
    {
        return NumberFormatter::quantity($value);
    }
}
