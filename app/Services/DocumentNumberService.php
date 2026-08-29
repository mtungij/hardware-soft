<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(int $companyId, string $type, string $prefix): string
    {
        return DB::transaction(function () use ($companyId, $type, $prefix): string {
            $year = (int) now()->format('Y');
            DocumentSequence::query()->firstOrCreate(
                ['company_id' => $companyId, 'document_type' => $type, 'year' => $year],
                ['last_number' => 0],
            );
            $sequence = DocumentSequence::query()
                ->where('company_id', $companyId)->where('document_type', $type)->where('year', $year)
                ->lockForUpdate()->firstOrFail();
            $sequence->increment('last_number');

            return sprintf('%s-%d-%06d', $prefix, $year, $sequence->last_number);
        });
    }
}
