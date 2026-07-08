<?php

namespace App\Http\Controllers;

use App\Services\ReportExportService;
use App\Support\InventorySettings;
use Illuminate\Http\Request;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format)
    {
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);
        abort_if(! InventorySettings::warehouseEnabled() && in_array($report, ['purchases', 'suppliers'], true), 403);

        $service = app(ReportExportService::class);
        $payload = $service->build('reports.'.$report, $request);

        if ($format === 'pdf') {
            abort_unless($request->user()?->can('export pdf'), 403);

            return response($service->generatePdf($payload['title'], $payload, $payload['filters']), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.str($payload['title'])->slug('_').'.pdf"',
            ]);
        }

        abort_unless($request->user()?->can('export excel'), 403);

        return $service->generateExcel($payload['title'], $payload, $payload['filters']);
    }
}
