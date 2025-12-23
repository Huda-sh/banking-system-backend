<?php

namespace App\Http\Controllers\Api;


use App\Enums\FrequencyEnum;
use App\Http\Controllers\Controller;
use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class RecurringTransactionsController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only([
            'search', 'is_active', 'frequency', 'type', 'start_date', 'end_date', 'min_amount', 'max_amount'
        ]);

        $transactions = RecurringTransaction::where('created_by', Auth::id())
            ->filter($filters)
            ->with(['account', 'targetAccount', 'creator'])
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'filters' => $filters
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:transfer,deposit,withdrawal',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'target_account_id' => 'nullable|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'frequency' => 'required|in:' . implode(',', FrequencyEnum::values()),
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }
        $data = $validator->validated();
        $date['account_id']= $data['account_id'] ?? null;
        $data['target_account_id'] = $data['target_account_id'] ?? null;
        $data['created_by'] = Auth::id() ;
        $data['active'] = true;

        $transaction = RecurringTransaction::create($data);

        return response()->json([
            'message' => 'Recurring transaction created successfully',
            'data' => $transaction
        ], 201);
    }

    public function toggle($id)
    {
        $transaction = RecurringTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        $transaction->update(['active' => !$transaction->active]);

        return response()->json([
            'id' => $transaction->id,
            'is_active' => $transaction->active,
            'message' => $transaction->active ? 'Recurring payment has been resumed.' : 'Recurring payment has been paused.'
        ]);
    }

    public function update(Request $request, $id)
    {
        $transaction = RecurringTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'frequency' => 'required|in:' . implode(',', FrequencyEnum::values()),
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->validated();
        $transaction->update($data);

        return response()->json([
            'message' => 'Recurring transaction updated successfully',
            'data' => $transaction
        ]);
    }

    public function terminate($id)
    {
        $transaction = RecurringTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        $transaction->update([
            'active' => false,
            'end_date' => now()->format('Y-m-d')
        ]);

        return response()->json([
            'id' => $transaction->id,
            'status' => 'terminated',
            'endDate' => $transaction->end_date,
            'message' => 'Recurrence terminated. No further transactions will be generated.'
        ]);
    }

    public function history(Request $request, $id)
    {

        $transactions = \App\Models\Transaction::where('source_account_id', function ($query) use ($id) {
            $query->select('account_id')
                ->from('recurring_transactions')
                ->where('id', $id);
        })
            ->where('created_at', '>=', function ($query) use ($id) {
                $query->select('start_date')
                    ->from('recurring_transactions')
                    ->where('id', $id);
            })
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ]
        ]);
    }
}
