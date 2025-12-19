<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Models\Account;
use App\Services\TransactionService;
use App\Services\SchedulerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private SchedulerService $schedulerService
    ) {
        $this->middleware('auth');
    }

    /**
     * Display a listing of transactions.
     */
    public function index(Request $request)
    {
        $filters = $this->getFilters($request);
        $perPage = $request->input('per_page', 25);

        $transactions = $this->transactionService->getTransactionRepository()
            ->getByUser(auth()->id(), $filters)
            ->with(['fromAccount', 'toAccount'])
            ->paginate($perPage)
            ->withQueryString();

        $summary = $this->transactionService->getUserTransactionSummary(auth()->user());

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'summary' => $summary,
            'filters' => $filters,
            'canCreate' => auth()->user()->can('create', Transaction::class)
        ]);
    }

    /**
     * Show the form for creating a new transaction.
     */
    public function create()
    {
        $this->authorize('create', Transaction::class);

        $userAccounts = auth()->user()->accounts()->active()->get();
        $accountTypes = config('banking.account_types');

        return Inertia::render('Transactions/Create', [
            'accounts' => $userAccounts,
            'account_types' => $accountTypes,
            'canSchedule' => auth()->user()->can('create', \App\Models\ScheduledTransaction::class)
        ]);
    }

    /**
     * Store a newly created transaction.
     */
    public function store(StoreTransactionRequest $request)
    {
        try {
            $transaction = $this->transactionService->process(
                $request->validated(),
                auth()->user()
            );

            return redirect()->route('transactions.show', $transaction)
                ->with('success', 'Transaction processed successfully');

        } catch (\Exception $e) {
            Log::error('Transaction failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return back()->withErrors([
                'error' => $e->getMessage()
            ])->withInput();
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Transaction $transaction)
    {
        $this->authorize('view', $transaction);

        $transaction->load([
            'fromAccount',
            'toAccount',
            'initiatedBy',
            'processedBy',
            'approvedBy',
            'approvals' => fn($q) => $q->with('approver'),
            'auditLogs' => fn($q) => $q->with('user')->latest(),
            'scheduledTransaction'
        ]);

        $relatedTransactions = $this->getRelatedTransactions($transaction);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction,
            'related_transactions' => $relatedTransactions,
            'canCancel' => auth()->user()->can('cancel', $transaction),
            'canReverse' => auth()->user()->can('reverse', $transaction),
            'canApprove' => auth()->user()->can('approve', $transaction)
        ]);
    }

    /**
     * Show the form for editing the specified transaction.
     */
    public function edit(Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        return Inertia::render('Transactions/Edit', [
            'transaction' => $transaction->load(['fromAccount', 'toAccount'])
        ]);
    }

    /**
     * Update the specified transaction.
     */
    public function update(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $request->validate([
            'description' => 'nullable|string|max:255',
            'metadata.notes' => 'nullable|string'
        ]);

        try {
            $transaction->update($request->only(['description', 'metadata']));

            return redirect()->route('transactions.show', $transaction)
                ->with('success', 'Transaction updated successfully');

        } catch (\Exception $e) {
            Log::error('Transaction update failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Cancel a pending transaction.
     */
    public function cancel(Transaction $transaction)
    {
        $this->authorize('cancel', $transaction);

        try {
            $this->transactionService->cancel(
                $transaction,
                auth()->user(),
                request('reason')
            );

            return redirect()->route('transactions.show', $transaction)
                ->with('success', 'Transaction cancelled successfully');

        } catch (\Exception $e) {
            Log::error('Transaction cancellation failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reverse a completed transaction.
     */
    public function reverse(Transaction $transaction)
    {
        $this->authorize('reverse', $transaction);

        try {
            $this->transactionService->reverse(
                $transaction,
                auth()->user(),
                request('reason')
            );

            return redirect()->route('transactions.show', $transaction)
                ->with('success', 'Transaction reversed successfully');

        } catch (\Exception $e) {
            Log::error('Transaction reversal failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Show transaction summary dashboard.
     */
    public function summary(Request $request)
    {
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->subMonths(3);
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : now();

        $summary = $this->transactionService->getUserTransactionSummary(
            auth()->user(),
            $startDate,
            $endDate
        );

        $trends = $this->transactionService->getTransactionRepository()
            ->getMonthlyTrends($startDate, $endDate);

        return Inertia::render('Transactions/Summary', [
            'summary' => $summary,
            'trends' => $trends,
            'date_range' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'canViewReports' => auth()->user()->can('viewAny', \App\Models\Report::class)
        ]);
    }

    /**
     * Get related transactions for a transaction.
     */
    private function getRelatedTransactions(Transaction $transaction): array
    {
        $related = [];

        // Get transactions with the same accounts
        if ($transaction->from_account_id || $transaction->to_account_id) {
            $accountIds = array_filter([$transaction->from_account_id, $transaction->to_account_id]);

            $related = $this->transactionService->getTransactionRepository()
                ->where(function ($query) use ($accountIds) {
                    foreach ($accountIds as $accountId) {
                        $query->orWhere('from_account_id', $accountId)
                            ->orWhere('to_account_id', $accountId);
                    }
                })
                ->where('id', '!=', $transaction->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->toArray();
        }

        return $related;
    }

    /**
     * Get filters from request.
     */
    private function getFilters(Request $request): array
    {
        $filters = [];

        if ($request->has('type')) {
            $filters['type'] = $request->input('type');
        }

        if ($request->has('status')) {
            $filters['status'] = $request->input('status');
        }

        if ($request->has('date_range')) {
            $dateRange = explode(' - ', $request->input('date_range'));
            if (count($dateRange) === 2) {
                $filters['date_range'] = [
                    'start' => Carbon::parse($dateRange[0]),
                    'end' => Carbon::parse($dateRange[1])
                ];
            }
        }

        if ($request->has('search')) {
            $filters['search'] = $request->input('search');
        }

        return $filters;
    }
}
