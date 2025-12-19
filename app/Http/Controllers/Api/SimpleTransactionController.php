<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SimpleTransactionController extends Controller
{
    /**
     * Display a listing of transactions with filtering capabilities.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d|after_or_equal:from_date',
            'type' => [
                'nullable',
                Rule::in([
                    'deposit', 'withdrawal', 'transfer', 'scheduled',
                    'loan_payment', 'interest_payment', 'fee_charge'
                ])
            ],
            'status' => [
                'nullable',
                Rule::in([
                    'pending', 'pending_approval', 'approved',
                    'completed', 'failed', 'cancelled', 'reversed'
                ])
            ],
            'account_id' => 'nullable|exists:accounts,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $filters = $validator->validated();
        $user = Auth::user();

        // Start with a query builder for transactions
        $query = Transaction::query();

        // Apply date range filter
        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        // Apply type filter
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply account filter
        if (isset($filters['account_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('from_account_id', $filters['account_id'])
                    ->orWhere('to_account_id', $filters['account_id']);
            });
        }

        // Get paginated results
        $perPage = $filters['per_page'] ?? 15;
        $transactions = $query->with(['fromAccount:id,account_number', 'toAccount:id,account_number'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Return response with resource
        return response()->json([
            'success' => true,
            'data' => TransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'filters' => [
                    'from_date' => $filters['from_date'] ?? null,
                    'to_date' => $filters['to_date'] ?? null,
                    'type' => $filters['type'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'account_id' => $filters['account_id'] ?? null
                ]
            ]
        ]);
    }
}
