<?php

namespace App\Http\Resources;

use App\Accounts\Composite\AccountLeaf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource instanceof AccountLeaf 
            ? $this->resource->account 
            : $this->resource;

        $currentState = $account->currentState;

        return [
            'id' => $account->id,
            'account_number' => $account->account_number,
            'balance' => (float) $account->balance,
            'currency' => $account->currency,
            'account_type' => [
                'id' => $account->accountType->id,
                'name' => $account->accountType->name,
                'description' => $account->accountType->description,
            ],
            'users' => $account->users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => "{$user->first_name} {$user->last_name}",
                    'email' => $user->email,
                    'is_owner' => (bool) $user->pivot->is_owner,
                ];
            }),
            'current_state' => $currentState ? [
                'state' => $currentState->state,
                'changed_by' => $currentState->changed_by,
                'changed_at' => $currentState->created_at->format('Y-m-d H:i:s'),
            ] : null,
            'features' => $account->features->map(function ($feature) {
                return [
                    'id' => $feature->id,
                    'label' => $feature->label,
                    'class_name' => $feature->class_name,
                ];
            }),
            'created_at' => $account->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $account->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}

