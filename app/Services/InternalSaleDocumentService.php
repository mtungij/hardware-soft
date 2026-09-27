<?php

namespace App\Services;

use App\Models\InternalSale;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class InternalSaleDocumentService
{
    public function data(InternalSale $sale, User $user, string $mode = 'delivery'): array
    {
        abort_unless(in_array($mode, ['delivery', 'value'], true), 404);
        abort_unless((int) $sale->company_id === (int) $user->company_id, 404);
        abort_unless($user->can('internal_sales.view') && $user->can(
            $mode === 'value' ? 'internal_sales.print_value_note' : 'internal_sales.print_delivery_note'
        ), 403);
        abort_unless(AuthorizationScope::canAccessStockLocation($user, (int) $sale->from_location_id)
            || AuthorizationScope::canAccessStockLocation($user, (int) $sale->to_location_id), 403);
        abort_unless($sale->status === 'completed', 409, 'Only completed Internal Sales have delivery documents.');

        $sale->load([
            'company', 'branch', 'fromLocation', 'toLocation', 'creator', 'completedBy',
            'items.product',
        ]);
        abort_unless($sale->company && $sale->branch && $sale->fromLocation && $sale->toLocation, 404);
        foreach ([$sale->branch, $sale->fromLocation, $sale->toLocation, $sale->creator, $sale->completedBy] as $record) {
            if ($record) {
                abort_unless((int) $record->company_id === (int) $sale->company_id, 404);
            }
        }
        foreach ($sale->items as $item) {
            abort_unless((int) $item->company_id === (int) $sale->company_id
                && $item->product && (int) $item->product->company_id === (int) $sale->company_id, 404);
        }

        $logo = null;
        if ($sale->company->logo) {
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($sale->company->logo)) {
                    $mime = $disk->mimeType($sale->company->logo);
                    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                        $logo = 'data:'.$mime.';base64,'.base64_encode($disk->get($sale->company->logo));
                    }
                }
            } catch (\Throwable) {
                $logo = null;
            }
        }

        return ['sale' => $sale, 'valueMode' => $mode === 'value', 'logo' => $logo];
    }

    public function pdf(array $data): string
    {
        $tempDir = storage_path('app/mpdf-temp');
        File::ensureDirectoryExists($tempDir);
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 15, 'margin_right' => 15,
            'margin_top' => 15, 'margin_bottom' => 18,
            'tempDir' => $tempDir,
        ]);
        $label = $data['valueMode'] ? 'Internal Sale Value Note' : 'Internal Delivery Note';
        $pdf->SetTitle($label.' '.$data['sale']->internal_sale_number);
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt;color:#64748b">Page {PAGENO} of {nbpg}</div>');
        $pdf->WriteHTML(view('documents.internal-sale-note', [...$data, 'isPdf' => true])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
