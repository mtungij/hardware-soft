<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use Illuminate\Database\Eloquent\Builder;

class CustomerPortalCatalogue
{
    public function branch(CustomerAccount $account, ?int $branchId = null): Branch
    {
        abort_unless($account->customer && (int) $account->customer->company_id === (int) $account->company_id, 403);

        return Branch::withoutGlobalScopes()->where('company_id', $account->company_id)->where('status', 'active')
            ->when($branchId ?: $account->customer->branch_id, fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('is_default')->firstOrFail();
    }

    public function products(CustomerAccount $account, ?int $branchId = null, string $search = ''): Builder
    {
        $branch = $this->branch($account, $branchId);

        return Product::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $account->company_id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->with(['unit' => fn ($q) => $q->where('company_id', $account->company_id), 'category' => fn ($q) => $q->where('company_id', $account->company_id), 'unitConversions' => fn ($q) => $q->where('company_id', $account->company_id)->where('active', true)->where('can_sell', true)->with('unit')])
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', '%'.$search.'%')
                ->orWhereHas('category', fn ($q) => $q->where('company_id', $account->company_id)->where('name', 'like', '%'.$search.'%'))))
            ->orderBy('name');
    }

    public function location(CustomerAccount $account, Branch $branch): ?StockLocation
    {
        $settings = Setting::withoutGlobalScopes()->where('company_id', $account->company_id)->first();

        return StockLocation::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $account->company_id)
            ->where('branch_id', $branch->id)->where('status', 'active')->where('is_active', true)
            ->where('can_sell', true)->where('is_sellable', true)
            ->where(fn ($q) => $q->where('type', 'dispensing')->orWhere('is_dispensing_location', true)
                ->when($settings?->enable_warehouse && $settings?->allow_sales_from_store, fn ($q) => $q->orWhere('type', 'store')))
            ->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** An explicit allowlist keeps cost, stock and hidden prices out of every catalogue response. */
    public function payload(Product $product, CustomerAccount $account, Branch $branch): array
    {
        abort_unless((int) $product->company_id === (int) $account->company_id
            && (! $product->branch_id || (int) $product->branch_id === (int) $branch->id), 403);
        $showPrices = (bool) Company::findOrFail($account->company_id)->customer_portal_show_prices;
        $location = $this->location($account, $branch);
        $payload = [
            'id' => $product->id, 'name' => $product->displayNameWithSize(), 'sku' => $product->sku,
            'category' => $product->category?->name, 'unit' => $product->unit?->short_name ?: $product->unit?->name,
            'image_url' => $product->image_url,
            'units' => $product->unitConversions->map(function ($conversion) use ($product, $showPrices, $location) {
                $row = ['id' => $conversion->id, 'name' => $conversion->unit?->short_name ?: $conversion->unit?->name,
                    'conversion_factor' => (float) $conversion->conversion_factor];
                if ($showPrices) {
                    $row['selling_price'] = $location ? app(LocationPriceService::class)->priceFor($product, $location, 'retail', $conversion) : null;
                }

                return $row;
            })->values()->all(),
        ];
        if ($showPrices) {
            $payload['selling_price'] = $location ? app(LocationPriceService::class)->priceFor($product, $location, 'retail') : null;
        }

        return $payload;
    }
}
