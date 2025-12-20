<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'method'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
