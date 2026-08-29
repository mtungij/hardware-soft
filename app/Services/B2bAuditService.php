<?php

namespace App\Services;

use App\Models\B2bDocumentEvent;
use Illuminate\Database\Eloquent\Model;

class B2bAuditService
{
    public function record(Model $document, string $documentType, string $event, mixed $actor = null, ?string $reason = null, array $metadata = []): B2bDocumentEvent
    {
        return B2bDocumentEvent::withoutGlobalScopes()->create([
            'company_id' => $document->company_id,
            'document_type' => $documentType,
            'document_id' => $document->getKey(),
            'event' => $event,
            'actor_type' => $actor ? class_basename($actor) : 'system',
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
