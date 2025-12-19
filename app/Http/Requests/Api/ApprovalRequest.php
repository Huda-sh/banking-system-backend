<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Transaction;
use App\Models\TransactionApproval;
use Illuminate\Support\Facades\Auth;

class ApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = Transaction::find($this->route('transaction'));
        return Auth::check() && $this->canApproveTransaction($transaction);
    }

    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'max:500'],
            'level' => ['nullable', 'string', 'in:teller,manager,admin,risk_manager,compliance_officer,senior_manager,executive']
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Approval notes are required',
            'notes.max' => 'Notes cannot exceed 500 characters',
            'level.in' => 'Invalid approval level'
        ];
    }

    private function canApproveTransaction(?Transaction $transaction): bool
    {
        if (!$transaction) {
            return false;
        }

        $user = Auth::user();

        // Admin can approve any transaction
        if ($user->hasRole('admin')) {
            return true;
        }

        // Check if user has pending approval for this transaction
        $pendingApproval = TransactionApproval::where('transaction_id', $transaction->id)
            ->where('approver_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$pendingApproval) {
            return false;
        }

        // Check if approval is still valid (not expired)
        return !$pendingApproval->due_at || $pendingApproval->due_at->isFuture();
    }
}
