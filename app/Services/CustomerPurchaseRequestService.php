<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerAccount;
use App\Models\CustomerPurchaseRequest;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\User;
use App\Support\AuthorizationScope;
use App\Support\NumberFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPurchaseRequestService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private ProductUnitConversionService $conversions,
        private WhatsAppNotificationService $notifications,
        private B2bAuditService $audit,
    ) {}

    /** @param array<int, array<string, mixed>> $items */
    public function submit(CustomerAccount $account, ?int $branchId, ?string $notes, array $items, string $submissionKey): CustomerPurchaseRequest
    {
        $existing = CustomerPurchaseRequest::withoutGlobalScopes()
            ->where('customer_account_id', $account->id)->where('submission_key', $submissionKey)->first();
        if ($existing) {
            return $existing;
        }

        $request = DB::transaction(function () use ($account, $branchId, $notes, $items, $submissionKey): CustomerPurchaseRequest {
            $account = CustomerAccount::withoutGlobalScopes()->with('customer')->lockForUpdate()->findOrFail($account->id);
            if (! $account->isActive() || ! $account->customer || (int) $account->customer->company_id !== (int) $account->company_id) {
                throw ValidationException::withMessages(['account' => 'The customer account is not active or is not linked correctly.']);
            }
            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Add at least one product.']);
            }
            $branch = $branchId
                ? Branch::withoutGlobalScopes()->where('company_id', $account->company_id)->where('status', 'active')->find($branchId)
                : Branch::withoutGlobalScopes()->where('company_id', $account->company_id)->where('status', 'active')->orderByDesc('is_default')->first();
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'Select an active branch belonging to this company.']);
            }

            $request = CustomerPurchaseRequest::withoutGlobalScopes()->create([
                'company_id' => $account->company_id,
                'branch_id' => $branch->id,
                'customer_id' => $account->customer_id,
                'customer_account_id' => $account->id,
                'request_number' => $this->numbers->next($account->company_id, DocumentSequence::CUSTOMER_PURCHASE_REQUEST, 'REQ'),
                'status' => 'pending',
                'submission_key' => $submissionKey,
                'customer_notes' => $notes,
                'submitted_at' => now(),
            ]);

            foreach ($items as $index => $row) {
                $product = Product::withoutGlobalScopes()->with(['unit', 'unitConversions.unit'])
                    ->where('company_id', $account->company_id)->where('status', 'active')
                    ->find($row['product_id'] ?? null);
                if (! $product) {
                    throw ValidationException::withMessages(["items.{$index}.product_id" => 'Select an active product belonging to this company.']);
                }
                if (! is_numeric($row['quantity'] ?? null) || (float) $row['quantity'] <= 0) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be greater than zero.']);
                }
                $quantity = round((float) $row['quantity'], 4);
                $conversion = $this->conversions->resolveForSale($product, filled($row['product_unit_conversion_id'] ?? null) ? (int) $row['product_unit_conversion_id'] : null);
                $factor = $conversion ? (float) $conversion->conversion_factor : 1.0;
                $baseQuantity = round($quantity * $factor, 4);
                if (! $product->acceptsStockQuantity($baseQuantity)) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => 'The selected quantity is invalid for the product base unit.']);
                }
                $unit = $conversion?->unit ?: $product->unit;
                $price = $conversion?->priceFor('retail') ?? (float) $product->selling_price;
                $request->items()->create([
                    'company_id' => $account->company_id,
                    'product_id' => $product->id,
                    'base_unit_id' => $product->unit_id,
                    'transaction_unit_id' => $unit->id,
                    'product_unit_conversion_id' => $conversion?->id,
                    'product_name_snapshot' => $product->displayNameWithSize(),
                    'sku_snapshot' => $product->sku,
                    'base_unit_name_snapshot' => $product->unit?->short_name ?: $product->unit?->name,
                    'transaction_unit_name_snapshot' => $unit?->short_name ?: $unit?->name,
                    'transaction_quantity' => $quantity,
                    'conversion_factor_snapshot' => $factor,
                    'base_quantity' => $baseQuantity,
                    'display_unit_price_snapshot' => $price,
                    'customer_notes' => $row['notes'] ?? null,
                ]);
            }
            $this->audit->record($request, 'purchase_request', 'submitted', $account);

            return $request->load(['items', 'branch', 'customer']);
        });

        DB::afterCommit(function () use ($request): void {
            $company = Company::query()->find($request->company_id);
            if (! $company) {
                return;
            }
            $localization = app(WhatsAppLocalization::class);
            $label = fn (string $key): string => $localization->get($company, 'b2b.'.$key);
            $estimated = $request->items->sum(fn ($item): float => (float) $item->transaction_quantity * (float) $item->display_unit_price_snapshot);
            $message = implode("\n", [
                '*'.$label('request_title').'*', '',
                $label('request').': '.$request->request_number,
                $label('customer').': '.$request->customer->name,
                $label('branch').': '.$request->branch->name,
                $label('items').': '.$request->items->count(),
                $label('estimated_value').': TZS '.NumberFormatter::money($estimated), '',
                $label('request_submitted'), '',
                $label('review_request'),
            ]);
            $this->notifications->queueForRecipients(
                $company, 'customer_requests', 'customer_purchase_request_submitted',
                'purchase-request:'.$request->id.':submitted', $message, (int) $request->branch_id,
                metadata: ['purchase_request_id' => $request->id, 'request_number' => $request->request_number],
            );
        });

        return $request;
    }

    public function beginReview(CustomerPurchaseRequest $request, User $staff): CustomerPurchaseRequest
    {
        if ((int) $request->company_id !== (int) $staff->company_id || ! $staff->can('customer_requests.review')
            || (AuthorizationScope::scopeFor($staff, 'report_scope', AuthorizationScope::BRANCH) !== AuthorizationScope::COMPANY
                && (int) $request->branch_id !== (int) $staff->branch_id)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $staff): CustomerPurchaseRequest {
            $request = CustomerPurchaseRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if ($request->status === 'pending') {
                $request->update(['status' => 'under_review', 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
                $this->audit->record($request, 'purchase_request', 'review_started', $staff);
            }

            return $request->refresh();
        });
    }
}
