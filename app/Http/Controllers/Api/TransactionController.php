<?php

namespace App\Http\Controllers\Api;

use App\Enums\Direction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\TransactionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTransactionRequest;
use App\Http\Requests\Api\UpdateTransactionRequest;
use App\Http\Resources\TransactionCollection;
use App\Http\Resources\TransactionResource;
use App\Models\Approval;
use App\Models\Transaction;
use App\Models\User;
use App\Observables\TransactionApprovalSubject;
use App\Observer\ReceiverEmailObserver;
use App\Observer\SenderEmailObserver;
use App\Services\Approval\LargeTransactionHandler;
use App\Services\Approval\MediumTransactionHandler;
use App\Services\Approval\SmallTransactionHandler;
use App\Services\Approval\VeryLargeTransactionHandler;
use App\Services\Transactions\FeeCalculationService;
use App\Services\Transactions\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private FeeCalculationService $feeCalculationService
    ) {}

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

    //get transaction by id
    public function getTransaction($id)
    {
        $transaction = Transaction::with([
            'initiatedBy',
            'processedBy',
            'sourceAccount',
            'targetAccount',
            'approvals',
            'approval.approvedBy',
        ])->findOrFail($id);

        // basicInfo
        $basicInfo = [
            'type' => $transaction->type,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'direction' => $transaction->direction ?? 'debit',
            'status' => $transaction->status,
            'date' => $transaction->created_at->toISOString(),
            'description' => $transaction->description,
        ];

        // accountDetails
        $accountDetails = [
            'sourceAccount' => $transaction->sourceAccount
                ? $transaction->sourceAccount->id . ' (' . ($transaction->sourceAccount->type ?? 'Account') . ')'
                : 'N/A',
            'targetAccount' => $transaction->targetAccount
                ? $transaction->targetAccount->id . ' (' . ($transaction->targetAccount->type ?? 'Account') . ')'
                : 'N/A',
         ];

        // approvalWorkflow
        $approval = $transaction->approval->last();
        $approvedByUser = $approval?->approvedBy;
        $approvedBy = $approvedByUser
            ? $approvedByUser->first_name . '_' . $approvedByUser->last_name
            : null;


        $workflowPath = $this->getWorkflowPath($transaction->amount);

        $approvalWorkflow = [
            'approvedBy' =>$approvedBy,
            'approvalDate' => $approval?->updated_at?->toISOString(),
            'workflowPath' => $workflowPath,
            'comments' => $approval?->comment ?? null,
        ];

        // auditTrail
        $auditTrail = [
            [
                'timestamp' => $transaction->created_at->toISOString(),
                'action' => 'CREATED',
                'user' => $transaction->initiatedBy?->first_name ?? 'System',
            ],
            [
                'timestamp' => $transaction->updated_at?->toISOString(),
                'action' => strtoupper($transaction->status),
                'user' => $approvedBy ?? 'System',
            ],
        ];

        return response()->json([
            'id' => $transaction->id,
            'referenceNumber' => $transaction->reference_number,
            'basicInfo' => $basicInfo,
            'accountDetails' => $accountDetails,
            'executionLogic' => [
                 'feeStrategyUsed' => 'None',
                'interestApplied' => 0.00,
            ],
            'approvalWorkflow' => $approvalWorkflow,
            'auditTrail' => $auditTrail,
        ]);
    }


    private function getWorkflowPath($amount)
    {
        if ($amount <= 1000) {
            return 'SmallTransactionHandler (Auto-approved)';
        } elseif ($amount <= 10000) {
            return 'Small → MediumTransactionHandler (Approved)';
        } elseif ($amount <= 50000) {
            return 'Small → Medium → LargeTransactionHandler (Approved)';
        } else {
            return 'Small → Medium → Large → VeryLargeTransactionHandler (Approved)';
        }
    }


    //update status of transaction
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
            'comments' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $transaction = Transaction::findOrFail($id);

         if ($transaction->status !== 'pending') {
            return response()->json(['error' => 'Only pending transactions can be updated.'], 400);
        }


        $allowed = $user->hasRole('Manager') || $user->hasRole('Admin');
        if (!$allowed) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $amount = $transaction->amount;
        $canApprove = false;

        if ($amount > 10000) {

            $canApprove = $user->hasRole('Admin');
        } else {
             $canApprove = $user->hasRole('Manager') || $user->hasRole('Admin');
        }

        if (!$canApprove) {
            return response()->json(['error' => 'You are not authorized to approve this transaction.'], 403);
        }


        $newStatus = $request->status;
        $dbStatus = $newStatus === 'approved' ? 1 : -1;

        \DB::transaction(function () use ($newStatus, $transaction, $user, $dbStatus, $request) {

            $transaction->update([
                'status' => $newStatus,
                'processed_by' => $user->id,
                'approved_at' => $newStatus === 'approved' ? now() : null,
            ]);


            $approval = $transaction->approvals()->first();
            if ($approval) {
                $approval->update([
                    'approved_by' => $user->id,
                    'status' => $newStatus,
                    'comment' => $request->comments,
                ]);



            } else {
                Approval::updateOrCreate([
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'requested_by' => $transaction->initiated_by,
                    'approved_by' => $user->id,
                    'status' => $newStatus,
                    'comment' => $request->comments,
                ]);
            }
            $subject = new TransactionApprovalSubject($transaction);
            $subject->attach(new \App\Observers\SenderEmailObserver());
            $subject->attach(new \App\Observers\ReceiverEmailObserver());
            $subject->notify();
        });

        return response()->json([
            'message' => 'Transaction status updated successfully.',
            'transaction' => $transaction->fresh(),
        ]);
    }
    /**
     * Store a newly created transaction.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:transfer,withdrawal,deposit',
            'sourceAccountId' => 'nullable|integer|exists:accounts,id',
            'targetAccountId' => 'nullable|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'description' => 'nullable|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        return DB::transaction(function () use ($data, $user) {
            $transaction = Transaction::create([
                'type' => $data['type'],
                'reference_number' => 'MTX-'.random_int(1000000, 9999999),
                'source_account_id' => $data['sourceAccountId'] ?? null,
                'target_account_id' => $data['targetAccountId'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? '',
                'initiated_by' => $user->id,
                'status' => TransactionStatus::PENDING,
            ]);

            $small = new SmallTransactionHandler();
            $medium = new MediumTransactionHandler();
            $large = new LargeTransactionHandler();
            $veryLarge = new VeryLargeTransactionHandler();

            $small->setNext($medium)
                ->setNext($large)
                ->setNext($veryLarge);

            $result = $small->handle($transaction, $user);

            if ($result['approved']) {
                $transaction->update([
                    'status' => 'approved',
                ]);

                return response()->json([
                    'message' => $result['message'],
                    'transaction' => $transaction->fresh(),
                ], 201);
            } else {
                return response()->json([
                    'message' => $result['message'],
                    'requires_approval' => true,
                    'allowed_roles' => $result['allowed_roles'] ?? [],
                    'transaction_id' => $transaction->id,
                ], 403);
            }
        });
    }    /**
     * Display the specified transaction.
     */
    public function show(Request $request): JsonResponse
    {
        //        $user = Auth::user();

        // Validate query parameters
        $validator = Validator::make($request->all(), [
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'direction' => 'nullable|in:' . implode(',', array_column(Direction::cases(), 'value')),
            'trans_type' => 'nullable|in:' . implode(',', array_column(TransactionType::cases(), 'value')),
            'status' => 'nullable|in:' . implode(',', array_column(TransactionStatus::cases(), 'value')),
            'account_id' => 'nullable|exists:accounts,id',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = Transaction::query();

        // Apply account filter first (most restrictive)
        if ($request->filled('account_id')) {
            $accountId = $request->account_id;
            $query->where(function ($q) use ($accountId) {
                $q->where('source_account_id', $accountId)
                    ->orWhere('target_account_id', $accountId);
            });
        }

        // Apply search filter
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('id', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%")
                    ->orWhereHas('sourceAccount', function ($q) use ($searchTerm) {
                        $q->where('account_number', 'like', "%{$searchTerm}%");
                    })
                    ->orWhereHas('targetAccount', function ($q) use ($searchTerm) {
                        $q->where('account_number', 'like', "%{$searchTerm}%");
                    });
            });
        }

        // Apply date filters
        if ($request->filled('from_date')) {
            $fromDate = Carbon::parse($request->from_date)->startOfDay();
            $query->where('created_at', '>=', $fromDate);
        }

        if ($request->filled('to_date')) {
            $toDate = Carbon::parse($request->to_date)->endOfDay();
            $query->where('created_at', '<=', $toDate);
        }

        // Apply type filter
        if ($request->filled('trans_type')) {
            $query->where('type', $request->trans_type);
        }

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Apply direction filter
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        ////         Only show user's transactions if not admin
        //        if (!$user->hasRole('admin')) {
        //            $query->where('initiated_by', $user->id);
        //        }

        // Get paginated results with relationships
        $perPage = $request->input('per_page', 15);
        $transactions = $query->with([
            'sourceAccount:id,account_number,currency,account_type_id',
            'sourceAccount.accountType:id,name',
            'targetAccount:id,account_number,currency,account_type_id',
            'targetAccount.accountType:id,name'
        ])
            ->latest()
            ->paginate($perPage);

        // Format response
        $formattedTransactions = $transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'reference_number' => $transaction->reference_number,
                'description' => $transaction->description,
                // 'type' => $transaction->type,
                'type_label' => TransactionType::tryFrom($transaction->type)?->getLabel() ?? ucfirst($transaction->type),
                // 'status' => $transaction->status,
                'status_label' => TransactionStatus::tryFrom($transaction->status)?->getLabel() ?? ucfirst($transaction->status),
                // 'direction' => $transaction->direction,
                'direction_label' => Direction::tryFrom($transaction->direction)?->getLabel() ?? ucfirst($transaction->direction),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'initiated_by'=>$transaction->full_name,
                'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                'approved_at' => $transaction->approved_at ? $transaction->approved_at->format('Y-m-d H:i:s') : null,
                'source_account' => $transaction->sourceAccount ? [
                    'id' => $transaction->sourceAccount->id,
                    'account_number' => $transaction->sourceAccount->account_number,
                    'currency' => $transaction->sourceAccount->currency,
                    'account_type'=>$transaction->sourceAccount->accountType->name ?? null,

                ] : null,
                'target_account' => $transaction->targetAccount ? [
                    'id' => $transaction->targetAccount->id,
                    'account_number' => $transaction->targetAccount->account_number,
                    'currency' => $transaction->targetAccount->currency,
                    'account_type'=>$transaction->targetAccount->accountType->name ?? null,

                ] : null
            ];
        });

        // Build filter summary
        $appliedFilters = array_filter([
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'direction' => $request->direction,
            'trans_type' => $request->trans_type,
            'status' => $request->status,
            'account_id' => $request->account_id,
            'search' => $request->search
        ], function ($value) {
            return $value !== null && $value !== '';
        });

        return response()->json([
            'success' => true,
            'data' => $formattedTransactions,
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'applied_filters' => $appliedFilters,
                'filter_count' => count($appliedFilters)
            ]
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

        if ($transaction->source_account_id) {
            $accountIds[] = $transaction->source_account_id;
        }

        if ($transaction->target_account_id) {
            $accountIds[] = $transaction->target_account_id;
        }

        return !empty($accountIds) && $user->accounts()->whereIn('id', $accountIds)->exists();
    }
}
