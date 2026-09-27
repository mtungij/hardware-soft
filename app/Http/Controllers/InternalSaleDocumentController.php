<?php

namespace App\Http\Controllers;

use App\Models\InternalSale;
use App\Services\InternalSaleDocumentService;
use Illuminate\Support\Str;

class InternalSaleDocumentController extends Controller
{
    public function printDelivery(InternalSale $internalSale, InternalSaleDocumentService $service)
    {
        return $this->print($internalSale, $service, 'delivery');
    }

    public function pdfDelivery(InternalSale $internalSale, InternalSaleDocumentService $service)
    {
        return $this->pdf($internalSale, $service, 'delivery');
    }

    public function printValue(InternalSale $internalSale, InternalSaleDocumentService $service)
    {
        return $this->print($internalSale, $service, 'value');
    }

    public function pdfValue(InternalSale $internalSale, InternalSaleDocumentService $service)
    {
        return $this->pdf($internalSale, $service, 'value');
    }

    private function print(InternalSale $sale, InternalSaleDocumentService $service, string $mode)
    {
        return response()->view('documents.internal-sale-note', $service->data($sale, auth()->user(), $mode))
            ->header('Cache-Control', 'private, no-store');
    }

    private function pdf(InternalSale $sale, InternalSaleDocumentService $service, string $mode)
    {
        $data = $service->data($sale, auth()->user(), $mode);
        $name = $mode === 'value' ? 'internal-sale-value-note' : 'internal-delivery-note';

        return response($service->pdf($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'-'.Str::slug($sale->internal_sale_number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
