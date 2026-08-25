<?php

namespace App\Services;

use App\Models\WhatsAppSettingAudit;

class WhatsAppAuditService
{
    public function record(int $companyId, string $action, ?array $before = null, ?array $after = null): void
    {
        WhatsAppSettingAudit::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'action' => $action,
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
            'ip_address' => request()?->ip(),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        unset($values['gowa_username'], $values['gowa_password']);

        return $values;
    }
}
