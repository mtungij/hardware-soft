<?php

namespace App\Services;

use App\Models\AdditionalChargeType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerPurchaseRequest;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\AuthorizationScope;
use App\Support\NumberFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class B2bQuotationService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private ProductUnitConversionService $conversions,
        private InventoryService $inventory,
        private B2bDocumentPdfService $pdfs,
        private WhatsAppNotificationService $notifications,
        private B2bAuditService $audit,
    ) {}

    /** @param array<int, array<string, mixed>> $lines */
    public function createFromRequest(CustomerPurchaseRequest $request, User $staff, array $lines, string $documentType, mixed $validUntil, ?string $notes = null, ?string $terms = null, array $additionalCharges = []): Quotation
    {
        $this->authorizeStaff($staff, $request->company_id, $request->branch_id, 'customer_requests.create_quotation');
        if (! in_array($documentType, ['quotation', 'proforma'], true)) {
            throw ValidationException::withMessages(['document_type' => 'Select quotation or proforma.']);
        }

        return DB::transaction(function () use ($request, $staff, $lines, $documentType, $validUntil, $notes, $terms, $additionalCharges): Quotation {
            $request = CustomerPurchaseRequest::withoutGlobalScopes()->with('items')->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['pending', 'under_review'], true)) {
                throw ValidationException::withMessages(['request' => 'Only pending or under-review requests can be quoted.']);
            }
            $validUntil = CarbonImmutable::parse($validUntil)->startOfDay();
            if ($validUntil->isBefore(today())) {
                throw ValidationException::withMessages(['valid_until' => 'Validity date cannot be in the past.']);
            }
            $provided = collect($lines)->keyBy(fn ($line) => (int) ($line['request_item_id'] ?? 0));
            $prepared = [];
            $subtotal = $discount = $tax = 0.0;
            foreach ($request->items as $index => $requestItem) {
                $line = $provided->get($requestItem->id, []);
                $quantity = (float) ($line['quantity'] ?? $requestItem->transaction_quantity);
                $unitPrice = (float) ($line['unit_price'] ?? $requestItem->display_unit_price_snapshot);
                $discountPerUnit = (float) ($line['discount_per_unit'] ?? 0);
                $taxPerUnit = (float) ($line['tax_amount'] ?? 0);
                if ($quantity <= 0 || $unitPrice < 0 || $discountPerUnit < 0 || $discountPerUnit > $unitPrice || $taxPerUnit < 0) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Quotation quantity, price, discount, or tax is invalid.']);
                }
                if (abs($unitPrice - (float) $requestItem->display_unit_price_snapshot) > 0.001 && ! $staff->can('products.edit_selling_price')) {
                    throw new AuthorizationException('You are not allowed to override selling prices.');
                }
                if ($discountPerUnit > 0 && ! $staff->can('sales.discount')) {
                    throw new AuthorizationException('You are not allowed to apply discounts.');
                }
                $product = Product::withoutGlobalScopes()->with('unit')->where('company_id', $request->company_id)->where('status', 'active')->findOrFail($requestItem->product_id);
                $conversion = $this->conversions->resolveForSale($product, $requestItem->product_unit_conversion_id);
                $factor = $conversion ? (float) $requestItem->conversion_factor_snapshot : 1.0;
                $baseQuantity = round($quantity * $factor, 4);
                $gross = $quantity * $unitPrice;
                $lineDiscount = $quantity * $discountPerUnit;
                $lineTax = $quantity * $taxPerUnit;
                $lineTotal = $gross - $lineDiscount + $lineTax;
                $subtotal += $gross;
                $discount += $lineDiscount;
                $tax += $lineTax;
                $prepared[] = compact('requestItem', 'quantity', 'unitPrice', 'discountPerUnit', 'lineDiscount', 'lineTax', 'lineTotal', 'baseQuantity');
            }
            $prefix = $documentType === 'proforma' ? 'PRO' : 'QT';
            $type = $documentType === 'proforma' ? DocumentSequence::PROFORMA : DocumentSequence::QUOTATION;
            $preparedCharges = $this->prepareAdditionalCharges((int) $request->company_id, $staff, $additionalCharges);
            $chargeTotal = collect($preparedCharges)->sum('amount');
            $quotation = Quotation::withoutGlobalScopes()->create([
                'company_id' => $request->company_id, 'branch_id' => $request->branch_id,
                'customer_id' => $request->customer_id, 'customer_purchase_request_id' => $request->id,
                'created_by' => $staff->id, 'quotation_number' => $this->numbers->next($request->company_id, $type, $prefix),
                'document_type' => $documentType, 'status' => 'draft', 'quotation_date' => today(),
                'source_type' => 'customer_request',
                'valid_until' => $validUntil, 'subtotal' => $subtotal, 'discount_amount' => $discount,
                'tax_amount' => $tax, 'additional_charge_amount' => $chargeTotal,
                'total_amount' => max(0, $subtotal - $discount + $tax + $chargeTotal),
                'notes' => $notes, 'terms' => $terms,
            ]);
            foreach ($prepared as $line) {
                $item = $line['requestItem'];
                $quotation->items()->create([
                    'company_id' => $request->company_id, 'product_id' => $item->product_id,
                    'base_unit_id' => $item->base_unit_id, 'transaction_unit_id' => $item->transaction_unit_id,
                    'product_unit_conversion_id' => $item->product_unit_conversion_id,
                    'product_name_snapshot' => $item->product_name_snapshot, 'sku_snapshot' => $item->sku_snapshot,
                    'base_unit_name_snapshot' => $item->base_unit_name_snapshot,
                    'transaction_unit_name_snapshot' => $item->transaction_unit_name_snapshot,
                    'transaction_quantity' => $line['quantity'], 'conversion_factor_snapshot' => $item->conversion_factor_snapshot,
                    'base_quantity' => $line['baseQuantity'], 'unit_price' => $line['unitPrice'],
                    'discount_per_unit' => $line['discountPerUnit'], 'discount_amount' => $line['lineDiscount'],
                    'tax_amount' => $line['lineTax'], 'line_total' => $line['lineTotal'],
                ]);
            }
            foreach ($preparedCharges as $charge) {
                $quotation->additionalCharges()->create($charge);
            }
            $request->update(['status' => 'quoted', 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'quoted_at' => now()]);
            $this->audit->record($request, 'purchase_request', 'reviewed', $staff);
            $this->audit->record($quotation, 'quotation', 'created', $staff);

            return $quotation->load(['items', 'additionalCharges', 'company', 'customer', 'branch', 'creator']);
        });
    }

    /** @param array<int, array<string, mixed>> $lines */
    public function createDirect(
        Customer $customer,
        User $staff,
        int $branchId,
        array $lines,
        string $documentType,
        mixed $validUntil,
        string $creationKey,
        ?string $notes = null,
        ?string $terms = null,
        array $additionalCharges = [],
    ): Quotation {
        $this->authorizeStaff($staff, (int) $customer->company_id, $branchId, 'quotations.create');
        $this->validateCustomerAndBranch($customer, $branchId);
        $this->validateDocumentType($documentType, $validUntil);
        if (! Str::isUuid($creationKey)) {
            throw ValidationException::withMessages(['creation_key' => 'A valid document operation key is required.']);
        }

        return DB::transaction(function () use ($customer, $staff, $branchId, $lines, $documentType, $validUntil, $creationKey, $notes, $terms, $additionalCharges): Quotation {
            $existing = Quotation::withoutGlobalScopes()
                ->where('company_id', $customer->company_id)
                ->where('creation_key', $creationKey)
                ->first();
            if ($existing) {
                return $existing->load(['items', 'additionalCharges', 'company', 'customer', 'branch', 'creator']);
            }

            $prepared = $this->prepareStaffLines((int) $customer->company_id, $staff, $lines);
            $preparedCharges = $this->prepareAdditionalCharges((int) $customer->company_id, $staff, $additionalCharges);
            $chargeTotal = collect($preparedCharges)->sum('amount');
            $prefix = $documentType === 'proforma' ? 'PRO' : 'QT';
            $type = $documentType === 'proforma' ? DocumentSequence::PROFORMA : DocumentSequence::QUOTATION;
            $quotation = Quotation::withoutGlobalScopes()->create([
                'company_id' => $customer->company_id,
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'customer_purchase_request_id' => null,
                'created_by' => $staff->id,
                'quotation_number' => $this->numbers->next((int) $customer->company_id, $type, $prefix),
                'document_type' => $documentType,
                'source_type' => 'staff_created',
                'creation_key' => $creationKey,
                'status' => 'draft',
                'quotation_date' => today(),
                'valid_until' => CarbonImmutable::parse($validUntil)->startOfDay(),
                'subtotal' => collect($prepared)->sum('gross'),
                'discount_amount' => collect($prepared)->sum('lineDiscount'),
                'tax_amount' => collect($prepared)->sum('lineTax'),
                'additional_charge_amount' => $chargeTotal,
                'total_amount' => max(0, collect($prepared)->sum('lineTotal') + $chargeTotal),
                'notes' => $notes,
                'terms' => $terms,
            ]);

            foreach ($prepared as $line) {
                $quotation->items()->create($this->quotationItemPayload($line, (int) $customer->company_id));
            }
            foreach ($preparedCharges as $charge) {
                $quotation->additionalCharges()->create($charge);
            }

            $this->audit->record($quotation, 'quotation', 'created_by_staff', $staff, metadata: [
                'document_type' => $documentType,
                'source_type' => 'staff_created',
                'additional_charge_amount' => $chargeTotal,
            ]);

            return $quotation->load(['items', 'additionalCharges', 'company', 'customer', 'branch', 'creator']);
        });
    }

    public function send(Quotation $quotation, User $staff): Quotation
    {
        $this->authorizeStaff($staff, $quotation->company_id, $quotation->branch_id, 'quotations.send');
        if (! in_array($quotation->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['quotation' => 'Only draft or sent quotations can be sent.']);
        }
        $path = $quotation->pdf_path ?: $this->pdfs->quotation($quotation);
        $quotation->update(['status' => 'sent', 'sent_at' => $quotation->sent_at ?: now(), 'pdf_path' => $path]);
        $this->audit->record($quotation, 'quotation', 'sent', $staff);
        $quotation->loadMissing(['company', 'customer']);
        $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $quotation->company_id)->first();
        if ($setting?->enabled && $setting->categoryEnabled('quotations') && filled($quotation->customer->phone)) {
            $label = $quotation->document_type === 'proforma' ? 'Proforma Invoice' : 'Quotation';
            $message = "Habari {$quotation->customer->name},\n\n{$label} yako {$quotation->quotation_number} kutoka {$quotation->company->company_name} imeandaliwa.\n\nJumla: TZS ".NumberFormatter::money($quotation->total_amount)."\nValid Until: {$quotation->valid_until->format('d M Y')}\n\nPDF imeambatanishwa.\n\nAsante.";
            $this->notifications->afterCommit(fn (WhatsAppNotificationService $notifications) => $notifications->queuePhone(
                $quotation->company, $setting, $quotation->customer->phone, 'quotations', 'quotation_sent',
                'quotation:'.$quotation->id.':sent', $message, (int) $quotation->branch_id,
                ['quotation_id' => $quotation->id], $path, 'file',
            ));
        }

        return $quotation->refresh();
    }

    public function accept(Quotation $quotation, CustomerAccount $account): Quotation
    {
        return $this->customerDecision($quotation, $account, true, null);
    }

    public function reject(Quotation $quotation, CustomerAccount $account, ?string $reason): Quotation
    {
        return $this->customerDecision($quotation, $account, false, $reason);
    }

    public function markAcceptedOffline(Quotation $quotation, User $staff, string $reason): Quotation
    {
        $this->authorizeStaff($staff, (int) $quotation->company_id, (int) $quotation->branch_id, 'quotations.convert');
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['acceptance_reason' => 'Record how the customer accepted this document.']);
        }

        return DB::transaction(function () use ($quotation, $staff, $reason): Quotation {
            $quotation = Quotation::withoutGlobalScopes()->with('purchaseRequest')->lockForUpdate()->findOrFail($quotation->id);
            if ($quotation->status === 'accepted') {
                return $quotation;
            }
            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages(['quotation' => 'Only a sent quotation can be marked accepted.']);
            }
            if ($quotation->valid_until->isBefore(today())) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has expired and cannot be accepted.']);
            }
            $quotation->update(['status' => 'accepted', 'accepted_at' => now(), 'rejected_at' => null, 'rejection_reason' => null]);
            $quotation->purchaseRequest?->update(['status' => 'accepted', 'accepted_at' => now(), 'rejected_at' => null]);
            $this->audit->record($quotation, 'quotation', 'accepted_offline', $staff, $reason, ['source' => 'staff_recorded']);

            return $quotation->refresh();
        });
    }

    /** @param array<int, array<string, mixed>> $payments */
    public function convertToSale(Quotation $quotation, User $staff, int $stockLocationId, array $payments): Sale
    {
        $permission = $quotation->customer_purchase_request_id ? 'customer_requests.convert_to_sale' : 'quotations.convert';
        $this->authorizeStaff($staff, $quotation->company_id, $quotation->branch_id, $permission);

        $sale = DB::transaction(function () use ($quotation, $staff, $stockLocationId, $payments): Sale {
            $quotation = Quotation::withoutGlobalScopes()->with(['items', 'additionalCharges', 'purchaseRequest'])->lockForUpdate()->findOrFail($quotation->id);
            if ($quotation->converted_sale_id) {
                return Sale::withoutGlobalScopes()->findOrFail($quotation->converted_sale_id);
            }
            if ($quotation->status !== 'accepted') {
                throw ValidationException::withMessages(['quotation' => 'Only an accepted quotation can be converted to sale.']);
            }
            $location = StockLocation::withoutGlobalScopes()->where('company_id', $quotation->company_id)
                ->where('branch_id', $quotation->branch_id)->findOrFail($stockLocationId);
            $shortages = collect($this->availability($quotation, $location->id))->where('shortage', '>', 0);
            if ($shortages->isNotEmpty()) {
                throw ValidationException::withMessages(['stock' => $shortages->map(fn (array $row): string => $row['product'].': Required '.NumberFormatter::quantity($row['required']).', Available '.NumberFormatter::quantity($row['available']).', Shortage '.NumberFormatter::quantity($row['shortage']))->join("\n")]);
            }
            $cart = $quotation->items->map(fn ($item): array => [
                'product_id' => $item->product_id, 'stock_location_id' => $location->id,
                'quantity' => (float) $item->transaction_quantity,
                'product_unit_conversion_id' => $item->product_unit_conversion_id,
                'selling_unit_id' => $item->transaction_unit_id, 'sale_type' => 'retail',
                'approved_unit_price' => (float) $item->unit_price,
                'approved_conversion_factor' => (float) $item->conversion_factor_snapshot,
                'approved_base_quantity' => (float) $item->base_quantity,
                'discount_per_unit' => (float) $item->discount_per_unit,
                'tax_amount' => (float) $item->tax_amount / max(1, (float) $item->transaction_quantity),
            ])->all();
            $saleCharges = $quotation->additionalCharges->map(fn ($charge): array => [
                'quotation_additional_charge_id' => $charge->id,
                'additional_charge_type_id' => $charge->additional_charge_type_id,
                'charge_name_snapshot' => $charge->charge_name_snapshot,
                'description_snapshot' => $charge->description_snapshot,
                'amount' => (float) $charge->amount,
                'sort_order' => $charge->sort_order,
            ])->all();
            $sale = $this->inventory->completeSale(
                $cart, $payments, $quotation->customer_id, $location->id, $quotation->branch_id,
                $staff->id, 'Converted from '.$quotation->quotation_number, false, [],
                'quotation:'.$quotation->id.':converted', true, $saleCharges,
            );
            $quotation->update(['status' => 'converted', 'converted_sale_id' => $sale->id]);
            $quotation->purchaseRequest?->update(['status' => 'converted', 'sale_id' => $sale->id, 'converted_at' => now()]);
            $invoice = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
            if (! $invoice) {
                $invoice = SalesInvoice::withoutGlobalScopes()->create([
                    'sale_id' => $sale->id, 'company_id' => $sale->company_id,
                    'customer_id' => $sale->customer_id, 'quotation_id' => $quotation->id,
                    'source_type' => 'quotation',
                    'invoice_number' => $this->numbers->next($sale->company_id, DocumentSequence::SALES_INVOICE, 'INV'),
                ]);
            }
            $this->audit->record($quotation, 'quotation', 'converted', $staff, metadata: ['sale_id' => $sale->id, 'additional_charge_amount' => (float) $quotation->additional_charge_amount]);

            return $sale;
        });

        $invoiceId = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->value('id');
        if ($invoiceId) {
            $this->generateAndSendInvoice((int) $invoiceId);
        }

        return $sale;
    }

    /** @param array<int, array<string, mixed>> $lines @param array<int, array<string, mixed>> $payments */
    public function createDirectSale(
        Customer $customer,
        User $staff,
        int $branchId,
        int $stockLocationId,
        array $lines,
        array $payments,
        string $idempotencyKey,
        ?string $notes = null,
        array $additionalCharges = [],
    ): Sale {
        $this->authorizeStaff($staff, (int) $customer->company_id, $branchId, 'sales.create', 'sales_scope');
        if (! $staff->can('invoices.send')) {
            throw new AuthorizationException('You are not allowed to create and send final customer invoices.');
        }
        $this->validateCustomerAndBranch($customer, $branchId);
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid sale operation key is required.']);
        }

        $prepared = $this->prepareStaffLines((int) $customer->company_id, $staff, $lines);
        $preparedCharges = $this->prepareAdditionalCharges((int) $customer->company_id, $staff, $additionalCharges);
        $cart = collect($prepared)->map(fn (array $line): array => [
            'product_id' => $line['product']->id,
            'stock_location_id' => $stockLocationId,
            'quantity' => $line['quantity'],
            'product_unit_conversion_id' => $line['conversion']?->id,
            'selling_unit_id' => $line['transactionUnit']->id,
            'sale_type' => 'retail',
            'approved_unit_price' => $line['unitPrice'],
            'approved_conversion_factor' => $line['factor'],
            'approved_base_quantity' => $line['baseQuantity'],
            'discount_per_unit' => $line['discountPerUnit'],
            'tax_amount' => $line['taxPerUnit'],
        ])->all();

        $sale = DB::transaction(function () use ($customer, $staff, $branchId, $stockLocationId, $cart, $payments, $idempotencyKey, $notes, $preparedCharges): Sale {
            $sale = $this->inventory->completeSale(
                $cart,
                $payments,
                $customer->id,
                $stockLocationId,
                $branchId,
                $staff->id,
                $notes ?: 'Direct customer sale',
                false,
                [],
                $idempotencyKey,
                true,
                $preparedCharges,
            );
            $invoice = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
            if (! $invoice) {
                $invoice = SalesInvoice::withoutGlobalScopes()->create([
                    'sale_id' => $sale->id,
                    'company_id' => $sale->company_id,
                    'customer_id' => $sale->customer_id,
                    'quotation_id' => null,
                    'source_type' => 'direct_sale',
                    'invoice_number' => $this->numbers->next($sale->company_id, DocumentSequence::SALES_INVOICE, 'INV'),
                ]);
                $this->audit->record($invoice, 'sales_invoice', 'generated_from_direct_sale', $staff, metadata: ['sale_id' => $sale->id]);
            }

            return $sale;
        });

        $invoiceId = SalesInvoice::withoutGlobalScopes()->where('sale_id', $sale->id)->value('id');
        if ($invoiceId) {
            $this->generateAndSendInvoice((int) $invoiceId);
        }

        return $sale;
    }

    public function availability(Quotation $quotation, int $stockLocationId): array
    {
        return $quotation->loadMissing('items')->items->map(function ($item) use ($quotation, $stockLocationId): array {
            $available = $this->inventory->getProductStock($item->product_id, $stockLocationId, $quotation->branch_id);

            return ['item_id' => $item->id, 'product' => $item->product_name_snapshot, 'required' => (float) $item->base_quantity,
                'available' => $available, 'shortage' => max(0, (float) $item->base_quantity - $available)];
        })->all();
    }

    private function customerDecision(Quotation $quotation, CustomerAccount $account, bool $accept, ?string $reason): Quotation
    {
        $quotation = DB::transaction(function () use ($quotation, $account, $accept, $reason): Quotation {
            $quotation = Quotation::withoutGlobalScopes()->with(['purchaseRequest', 'customer'])->lockForUpdate()->findOrFail($quotation->id);
            if ((int) $quotation->company_id !== (int) $account->company_id || (int) $quotation->customer_id !== (int) $account->customer_id) {
                throw new AuthorizationException('This quotation does not belong to this customer account.');
            }
            $target = $accept ? 'accepted' : 'rejected';
            if ($quotation->status === $target) {
                return $quotation;
            }
            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages(['quotation' => 'Only a sent quotation can be accepted or rejected.']);
            }
            if ($accept && $quotation->valid_until->isBefore(today())) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has expired and cannot be accepted.']);
            }
            $quotation->update([
                'status' => $target, 'accepted_at' => $accept ? now() : null,
                'rejected_at' => $accept ? null : now(), 'rejection_reason' => $accept ? null : $reason,
            ]);
            $quotation->purchaseRequest?->update([
                'status' => $target, 'accepted_at' => $accept ? now() : null, 'rejected_at' => $accept ? null : now(),
            ]);
            $this->audit->record($quotation, 'quotation', $target, $account, $reason);

            return $quotation;
        });

        if ($accept) {
            $company = Company::query()->find($quotation->company_id);
            if ($company) {
                $message = "*QUOTATION ACCEPTED*\n\nCustomer: {$quotation->customer->name}\nQuotation: {$quotation->quotation_number}\nAmount: TZS ".NumberFormatter::money($quotation->total_amount)."\n\nCustomer accepted the quotation through HARDEX Customer Portal.";
                $this->notifications->afterCommit(fn (WhatsAppNotificationService $notifications) => $notifications->queueForRecipients(
                    $company, 'quotation_acceptance', 'quotation_accepted', 'quotation:'.$quotation->id.':accepted',
                    $message, (int) $quotation->branch_id, metadata: ['quotation_id' => $quotation->id],
                ));
            }
        }

        return $quotation->refresh();
    }

    private function generateAndSendInvoice(int $invoiceId): void
    {
        try {
            $invoice = SalesInvoice::withoutGlobalScopes()->with(['company', 'customer', 'sale'])->findOrFail($invoiceId);
            $newlyGenerated = blank($invoice->pdf_path);
            $path = $invoice->pdf_path ?: $this->pdfs->invoice($invoice);
            $invoice->update(['pdf_path' => $path, 'generated_at' => $invoice->generated_at ?: now()]);
            if ($newlyGenerated) {
                $this->audit->record($invoice, 'sales_invoice', 'generated', metadata: ['sale_id' => $invoice->sale_id]);
            }
            $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $invoice->company_id)->first();
            if (! $setting?->enabled || ! $setting->categoryEnabled('customer_invoices') || blank($invoice->customer?->phone)) {
                return;
            }
            $sale = $invoice->sale;
            $message = "Habari {$invoice->customer->name},\n\nMauzo yako {$invoice->invoice_number} yamekamilika.\n\nJumla: TZS ".NumberFormatter::money($sale->total_amount)."\nMalipo: TZS ".NumberFormatter::money($sale->paid_amount)."\nSalio: TZS ".NumberFormatter::money($sale->balance_amount)."\n\nInvoice imeambatanishwa.\n\nAsante kwa kununua {$invoice->company->company_name}.";
            $this->notifications->queuePhone(
                $invoice->company, $setting, $invoice->customer->phone, 'customer_invoices', 'customer_sales_invoice',
                'sale:'.$sale->id.':invoice:customer', $message, (int) $sale->branch_id,
                ['sale_id' => $sale->id, 'invoice_id' => $invoice->id], $path, 'file',
            );
            if (! $invoice->sent_at) {
                $invoice->update(['sent_at' => now()]);
                $this->audit->record($invoice, 'sales_invoice', 'sent');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function prepareStaffLines(int $companyId, User $staff, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product.']);
        }

        return collect($lines)->values()->map(function (array $line, int $index) use ($companyId, $staff): array {
            $product = Product::withoutGlobalScopes()->with('unit')->where('company_id', $companyId)->where('status', 'active')->find($line['product_id'] ?? null);
            if (! $product) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Select an active product belonging to this company.']);
            }
            $conversion = $this->conversions->resolveForSale($product, filled($line['product_unit_conversion_id'] ?? null) ? (int) $line['product_unit_conversion_id'] : null);
            $quantity = (float) ($line['quantity'] ?? 0);
            $authorizedPrice = $conversion ? $conversion->priceFor('retail') : (float) $product->selling_price;
            if ($authorizedPrice === null) {
                throw ValidationException::withMessages(["lines.{$index}.product_unit_conversion_id" => 'The selected selling unit has no retail price configured.']);
            }
            $unitPrice = filled($line['unit_price'] ?? null) ? (float) $line['unit_price'] : $authorizedPrice;
            $discountPerUnit = (float) ($line['discount_per_unit'] ?? 0);
            $taxPerUnit = (float) ($line['tax_amount'] ?? 0);
            if ($quantity <= 0 || $unitPrice < 0 || $discountPerUnit < 0 || $discountPerUnit >= $unitPrice || $taxPerUnit < 0) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Quantity, price, discount, or tax is invalid.']);
            }
            if (abs($unitPrice - $authorizedPrice) > 0.001 && ! $staff->can('products.edit_selling_price')) {
                throw new AuthorizationException('You are not allowed to override selling prices.');
            }
            if ($discountPerUnit > 0 && ! $staff->can('sales.discount')) {
                throw new AuthorizationException('You are not allowed to apply discounts.');
            }
            $factor = $conversion ? (float) $conversion->conversion_factor : 1.0;
            $baseQuantity = round($quantity * $factor, 4);
            $gross = $quantity * $unitPrice;
            $lineDiscount = $quantity * $discountPerUnit;
            $lineTax = $quantity * $taxPerUnit;
            $lineTotal = $gross - $lineDiscount + $lineTax;
            $transactionUnit = $conversion?->unit ?: $product->unit;

            return compact('product', 'conversion', 'transactionUnit', 'quantity', 'authorizedPrice', 'unitPrice', 'discountPerUnit', 'taxPerUnit', 'factor', 'baseQuantity', 'gross', 'lineDiscount', 'lineTax', 'lineTotal');
        })->all();
    }

    /** @param array<int, array<string, mixed>> $charges */
    private function prepareAdditionalCharges(int $companyId, User $staff, array $charges): array
    {
        $charges = collect($charges)->filter(fn (array $charge): bool => filled($charge['additional_charge_type_id'] ?? null) || filled($charge['amount'] ?? null))->values();
        if ($charges->isEmpty()) {
            return [];
        }
        if (! $staff->can('commercial_charges.apply')) {
            throw new AuthorizationException('You are not allowed to apply additional commercial charges.');
        }

        return $charges->map(function (array $charge, int $index) use ($companyId): array {
            $type = AdditionalChargeType::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($charge['additional_charge_type_id'] ?? null);
            if (! $type) {
                throw ValidationException::withMessages(["additional_charges.{$index}.additional_charge_type_id" => 'Select an active additional charge type belonging to this company.']);
            }
            $amount = (float) ($charge['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(["additional_charges.{$index}.amount" => 'Additional charge amount must be greater than zero.']);
            }

            return [
                'company_id' => $companyId,
                'additional_charge_type_id' => $type->id,
                'charge_name_snapshot' => $type->name,
                'description_snapshot' => filled($charge['description'] ?? null) ? trim((string) $charge['description']) : $type->description,
                'amount' => $amount,
                'sort_order' => (int) ($charge['sort_order'] ?? $index),
            ];
        })->all();
    }

    private function quotationItemPayload(array $line, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'product_id' => $line['product']->id,
            'base_unit_id' => $line['product']->unit_id,
            'transaction_unit_id' => $line['transactionUnit']->id,
            'product_unit_conversion_id' => $line['conversion']?->id,
            'product_name_snapshot' => $line['product']->displayNameWithSize(),
            'sku_snapshot' => $line['product']->sku,
            'base_unit_name_snapshot' => $line['product']->unit?->name ?: 'Base Unit',
            'transaction_unit_name_snapshot' => $line['transactionUnit']->name,
            'transaction_quantity' => $line['quantity'],
            'conversion_factor_snapshot' => $line['factor'],
            'base_quantity' => $line['baseQuantity'],
            'unit_price' => $line['unitPrice'],
            'discount_per_unit' => $line['discountPerUnit'],
            'discount_amount' => $line['lineDiscount'],
            'tax_amount' => $line['lineTax'],
            'line_total' => $line['lineTotal'],
        ];
    }

    private function validateCustomerAndBranch(Customer $customer, int $branchId): void
    {
        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
        }
        $validBranch = Branch::withoutGlobalScopes()->where('company_id', $customer->company_id)->where('status', 'active')->whereKey($branchId)->exists();
        if (! $validBranch) {
            throw ValidationException::withMessages(['branch_id' => 'Select an active branch belonging to this company.']);
        }
    }

    private function validateDocumentType(string $documentType, mixed $validUntil): void
    {
        if (! in_array($documentType, ['quotation', 'proforma'], true)) {
            throw ValidationException::withMessages(['document_type' => 'Select quotation or proforma.']);
        }
        if (CarbonImmutable::parse($validUntil)->startOfDay()->isBefore(today())) {
            throw ValidationException::withMessages(['valid_until' => 'Validity date cannot be in the past.']);
        }
    }

    private function authorizeStaff(User $staff, int $companyId, int $branchId, string $permission, string $scopeColumn = 'report_scope'): void
    {
        if ((int) $staff->company_id !== $companyId || ! $staff->can($permission)) {
            throw new AuthorizationException;
        }
        if (AuthorizationScope::scopeFor($staff, $scopeColumn, AuthorizationScope::BRANCH) !== AuthorizationScope::COMPANY
            && (int) $staff->branch_id !== $branchId) {
            throw new AuthorizationException;
        }
    }
}
