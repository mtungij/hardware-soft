<?php

namespace App\Services;

use App\Models\StockTransfer;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class StockTransferNoteService
{
    public function authorize(StockTransfer $transfer, User $user): void
    {
        abort_unless((int) $transfer->company_id === (int) $user->company_id, 404);
        abort_unless($user->can('stock.view'), 403);
        foreach ([$transfer->from_location_id, $transfer->to_location_id] as $id) {
            abort_unless(AuthorizationScope::stockLocationsForBranch($user, 'can_view', (int) $transfer->branch_id)->contains('id', $id), 403);
        }

        $transfer->load(['company', 'branch', 'fromLocation.branch', 'toLocation.branch', 'createdBy', 'completedBy', 'items.product.unit']);
        $related = collect([$transfer->branch, $transfer->fromLocation, $transfer->toLocation, $transfer->fromLocation?->branch, $transfer->toLocation?->branch, $transfer->createdBy, $transfer->completedBy]);
        foreach ($transfer->items as $item) {
            $related->push($item, $item->product, $item->product?->unit);
        }
        foreach ($related->filter() as $record) {
            abort_unless((int) $record->company_id === (int) $transfer->company_id, 404);
        }
        abort_unless($transfer->company, 404);
    }

    public function data(StockTransfer $transfer): array
    {
        $this->authorize($transfer, auth()->user());
        abort_unless($transfer->status === 'completed', 409, 'Only completed transfers have a Stock Transfer Note.');
        $totals = $transfer->items->groupBy(fn ($item) => $item->product?->unit_id ?? 'unknown-'.$item->id)
            ->map(fn ($items) => ['unit' => $items->first()->product?->unit?->short_name ?? 'Unit unavailable', 'quantity' => $items->sum('quantity')]);
        $logo = null;
        $path = $transfer->company->logo;
        if ($path && Storage::disk('public')->exists($path)) {
            $mime = Storage::disk('public')->mimeType($path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
            }
        }

        return compact('transfer', 'totals', 'logo');
    }

    public function pdf(array $data): string
    {
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $pdf = new Mpdf(['format' => 'A4', 'tempDir' => Storage::disk('local')->path('mpdf-temp'), 'margin_bottom' => 18]);
        $pdf->SetTitle('Stock Transfer Note '.$data['transfer']->transfer_number);
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt;color:#64748b">Page {PAGENO} of {nbpg}</div>');
        $pdf->WriteHTML(view('documents.stock-transfer-note', [...$data, 'isPdf' => true])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
