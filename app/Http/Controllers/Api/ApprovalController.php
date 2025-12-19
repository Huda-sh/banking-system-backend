<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApprovalResource;
use App\Http\Resources\ApprovalCollection;
use App\Services\ApprovalWorkflowService;
use App\Models\Transaction;
use App\Models\TransactionApproval;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ApprovalException;

class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalWorkflowService $approvalWorkflowService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:approve-transactions')->only(['approve', 'reject', 'escalate']);
        $this->middleware('permission:view-approvals')->only(['index', 'show', 'pending', 'summary']);
    }

    /**
     * Display a listing of pending approvals.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        try {
            $filters = request()->validate([
                'status' => 'nullable|in:pending,approved,rejected',
                'level' => 'nullable|in:teller,manager,admin,risk_manager,compliance_officer,senior_manager,executive',
                'transaction_type' => 'nullable|in:deposit,withdrawal,transfer,scheduled',
                'per_page' => 'nullable|integer|min:1|max:100'
            ]);

            $approvals = $this->approvalWorkflowService->getUserApprovals(
                $user,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => new ApprovalCollection($approvals),
                'meta' => [
                    'current_page' => $approvals->currentPage(),
                    'last_page' => $approvals->lastPage(),
                    'per_page' => $approvals->perPage(),
                    'total' => $approvals->total()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ApprovalController@index error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve approvals'
            ], 500);
        }
    }

    /**
     * Display the specified approval.
     */
    public function show(TransactionApproval $approval): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessApproval($user, $approval)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this approval'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new ApprovalResource($approval)
        ]);
    }

    /**
     * Get pending approvals for the authenticated user.
     */
    public function pending(): JsonResponse
    {
        $user = Auth::user();

        try {
            $pendingApprovals = $this->approvalWorkflowService->getUserPendingApprovals($user);

            return response()->json([
                'success' => true,
                'data' => new ApprovalCollection($pendingApprovals['approvals']),
                'meta' => [
                    'pending_count' => $pendingApprovals['pending_count'],
                    'overdue_count' => $pendingApprovals['overdue_count']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('ApprovalController@pending error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending approvals'
            ], 500);
        }
    }

    /**
     * Approve a transaction.
     */
    public function approve(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        try {
            $data = request()->validate([
                'notes' => 'nullable|string|max:500'
            ]);

            $result = $this->approvalWorkflowService->approveTransaction($transaction, $user, $data);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Transaction approved successfully' : 'Failed to approve transaction'
            ]);

        } catch (ApprovalException $e) {
            Log::warning('ApprovalController@approve failed', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('ApprovalController@approve error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve transaction'
            ], 500);
        }
    }

    /**
     * Reject a transaction.
     */
    public function reject(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        try {
            $data = request()->validate([
                'notes' => 'required|string|max:500'
            ]);

            $result = $this->approvalWorkflowService->rejectTransaction($transaction, $user, $data);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Transaction rejected successfully' : 'Failed to reject transaction'
            ]);

        } catch (ApprovalException $e) {
            Log::warning('ApprovalController@reject failed', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('ApprovalController@reject error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject transaction'
            ], 500);
        }
    }

    /**
     * Escalate an approval to a higher level.
     */
    public function escalate(TransactionApproval $approval): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessApproval($user, $approval)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to escalate this approval'
            ], 403);
        }

        try {
            $data = request()->validate([
                'notes' => 'required|string|max:500'
            ]);

            $escalated = $this->approvalWorkflowService->escalateApproval($approval, $user, $data);

            return response()->json([
                'success' => true,
                'data' => new ApprovalResource($escalated),
                'message' => 'Approval escalated successfully'
            ]);

        } catch (ApprovalException $e) {
            Log::warning('ApprovalController@escalate failed', [
                'user_id' => $user->id,
                'approval_id' => $approval->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('ApprovalController@escalate error', [
                'user_id' => $user->id,
                'approval_id' => $approval->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate approval'
            ], 500);
        }
    }

    /**
     * Get approval workflow summary for a transaction.
     */
    public function summary(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessTransaction($user, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this transaction'
            ], 403);
        }

        try {
            $summary = $this->approvalWorkflowService->getWorkflowSummary($transaction);

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('ApprovalController@summary error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve approval summary'
            ], 500);
        }
    }

    /**
     * Cancel an approval workflow.
     */
    public function cancel(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessTransaction($user, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to cancel this approval workflow'
            ], 403);
        }

        try {
            $data = request()->validate([
                'reason' => 'required|string|max:500'
            ]);

            $result = $this->approvalWorkflowService->cancelWorkflow($transaction, $user, $data['reason']);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Approval workflow cancelled successfully' : 'Failed to cancel approval workflow'
            ]);

        } catch (ApprovalException $e) {
            Log::warning('ApprovalController@cancel failed', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('ApprovalController@cancel error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel approval workflow'
            ], 500);
        }
    }

    /**
     * Check if user can access an approval.
     */
    private function canAccessApproval(User $user, TransactionApproval $approval): bool
    {
        // Admin can access all approvals
        if ($user->hasRole('admin')) {
            return true;
        }

        // User can access their own pending approvals
        if ($approval->approver_id === $user->id && $approval->status === 'pending') {
            return true;
        }

        // Managers can access approvals for their team members
        if ($user->hasRole(['manager', 'senior_manager', 'executive'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can access a transaction for approval purposes.
     */
    private function canAccessTransaction(User $user, Transaction $transaction): bool
    {
        // Admin can access all transactions
        if ($user->hasRole('admin')) {
            return true;
        }

        // User can access transactions they initiated
        if ($transaction->initiated_by === $user->id) {
            return true;
        }

        // Check if user has any pending approvals for this transaction
        return $transaction->approvals()
            ->where('approver_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }
}
