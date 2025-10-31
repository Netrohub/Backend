<?php

namespace App\Helpers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditHelper
{
    /**
     * Log an audit event
     *
     * @param string $action Action name (e.g., 'wallet.withdraw', 'dispute.resolve')
     * @param string|null $modelType Model class name (e.g., 'App\Models\Wallet')
     * @param int|null $modelId Model ID
     * @param array|null $oldValues Old values before change
     * @param array|null $newValues New values after change
     * @param Request|null $request Request instance for IP and user agent
     * @param int|null $userId User ID (if different from authenticated user)
     * @return AuditLog
     */
    public static function log(
        string $action,
        ?string $modelType = null,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?int $userId = null
    ): AuditLog {
        try {
            $user = $request?->user();
            $userId = $userId ?? $user?->id;

            return AuditLog::create([
                'user_id' => $userId,
                'action' => $action,
                'model_type' => $modelType,
                'model_id' => $modelId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Log audit failure but don't break the main flow
            Log::error('Failed to create audit log', [
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return a dummy audit log to prevent errors
            return new AuditLog();
        }
    }
}

