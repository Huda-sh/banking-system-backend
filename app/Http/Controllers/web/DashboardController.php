<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use App\Services\ApprovalWorkflowService;
use App\Services\SchedulerService;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private ApprovalWorkflowService $approvalWorkflowService,
        private SchedulerService $schedulerService
    ) {
        $this->middleware('auth');
    }

    /**
     * Display the main dashboard.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Get account balances
        $accounts = $user->accounts()->with('currentState')->get();
        $totalBalance = $accounts->sum('balance');

        // Get recent transactions
        $recentTransactions = $this->transactionService->getTransactionRepository()
            ->getByUser($user->id, [
                'date_range' => [
                    'start' => now()->subDays(7),
                    'end' => now()
                ]
            ])
            ->with(['fromAccount', 'toAccount'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get pending approvals
        $pendingApprovals = $this->approvalWorkflowService->getUserPendingApprovals($user, 5);

        // Get upcoming scheduled transactions
        $upcomingSchedules = $this->schedulerService->getUserUpcomingSchedules(
            $user,
            now(),
            now()->addDays(7),
            5
        );

        // Get transaction summary
        $summary = $this->transactionService->getUserTransactionSummary(
            $user,
            now()->subMonths(1),
            now()
        );

        // Get high-risk transactions
        $highRiskTransactions = $this->transactionService->getTransactionRepository()
            ->getHighRiskTransactions([
                'date_range' => [
                    'start' => now()->subDays(30),
                    'end' => now()
                ]
            ])
            ->with(['fromAccount', 'toAccount'])
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard/Index', [
            'accounts' => $accounts,
            'total_balance' => $totalBalance,
            'recent_transactions' => $recentTransactions,
            'pending_approvals' => $pendingApprovals,
            'upcoming_schedules' => $upcomingSchedules,
            'summary' => $summary,
            'high_risk_transactions' => $highRiskTransactions,
            'canViewReports' => $user->can('viewAny', \App\Models\Report::class),
            'canManageApprovals' => $user->can('viewAny', \App\Models\TransactionApproval::class),
            'canViewAuditLogs' => $user->can('viewAuditLogs', \App\Models\Transaction::class)
        ]);
    }

    /**
     * Display the analytics dashboard.
     */
    public function analytics(Request $request)
    {
        $user = auth()->user();
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->subMonths(6);
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : now();

        // Get transaction trends
        $transactionTrends = $this->transactionService->getTransactionRepository()
            ->getMonthlyTrends($startDate, $endDate);

        // Get approval statistics
        $approvalStats = $this->approvalWorkflowService->getStatistics();

        // Get scheduled transaction statistics
        $scheduleStats = $this->schedulerService->getStatistics();

        // Get account balance trends
        $accountTrends = $this->getAccountBalanceTrends($user, $startDate, $endDate);

        // Get risk analysis
        $riskAnalysis = $this->getRiskAnalysis($user, $startDate, $endDate);

        return Inertia::render('Dashboard/Analytics', [
            'transaction_trends' => $transactionTrends,
            'approval_stats' => $approvalStats,
            'schedule_stats' => $scheduleStats,
            'account_trends' => $accountTrends,
            'risk_analysis' => $riskAnalysis,
            'date_range' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'canExportData' => $user->can('export', \App\Models\Report::class)
        ]);
    }

    /**
     * Display the approvals dashboard.
     */
    public function approvals(Request $request)
    {
        $user = auth()->user();

        // Get user's pending approvals
        $myPendingApprovals = $this->approvalWorkflowService->getUserPendingApprovals($user, 20);

        // Get all pending approvals (for managers/admins)
        $allPendingApprovals = [];
        if ($user->can('manage', \App\Models\TransactionApproval::class)) {
            $allPendingApprovals = $this->approvalWorkflowService->getTransactionRepository()
                ->getPendingApprovals()
                ->with(['fromAccount', 'toAccount', 'initiatedBy'])
                ->orderBy('created_at', 'asc')
                ->paginate(25);
        }

        // Get approval statistics
        $approvalStats = $this->approvalWorkflowService->getStatistics();

        // Get overdue approvals
        $overdueApprovals = $this->approvalWorkflowService->getOverdueApprovals($user);

        return Inertia::render('Dashboard/Approvals', [
            'my_pending_approvals' => $myPendingApprovals,
            'all_pending_approvals' => $allPendingApprovals,
            'approval_stats' => $approvalStats,
            'overdue_approvals' => $overdueApprovals,
            'canProcessOverdue' => $user->can('processOverdue', \App\Models\TransactionApproval::class),
            'canEscalate' => $user->can('escalate', \App\Models\TransactionApproval::class)
        ]);
    }

    /**
     * Display the schedules dashboard.
     */
    public function schedules(Request $request)
    {
        $user = auth()->user();

        // Get user's active scheduled transactions
        $activeSchedules = $this->schedulerService->getUserUpcomingSchedules(
            $user,
            now(),
            now()->addMonths(1),
            25
        );

        // Get schedule statistics
        $scheduleStats = $this->schedulerService->getStatistics();

        // Get failed schedules
        $failedSchedules = $this->schedulerService->getFailedSchedules($user);

        return Inertia::render('Dashboard/Schedules', [
            'active_schedules' => $activeSchedules,
            'schedule_stats' => $scheduleStats,
            'failed_schedules' => $failedSchedules,
            'canCreateSchedule' => $user->can('create', \App\Models\ScheduledTransaction::class),
            'canManageSchedules' => $user->can('manage', \App\Models\ScheduledTransaction::class)
        ]);
    }

    /**
     * Get account balance trends.
     */
    private function getAccountBalanceTrends(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $trends = [];

        $accounts = $user->accounts()->active()->get();

        foreach ($accounts as $account) {
            $trendData = [];
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $balance = $account->transactions()
                    ->where('created_at', '<=', $currentDate)
                    ->where('status', 'completed')
                    ->sum('amount');

                $trendData[] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'balance' => $balance
                ];

                $currentDate->addDay();
            }

            $trends[] = [
                'account_id' => $account->id,
                'account_number' => $account->account_number,
                'account_type' => $account->accountType?->name,
                'data' => $trendData
            ];
        }

        return $trends;
    }

    /**
     * Get risk analysis data.
     */
    private function getRiskAnalysis(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $highRiskTransactions = $this->transactionService->getTransactionRepository()
            ->getHighRiskTransactions([
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ])
            ->count();

        $totalTransactions = $this->transactionService->getTransactionRepository()
            ->getByUser($user->id, [
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ])
            ->count();

        $riskScore = $totalTransactions > 0 ? ($highRiskTransactions / $totalTransactions) * 100 : 0;

        return [
            'high_risk_count' => $highRiskTransactions,
            'total_count' => $totalTransactions,
            'risk_score' => round($riskScore, 2),
            'risk_level' => $this->getRiskLevel($riskScore),
            'recommendations' => $this->getRiskRecommendations($riskScore)
        ];
    }

    /**
     * Get risk level based on score.
     */
    private function getRiskLevel(float $score): string
    {
        return match(true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'minimal'
        };
    }

    /**
     * Get risk recommendations.
     */
    private function getRiskRecommendations(float $score): array
    {
        if ($score >= 80) {
            return [
                'Enable two-factor authentication',
                'Reduce transaction limits',
                'Require manual approval for all transactions',
                'Contact security team immediately'
            ];
        }

        if ($score >= 60) {
            return [
                'Review recent transaction history',
                'Enable additional verification for large transactions',
                'Monitor account activity closely'
            ];
        }

        if ($score >= 40) {
            return [
                'Enable transaction notifications',
                'Review account security settings',
                'Monitor for unusual activity'
            ];
        }

        return [
            'Continue monitoring account activity',
            'Keep security settings updated',
            'Review transaction history periodically'
        ];
    }
}
