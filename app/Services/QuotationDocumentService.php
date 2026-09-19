<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Quotation;
use App\Support\NumberFormatter;
use App\Support\QuotationTemplateRegistry;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class QuotationDocumentService
{
    public function buildDocumentData(Quotation $quotation): array
    {
        $quotation->loadMissing(['company', 'branch', 'customer', 'creator', 'items', 'additionalCharges']);
        $company = $quotation->company;
        abort_unless($company && $quotation->customer && $quotation->branch, 404);
        foreach (collect([$quotation->customer, $quotation->branch, $quotation->creator])->merge($quotation->items)->merge($quotation->additionalCharges)->filter() as $record) {
            abort_unless((int) $record->company_id === (int) $quotation->company_id, 404);
        }
        $currency = $company->currency ?: 'TZS';
        $money = fn ($value) => $currency.' '.NumberFormatter::money($value);
        $methods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $quotation->company_id)->forDocument('quotation')->get();

        return [
            'company' => $this->companyData($company),
            'number' => $quotation->quotation_number,
            'date' => $quotation->quotation_date->format('d M Y'),
            'valid_until' => $quotation->valid_until?->format('d M Y'),
            'branch' => $quotation->branch->name,
            'customer' => $quotation->customer->only(['name', 'phone', 'address', 'email']),
            'items' => $quotation->items->map(fn ($item) => [
                'product' => $item->product_name_snapshot, 'sku' => $item->sku_snapshot,
                'unit' => $item->transaction_unit_name_snapshot,
                'quantity' => NumberFormatter::quantity($item->transaction_quantity),
                'price' => NumberFormatter::money($item->unit_price),
                'discount' => NumberFormatter::money($item->discount_amount),
                'total' => NumberFormatter::money($item->line_total),
            ])->all(),
            'currency' => $currency,
            'totals' => [
                ['label' => 'Subtotal', 'value' => $money($quotation->subtotal)],
                ['label' => 'Discount', 'value' => $money($quotation->discount_amount)],
                ['label' => 'Tax', 'value' => $money($quotation->tax_amount)],
            ],
            'charges' => $quotation->additionalCharges->map(fn ($charge) => [
                'label' => $charge->charge_name_snapshot, 'description' => $charge->description_snapshot,
                'value' => $money($charge->amount),
            ])->all(),
            'grand_total' => $money($quotation->total_amount),
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
            'payments' => $methods->map(fn ($method) => $method->only(['display_name', 'provider', 'bank_name', 'account_name', 'account_number', 'phone_or_business_number', 'branch_name', 'instructions']))->all(),
            'prepared_by' => $quotation->creator?->name,
            'sample' => false,
        ];
    }

    public function sample(Company $company, int $lineCount = 3): array
    {
        // Fixed presentation values only: no models saved, sequences reserved or financial service invoked.
        $examples = [
            ['product' => 'DANGOTE CEMENT 32.5N PREMIUM GENERAL PURPOSE CEMENT', 'sku' => 'CEM-001', 'unit' => 'Bag', 'quantity' => '20', 'price' => '25,000', 'discount' => '0', 'total' => '500,000'],
            ['product' => 'Nondo Y12 — high tensile reinforcement bar, 12 metres', 'sku' => 'NON-012', 'unit' => 'Piece', 'quantity' => '15', 'price' => '18,000', 'discount' => '0', 'total' => '270,000'],
            ['product' => 'Heavy-duty galvanized roofing fasteners', 'sku' => 'FIX-020', 'unit' => 'Box', 'quantity' => '2', 'price' => '15,000', 'discount' => '0', 'total' => '30,000'],
        ];
        $items = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $items[] = $examples[$i % count($examples)];
        }

        $subtotal = array_sum(array_map(fn ($item) => (int) str_replace(',', '', $item['total']), $items));

        return [
            'company' => $this->companyData($company), 'number' => 'QT-SAMPLE-0001',
            'date' => '13 Sep 2026', 'valid_until' => '27 Sep 2026', 'branch' => 'Sample Branch',
            'customer' => ['name' => 'HARDEX DEMO CUSTOMER', 'phone' => '', 'email' => '', 'address' => 'Sample construction project'],
            'items' => $items, 'currency' => 'TZS',
            'totals' => [['label' => 'Subtotal', 'value' => 'TZS '.NumberFormatter::money($subtotal)], ['label' => 'Discount', 'value' => 'TZS 0'], ['label' => 'Tax', 'value' => 'TZS 0']],
            'charges' => [['label' => 'Transport', 'description' => 'Sample delivery to project site', 'value' => 'TZS 50,000']],
            'grand_total' => 'TZS '.NumberFormatter::money($subtotal + 50000),
            'notes' => 'SAMPLE — for template preview only. No transaction has been created.',
            'terms' => 'Sample terms: payment and delivery subject to agreed terms.',
            'payments' => [], 'prepared_by' => 'Sample preparer', 'sample' => true,
        ];
    }

    public function html(array $document, string $key): string
    {
        $template = QuotationTemplateRegistry::require($key);

        return view($template['view'], compact('document', 'template'))->render();
    }

    public function pdf(array $document, string $key): string
    {
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $pdf = new Mpdf(['format' => 'A4', 'tempDir' => Storage::disk('local')->path('mpdf-temp'), 'margin_top' => 14, 'margin_bottom' => 18, 'margin_left' => 14, 'margin_right' => 14]);
        $pdf->SetTitle('Quotation '.$document['number']);
        $pdf->SetAuthor($document['company']['company_name']);
        $pdf->DefHTMLFooterByName('quotationFooter', '<table style="width:100%;border-top:1px solid #cbd5e1;color:#475569"><tr><td style="padding-top:2mm;font-size:8pt">'.e($document['number']).' · '.e($document['company']['company_name']).'</td><td style="padding-top:2mm;font-size:8pt;text-align:right">Page {PAGENO} of {nbpg}</td></tr></table>');
        $pdf->WriteHTML($this->html($document, $key));

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function companyData(Company $company): array
    {
        $data = $company->only(['company_name', 'address', 'phone', 'email', 'tin_number', 'vrn_number']);
        $data['logo'] = null;
        if ($company->logo && Storage::disk('public')->exists($company->logo)) {
            $mime = Storage::disk('public')->mimeType($company->logo);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $data['logo'] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($company->logo));
            }
        }

        return $data;
    }
}
