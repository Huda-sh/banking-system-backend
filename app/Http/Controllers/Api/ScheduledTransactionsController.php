<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduledTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ScheduledTransactionsController extends Controller
{

    public function index(Request $request)
    {
        $filters = $request->only([
            'search', 'status', 'type', 'start_date', 'end_date', 'min_amount', 'max_amount'
        ]);

        $transactions = ScheduledTransaction::where('created_by', Auth::id())
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

    public function show($id)
    {
        $transaction = ScheduledTransaction::with(['account', 'targetAccount', 'creator'])
            ->where('created_by', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($transaction);
    }

    public function store(Request $request)
    {
         $validator = Validator::make($request->all(), [
            'type' => 'required|in:withdrawal,deposit,transfer',
            'account_id' => 'integer|nullable',
            'target_account_id' => 'integer|nullable',
            'amount' => 'required|numeric|min:0.01',
            'scheduled_at' => 'required|date|after:now',
            'description' => 'nullable|string',
        ], [
            'target_account_id.required_if' => 'The target account is required for transfer transactions.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

          $data = $validator->validated();

        $data['created_by'] = Auth::id();
        $data['status'] = 'scheduled';
        $data['reference_number'] = 'MTX-'.random_int(1000000, 9999999);

         unset($data['sourceAccountId']);

        $transaction = ScheduledTransaction::create($data);

        return response()->json([
            'message' => 'Scheduled transaction created successfully',
            'data' => $transaction
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $transaction = ScheduledTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'scheduled_at' => 'nullable|date|after:now',
        ]);
        $data = $validator->validated();
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }
        $data = $validator->validated();
        $transaction->update($data);

        return response()->json([
            'message' => 'Scheduled transaction updated successfully',
            'data' => $transaction
        ]);
    }

    public function destroy($id)
    {
        $transaction = ScheduledTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $transaction->delete();

        return response()->json('Scheduled transaction deleted successfully');
    }

    public function retry($id)
    {
        $transaction = ScheduledTransaction::where('created_by', Auth::id())
            ->where('id', $id)
            ->where('status', 'failed')
            ->firstOrFail();

        try {

            $transaction->update([
                'status' => 'executed',
                'scheduled_at' => now()
            ]);

            return response()->json([
                'message' => 'Retry initiated',
                'status' => 'executed'
            ]);
        } catch (\Exception $e) {
            Log::error('Retry failed for scheduled transaction #' . $id . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Retry failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
