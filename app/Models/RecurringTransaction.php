<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class RecurringTransaction extends Model
{
    protected $table = 'recurring_transactions';

    protected $fillable = [
        'account_id',
        'target_account_id',
        'type',
        'amount',
        'frequency',
        'start_date',
        'end_date',
        'active',
        'created_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'active' => 'boolean'
    ];

    // العلاقات
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // السمات المخصصة
    public function isActive(): bool
    {
        if (!$this->active) return false;
        if ($this->end_date && Carbon::today()->gt($this->end_date)) {
            $this->update(['active' => false]);
            return false;
        }
        return true;
    }

    public function getNextExecutionDate(): Carbon
    {
        $lastDate = $this->last_executed_at
            ? Carbon::parse($this->last_executed_at)
            : Carbon::parse($this->start_date);

        return match($this->frequency) {
            'daily' => $lastDate->addDay(),
            'weekly' => $lastDate->addWeek(),
            'monthly' => $lastDate->addMonth(),
            default => $lastDate->addDay(),
        };
    }

    // نطاق للبحث والفلترة
    public function scopeFilter($query, array $filters)
    {
        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->whereHas('account', function($q) use ($filters) {
                    $q->where('account_number', 'like', '%' . $filters['search'] . '%');
                })
                    ->orWhereHas('targetAccount', function($q) use ($filters) {
                        $q->where('account_number', 'like', '%' . $filters['search'] . '%');
                    })
                    ->orWhere('description', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('active', $filters['is_active']);
        }

        if (!empty($filters['frequency'])) {
            $query->where('frequency', $filters['frequency']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('start_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('end_date', '<=', $filters['end_date']);
        }

        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        return $query;
    }


}
