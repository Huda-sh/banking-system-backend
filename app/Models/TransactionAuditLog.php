<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\AuditAction;

class TransactionAuditLog extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'transaction_id', 'user_id', 'action', 'ip_address',
        'old_data', 'new_data', 'additional_info'
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'action' => AuditAction::class,
        'additional_info' => 'array'
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            $log->ip_address = $log->ip_address ?? request()->ip() ?? 'system';
            $log->user_id = $log->user_id ?? auth()->id();
        });
    }

    /**
     * Relationships
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scopes
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByTransaction($query, $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeByIp($query, $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Business logic methods
     */
    public function getActionLabel(): string
    {
        return match($this->action) {
            AuditAction::CREATED => 'Transaction Created',
            AuditAction::UPDATED => 'Transaction Updated',
            AuditAction::DELETED => 'Transaction Deleted',
            AuditAction::APPROVED => 'Transaction Approved',
            AuditAction::REJECTED => 'Transaction Rejected',
            AuditAction::CANCELLED => 'Transaction Cancelled',
            AuditAction::REVERSED => 'Transaction Reversed',
            AuditAction::FAILED => 'Transaction Failed',
            AuditAction::SCHEDULED => 'Transaction Scheduled',
            AuditAction::EXECUTED => 'Scheduled Transaction Executed',
            default => 'Unknown Action'
        };
    }

    public function getChanges(): array
    {
        if (!$this->old_data || !$this->new_data) {
            return [];
        }

        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($this->old_data), array_keys($this->new_data)));

        foreach ($allKeys as $key) {
            $oldValue = $this->old_data[$key] ?? null;
            $newValue = $this->new_data[$key] ?? null;

            if ($oldValue != $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }

        return $changes;
    }

    public function getSummary(): string
    {
        $changes = $this->getChanges();

        if (empty($changes)) {
            return $this->getActionLabel();
        }

        $changeDescriptions = [];
        foreach ($changes as $field => $values) {
            $changeDescriptions[] = "{$field}: {$values['old']} → {$values['new']}";
        }

        return $this->getActionLabel() . ' - ' . implode(', ', $changeDescriptions);
    }

    public function isSensitiveField(string $field): bool
    {
        $sensitiveFields = ['balance', 'amount', 'fee', 'account_number', 'user_id'];
        return in_array($field, $sensitiveFields);
    }

    public function getMaskedOldData(): array
    {
        return $this->maskSensitiveData($this->old_data);
    }

    public function getMaskedNewData(): array
    {
        return $this->maskSensitiveData($this->new_data);
    }

    private function maskSensitiveData(array $data): array
    {
        $masked = $data;

        foreach ($masked as $key => $value) {
            if ($this->isSensitiveField($key) && is_string($value)) {
                $masked[$key] = str_repeat('*', max(4, strlen($value) - 4)) . substr($value, -4);
            }
        }

        return $masked;
    }

    /**
     * Create audit log entry
     */
    public static function log(
        Transaction $transaction,
        AuditAction $action,
        ?User $user = null,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $ipAddress = null,
        ?array $additionalInfo = null
    ): self
    {
        return self::create([
            'transaction_id' => $transaction->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'ip_address' => $ipAddress ?? request()->ip() ?? 'system',
            'old_data' => $oldData,
            'new_data' => $newData,
            'additional_info' => $additionalInfo
        ]);
    }

    /**
     * Get audit trail for transaction
     */
    public static function getAuditTrail(Transaction $transaction): array
    {
        return self::where('transaction_id', $transaction->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($log) {
                return [
                    'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                    'user' => $log->user ? $log->user->full_name : 'System',
                    'action' => $log->getActionLabel(),
                    'ip_address' => $log->ip_address,
                    'changes' => $log->getChanges(),
                    'summary' => $log->getSummary()
                ];
            })
            ->toArray();
    }
}
