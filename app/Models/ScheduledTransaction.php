<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Exceptions\ScheduledTransactionException;

class ScheduledTransaction extends Model
{
    protected $table = 'scheduled_transactions';

    protected $fillable = [
        'account_id',
        'target_account_id',
        'type',
        'amount',
        'scheduled_at',
        'status', 'created_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'active' => 'boolean'
    ];

    // العلاقات
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function targetAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('scheduled_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('scheduled_at', '<=', $filters['end_date']);
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
