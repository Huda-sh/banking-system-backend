<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountFeature extends Model
{
    protected $fillable = [
        'class_name',
        'label',
    ];
}
