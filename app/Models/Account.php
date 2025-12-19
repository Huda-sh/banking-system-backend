<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    protected $fillable = [
        'account_type_id',
        'parent_account_id',
        'account_number',
        'balance',
        'currency',
    ];

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(AccountFeature::class, 'account_feature_assignments', 'account_id', 'account_feature_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_users', 'account_id', 'user_id')->withPivot('is_owner');
    }

    public function childrenAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_account_id');
    }

    public function states(): HasMany
    {
        return $this->hasMany(AccountState::class);
    }

    public function currentState(): HasOne
    {
        return $this->hasOne(AccountState::class)->latestOfMany();
    }
}
