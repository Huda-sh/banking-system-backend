<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\TransactionApproval;
use App\Enums\ApprovalStatus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyApprovalAuthority
{
    public function handle(Request $request, Closure $next, ...$levels): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        // If no specific levels are provided, check if user has any approval permissions
        if (empty($levels)) {
            return $this->handleGeneralApprovalCheck($request, $next, $user);
        }

        return $this->handleSpecificLevelCheck($request, $next, $user, $levels);
    }

    private function handleGeneralApprovalCheck(Request $request, Closure $next, $user): Response
    {
        $transactionId = $request->route('transaction');
        $transaction = Transaction::findOrFail($transactionId);

        // Check if user has any pending approvals for this transaction
        $hasApproval = TransactionApproval::where('transaction_id', $transaction->id)
            ->where('approver_id', $user->id)
            ->where('status', ApprovalStatus::PENDING)
            ->exists();

        if (!$hasApproval && !$user->hasRole('admin')) {
            Log::warning('Unauthorized approval attempt', [
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'You do not have approval authority for this transaction'
            ], 403);
        }

        return $next($request);
    }

    private function handleSpecificLevelCheck(Request $request, Closure $next, $user, array $levels): Response
    {
        $transactionId = $request->route('transaction');
        $transaction = Transaction::findOrFail($transactionId);

        // Check if user has the required role for any of the specified levels
        $hasRequiredRole = false;

        foreach ($levels as $level) {
            if ($user->hasRole($this->getRequiredRolesForLevel($level))) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (!$hasRequiredRole) {
            Log::warning('Unauthorized approval level attempt', [
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'required_levels' => $levels,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'You do not have the required approval level for this transaction'
            ], 403);
        }

        // For admin-level approvals, ensure transaction amount is within limits
        $maxAmount = $this->getMaxApprovalAmount($user);
        if ($transaction->amount > $maxAmount) {
            Log::warning('Approval amount exceeds user limit', [
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'transaction_amount' => $transaction->amount,
                'max_amount' => $maxAmount,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => sprintf('Transaction amount exceeds your approval limit of %.2f %s', $maxAmount, $transaction->currency)
            ], 403);
        }

        return $next($request);
    }

    private function getRequiredRolesForLevel(string $level): array
    {
        return match($level) {
            'teller' => ['teller', 'cashier'],
            'manager' => ['manager', 'branch_manager'],
            'admin' => ['admin', 'system_administrator'],
            'risk_manager' => ['risk_manager', 'compliance_officer'],
            'senior_manager' => ['senior_manager', 'director'],
            'executive' => ['executive', 'ceo', 'cfo'],
            default => []
        };
    }

    private function getMaxApprovalAmount($user): float
    {
        $maxAmounts = [
            'teller' => 10000.00,
            'manager' => 50000.00,
            'admin' => 100000.00,
            'risk_manager' => 250000.00,
            'senior_manager' => 500000.00,
            'executive' => 1000000.00
        ];

        $userRoles = $user->roles->pluck('name')->toArray();

        $maxAmount = 0.0;
        foreach ($userRoles as $role) {
            foreach ($maxAmounts as $level => $amount) {
                if (in_array($role, $this->getRequiredRolesForLevel($level)) && $amount > $maxAmount) {
                    $maxAmount = $amount;
                }
            }
        }

        return $maxAmount;
    }
}
