<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Services\UserService;
use Illuminate\Http\Request;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->get('search'),
            'status' => $request->get('status'),
            'role' => $request->get('role'),
        ];
        $users = $this->userService->get($filters);
        return UserResource::collection($users);
        
    }

    public function create(CreateUserRequest $request)
    {
        $data = $request->validated();
        $roles = $data['roles'];
        unset($data['roles']);
        $user = $this->userService->create($data, $roles);
        return response()->json(['message' => 'User created successfully', 'user' => UserResource::make($user)], 201);
    }

    public function update(UpdateUserRequest $request, int $user_id)
    {
        $data = $request->validated();
        $roles = $data['roles'];
        unset($data['roles']);
        $user = $this->userService->update($user_id, $data, $roles);
        return response()->json(['message' => 'User updated successfully', 'user' => UserResource::make($user)], 200);
    }

    public function updateStatus(Request $request, int $user_id)
    {
        $status = $request->status;
        if (!in_array($status, [UserStatus::ACTIVE->value, UserStatus::INACTIVE->value])) {
            return response()->json(['message' => 'Invalid status'], 422);
        }
        $user = $this->userService->updateStatus($user_id, $status);
        return response()->json(['message' => 'User status updated successfully', 'user' => UserResource::make($user)], 200);
    }

    public function show(int $user_id)
    {
        $user = $this->userService->getById($user_id);
        return response()->json(['data' => UserResource::make($user)], 200);
    }
}
