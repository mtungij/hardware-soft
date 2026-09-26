<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockLocation;
use App\Services\StockLedgerReadService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class StockLedgerPdfController extends Controller
{
    public function __invoke(Product $product, StockLocation $location, StockLedgerReadService $ledger)
    {
        $user = auth()->user();
        $product->loadMissing(['unit', 'size']);
        $report = $ledger->ledger($product, $location, $user);
        $company = $user->company;
        $logo = null;
        if ($company?->logo) {
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($company->logo)) {
                    $mime = $disk->mimeType($company->logo);
                    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                        $logo = 'data:'.$mime.';base64,'.base64_encode($disk->get($company->logo));
                    }
                }
            } catch (\Throwable) {
                $logo = null;
            }
        }

        $tempDir = storage_path('app/mpdf-temp');
        File::ensureDirectoryExists($tempDir);
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 16,
            'tempDir' => $tempDir,
        ]);
        $pdf->SetTitle('Stock Ledger · '.$product->sku.' · '.$location->name);
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt;color:#475569">Page {PAGENO} of {nbpg}</div>');
        $pdf->WriteHTML(view('pdf.stock-ledger', [
            'product' => $product,
            'location' => $location,
            'report' => $report,
            'company' => $company,
            'logo' => $logo,
            'canViewValue' => $user->can('stock.view_value'),
        ])->render());

        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="stock-ledger-'.str($product->sku.'-'.$location->code)->slug().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
