<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScheduledTransactionRequest;
use App\Http\Requests\Api\UpdateScheduledTransactionRequest;
use App\Http\Resources\ScheduledTransactionResource;
use App\Http\Resources\ScheduledTransactionCollection;
use App\Services\SchedulerService;
use App\Models\ScheduledTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduledTransactionController extends Controller
{
    public function __construct(
        private SchedulerService $schedulerService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage-scheduled-transactions')->only(['store', 'update', 'destroy', 'execute']);
        $this->middleware('permission:view-scheduled-transactions')->only(['index', 'show', 'upcoming']);
    }

    /**
     * Display a listing of scheduled transactions.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $filters = request()->validate([
            'status' => 'nullable|in:active,inactive',
            'frequency' => 'nullable|in:daily,weekly,monthly,yearly',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $scheduledTransactions = $this->schedulerService->getScheduledTransactionsForUser(
                $user,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => new ScheduledTransactionCollection($scheduledTransactions),
                'meta' => [
                    'current_page' => $scheduledTransactions->currentPage(),
                    'last_page' => $scheduledTransactions->lastPage(),
                    'per_page' => $scheduledTransactions->perPage(),
                    'total' => $scheduledTransactions->total()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@index error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve scheduled transactions'
            ], 500);
        }
    }

    /**
     * Store a newly created scheduled transaction.
     */
    public function store(StoreScheduledTransactionRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        try {
            $scheduled = $this->schedulerService->createScheduledTransaction($data, $user);

            return response()->json([
                'success' => true,
                'data' => new ScheduledTransactionResource($scheduled),
                'message' => 'Scheduled transaction created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@store error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create scheduled transaction'
            ], 422);
        }
    }

    /**
     * Display the specified scheduled transaction.
     */
    public function show(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this scheduled transaction'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new ScheduledTransactionResource($scheduledTransaction)
        ]);
    }

    /**
     * Update the specified scheduled transaction.
     */
    public function update(UpdateScheduledTransactionRequest $request, ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this scheduled transaction'
            ], 403);
        }

        $data = $request->validated();

        try {
            $updated = $this->schedulerService->updateScheduledTransaction($scheduledTransaction, $data);

            return response()->json([
                'success' => true,
                'data' => new ScheduledTransactionResource($updated),
                'message' => 'Scheduled transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@update error', [
                'user_id' => $user->id,
                'scheduled_id' => $scheduledTransaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update scheduled transaction'
            ], 422);
        }
    }

    /**
     * Execute a scheduled transaction immediately.
     */
    public function execute(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to execute this scheduled transaction'
            ], 403);
        }

        try {
            $result = $this->schedulerService->executeSingleScheduledTransaction($scheduledTransaction);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Scheduled transaction executed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@execute error', [
                'user_id' => $user->id,
                'scheduled_id' => $scheduledTransaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to execute scheduled transaction'
            ], 500);
        }
    }

    /**
     * Cancel a scheduled transaction.
     */
    public function cancel(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to cancel this scheduled transaction'
            ], 403);
        }

        try {
            $result = $this->schedulerService->cancelScheduledTransaction($scheduledTransaction, $user, 'Cancelled via API');

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Scheduled transaction cancelled successfully' : 'Failed to cancel scheduled transaction'
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@cancel error', [
                'user_id' => $user->id,
                'scheduled_id' => $scheduledTransaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel scheduled transaction'
            ], 500);
        }
    }

    /**
     * Get upcoming scheduled transactions.
     */
    public function upcoming(): JsonResponse
    {
        $user = Auth::user();

        try {
            $data = request()->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'per_page' => 'nullable|integer|min:1|max:50'
            ]);

            $startDate = $data['start_date'] ? Carbon::parse($data['start_date']) : now();
            $endDate = $data['end_date'] ? Carbon::parse($data['end_date']) : now()->addMonths(1);
            $perPage = $data['per_page'] ?? 20;

            $upcoming = $this->schedulerService->getUserUpcomingSchedules($user, $startDate, $endDate, $perPage);

            return response()->json([
                'success' => true,
                'data' => new ScheduledTransactionCollection($upcoming),
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => $upcoming->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@upcoming error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve upcoming scheduled transactions'
            ], 500);
        }
    }

    /**
     * Get execution history for a scheduled transaction.
     */
    public function history(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this scheduled transaction history'
            ], 403);
        }

        try {
            $history = $this->schedulerService->getScheduleHistory($scheduledTransaction);

            return response()->json([
                'success' => true,
                'data' => $history
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@history error', [
                'user_id' => $user->id,
                'scheduled_id' => $scheduledTransaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve scheduled transaction history'
            ], 500);
        }
    }

    /**
     * Reactivate a cancelled scheduled transaction.
     */
    public function reactivate(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $user = Auth::user();

        if (!$this->canAccessScheduledTransaction($user, $scheduledTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to reactivate this scheduled transaction'
            ], 403);
        }

        try {
            $reactivated = $this->schedulerService->reactivateSchedule($scheduledTransaction);

            return response()->json([
                'success' => true,
                'data' => new ScheduledTransactionResource($reactivated),
                'message' => 'Scheduled transaction reactivated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('ScheduledTransactionController@reactivate error', [
                'user_id' => $user->id,
                'scheduled_id' => $scheduledTransaction->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reactivate scheduled transaction'
            ], 500);
        }
    }

    /**
     * Check if user can access a scheduled transaction.
     */
    private function canAccessScheduledTransaction(User $user, ScheduledTransaction $scheduledTransaction): bool
    {
        // Admin can access all scheduled transactions
        if ($user->hasRole('admin')) {
            return true;
        }

        // Check if user initiated the original transaction
        return $scheduledTransaction->transaction && $scheduledTransaction->transaction->initiated_by === $user->id;
    }
}
