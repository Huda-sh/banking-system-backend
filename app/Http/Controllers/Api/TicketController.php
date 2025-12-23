<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketController extends Controller
{
    /**
     * Store a newly created ticket.
     */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $user = Auth::user();

        $ticket = Ticket::create([
            'title' => $request->title,
            'description' => $request->description,
            'status' => $request->status ?? TicketStatus::PENDING,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Ticket created successfully',
            'data' => new TicketResource($ticket->load('user')),
        ], 201);
    }

    /**
     * Update the status of a ticket.
     */
    public function updateStatus(UpdateTicketStatusRequest $request, int $id): JsonResponse
    {
        $user = Auth::user();

        // Check if user has required role (Admin, Manager, or Teller)
        $userRoles = $user->roles->pluck('name')->toArray();
        $allowedRoles = ['Admin', 'Manager', 'Teller'];

        $hasPermission = false;
        foreach ($allowedRoles as $role) {
            if (in_array($role, $userRoles)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            return response()->json([
                'message' => 'Forbidden. You do not have the required role.',
            ], 403);
        }

        $ticket = Ticket::findOrFail($id);

        $ticket->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Ticket status updated successfully',
            'data' => new TicketResource($ticket->load('user')),
        ], 200);
    }

    /**
     * Display a paginated listing of tickets.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,in_progress,resolved',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Ticket::query()->with('user');

        // Apply search filter
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Order by latest first and paginate
        $perPage = $request->input('per_page', 15);
        $tickets = $query->latest()->paginate($perPage);

        return TicketResource::collection($tickets);
    }
}
