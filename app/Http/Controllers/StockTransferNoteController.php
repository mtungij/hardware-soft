<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Services\StockTransferNoteService;
use Illuminate\Support\Str;

class StockTransferNoteController extends Controller
{
    public function print(StockTransfer $stockTransfer, StockTransferNoteService $service)
    {
        return response()->view('documents.stock-transfer-note', $service->data($stockTransfer))->header('Cache-Control', 'private, no-store');
    }

    public function pdf(StockTransfer $stockTransfer, StockTransferNoteService $service)
    {
        return response($service->pdf($service->data($stockTransfer)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="stock-transfer-note-'.Str::slug($stockTransfer->transfer_number).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
