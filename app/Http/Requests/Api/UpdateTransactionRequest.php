<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = Transaction::find($this->route('transaction'));
        return Auth::check() && $this->canUpdateTransaction($transaction);
    }

    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                Rule::in(['pending', 'pending_approval', 'approved', 'completed', 'failed', 'cancelled', 'reversed'])
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
            'metadata.add' => ['nullable', 'array'],
            'metadata.remove' => ['nullable', 'array']
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Invalid transaction status',
            'notes.max' => 'Notes cannot exceed 500 characters',
            'reason.max' => 'Reason cannot exceed 500 characters'
        ];
    }

    private function canUpdateTransaction(?Transaction $transaction): bool
    {
        if (!$transaction) {
            return false;
        }

        $user = Auth::user();

        // Admin can update any transaction
        if ($user->hasRole('admin')) {
            return true;
        }

        // Transaction initiator can update certain fields
        if ($transaction->initiated_by === $user->id) {
            return $this->isAllowedInitiatorUpdate();
        }

        // Approvers can update approval-related fields
        return $this->isAllowedApprovalUpdate($transaction, $user);
    }

    private function isAllowedInitiatorUpdate(): bool
    {
        $allowedFields = ['notes', 'reason'];
        return count(array_intersect($allowedFields, array_keys($this->all()))) > 0;
    }

    private function isAllowedApprovalUpdate(Transaction $transaction, $user): bool
    {
        // Check if user has pending approval for this transaction
        return $transaction->approvals()
            ->where('approver_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }
}
