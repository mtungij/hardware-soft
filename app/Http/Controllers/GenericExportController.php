<?php

namespace App\Http\Controllers;

use App\Services\ReportExportService;
use Illuminate\Http\Request;

class GenericExportController extends Controller
{
    public function __invoke(Request $request, string $export, string $format, ReportExportService $service)
    {
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        if ($format === 'pdf') {
            abort_unless($request->user()?->can('export pdf'), 403);
        }

        if ($format === 'excel') {
            abort_unless($request->user()?->can('export excel'), 403);
        }

        $payload = $service->build($export, $request);

        if ($format === 'pdf') {
            $pdf = $service->generatePdf($payload['title'], $payload, $payload['filters']);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.str($payload['title'])->slug('_').'.pdf"',
            ]);
        }

        return $service->generateExcel($payload['title'], $payload, $payload['filters']);
    }
}
