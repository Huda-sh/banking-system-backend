<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'requested_by',
        'approved_by',
        'status',
        'comment',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

     public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
     {
        return $this->belongsTo(User::class, 'requested_by');
    }

     public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
