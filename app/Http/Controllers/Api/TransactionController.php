<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTransactionRequest;
use App\Http\Requests\Api\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionCollection;
use App\Services\TransactionService;
use App\Services\FeeCalculationService;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\TransactionException;
use Carbon\Carbon;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private FeeCalculationService $feeCalculationService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:process-transactions')->only(['store', 'transfer', 'withdraw', 'deposit']);
        $this->middleware('permission:view-transactions')->only(['index', 'show', 'history']);
        $this->middleware('permission:reverse-transactions')->only(['reverse']);
        $this->middleware('permission:cancel-transactions')->only(['cancel']);
    }

    /**
     * Display a listing of transactions.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $filters = request()->validate([
            'account_id' => 'nullable|exists:accounts,id',
            'type' => 'nullable|in:deposit,withdrawal,transfer,scheduled',
            'status' => 'nullable|in:pending,pending_approval,approved,completed,failed,cancelled,reversed',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $transactions = $this->transactionService->getTransactionsForUser(
                $user,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => new TransactionCollection($transactions),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('TransactionController@index error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transactions'
            ], 500);
        }
    }

    /**
     * Store a newly created transaction.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        try {
            $transaction = $this->transactionService->process($data, $user);

            return response()->json([
                'success' => true,
                'data' => new TransactionResource($transaction),
                'message' => 'Transaction processed successfully'
            ], 201);

        } catch (ApprovalRequiredException $e) {
            $transaction = $e->getTransaction();

            return response()->json([
                'success' => true,
                'data' => new TransactionResource($transaction),
                'requires_approval' => true,
                'message' => 'Transaction created and pending approval',
                'approval_details' => $transaction->getApprovalWorkflowDetails()
            ], 202);

        } catch (TransactionException $e) {
            Log::warning('Transaction failed validation', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ], 422);

        } catch (\Exception $e) {
            Log::error('TransactionController@store error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process transaction'
            ], 500);
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessTransaction($user, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this transaction'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction)
        ]);
    }

    /**
     * Process a transfer transaction.
     */
    public function transfer(StoreTransactionRequest $request): JsonResponse
    {
        $request->merge(['type' => 'transfer']);
        return $this->store($request);
    }

    /**
     * Process a withdrawal transaction.
     */
    public function withdraw(StoreTransactionRequest $request): JsonResponse
    {
        $request->merge(['type' => 'withdrawal']);
        return $this->store($request);
    }

    /**
     * Process a deposit transaction.
     */
    public function deposit(StoreTransactionRequest $request): JsonResponse
    {
        $request->merge(['type' => 'deposit']);
        return $this->store($request);
    }

    /**
     * Reverse a transaction.
     */
    public function reverse(Transaction $transaction, UpdateTransactionRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessTransaction($user, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to reverse this transaction'
            ], 403);
        }

        $reason = $request->input('reason', 'No reason provided');

        try {
            $reversedTransaction = $this->transactionService->reverse($transaction, $user, $reason);

            return response()->json([
                'success' => true,
                'data' => new TransactionResource($reversedTransaction),
                'message' => 'Transaction reversed successfully'
            ]);

        } catch (TransactionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('TransactionController@reverse error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reverse transaction'
            ], 500);
        }
    }

    /**
     * Cancel a transaction.
     */
    public function cancel(Transaction $transaction, UpdateTransactionRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessTransaction($user, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to cancel this transaction'
            ], 403);
        }

        $reason = $request->input('reason', 'No reason provided');

        try {
            $result = $this->transactionService->cancel($transaction, $user, $reason);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Transaction cancelled successfully' : 'Failed to cancel transaction'
            ]);

        } catch (TransactionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('TransactionController@cancel error', [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel transaction'
            ], 500);
        }
    }

    /**
     * Get transaction history for a specific account.
     */
    public function accountHistory(string $accountId): JsonResponse
    {
        $user = Auth::user();

        try {
            $filters = request()->validate([
                'type' => 'nullable|in:deposit,withdrawal,transfer,scheduled',
                'status' => 'nullable|in:pending,pending_approval,approved,completed,failed,cancelled,reversed',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'per_page' => 'nullable|integer|min:1|max:100'
            ]);

            $transactions = $this->transactionService->getAccountHistory(
                $user,
                $accountId,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => new TransactionCollection($transactions),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('TransactionController@accountHistory error', [
                'user_id' => $user->id,
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve account history'
            ], 500);
        }
    }

    /**
     * Get transaction summary for a user.
     */
    public function summary(): JsonResponse
    {
        $user = Auth::user();

        try {
            $data = request()->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date'
            ]);

            $startDate = $data['start_date'] ? Carbon::parse($data['start_date']) : null;
            $endDate = $data['end_date'] ? Carbon::parse($data['end_date']) : null;

            $summary = $this->transactionService->getUserTransactionSummary($user, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('TransactionController@summary error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transaction summary'
            ], 500);
        }
    }

    /**
     * Calculate fee for a transaction.
     */
    public function calculateFee(): JsonResponse
    {
        try {
            $data = request()->validate([
                'type' => 'required|in:deposit,withdrawal,transfer,international_transfer',
                'amount' => 'required|numeric|min:0.01',
                'from_account_id' => 'nullable|exists:accounts,id',
                'to_account_id' => 'required|exists:accounts,id',
                'currency' => 'nullable|string|size:3'
            ]);

            $fee = $this->feeCalculationService->calculateFee(
                $data['type'],
                $data['amount'],
                $data['from_account_id'] ?? null,
                $data['to_account_id'],
                ['currency' => $data['currency'] ?? 'USD']
            );

            $breakdown = $this->feeCalculationService->getFeeBreakdown(
                $data['type'],
                $data['amount'],
                $data['from_account_id'] ?? null,
                $data['to_account_id'],
                ['currency' => $data['currency'] ?? 'USD']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'fee' => $fee,
                    'breakdown' => $breakdown
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('TransactionController@calculateFee error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate fee'
            ], 500);
        }
    }

    /**
     * Check if user can access a transaction.
     */
    private function canAccessTransaction(User $user, Transaction $transaction): bool
    {
        // Admin can access all transactions
        if ($user->hasRole('admin')) {
            return true;
        }

        // Check if user initiated the transaction
        if ($transaction->initiated_by === $user->id) {
            return true;
        }

        // Check if user has access to the accounts involved
        $accountIds = [];

        if ($transaction->from_account_id) {
            $accountIds[] = $transaction->from_account_id;
        }

        if ($transaction->to_account_id) {
            $accountIds[] = $transaction->to_account_id;
        }

        return !empty($accountIds) && $user->accounts()->whereIn('id', $accountIds)->exists();
    }
}
