<?php

namespace App\Http\Controllers;

use App\Accounts\Services\AccountService;
use App\Http\Requests\Account\CreateAccountGroupRequest;
use App\Http\Requests\Account\CreateAccountLeafRequest;
use App\Http\Requests\Account\UpdateAccountStateRequest;
use App\Http\Resources\AccountCreationDataResource;
use App\Http\Resources\AccountGroupResource;
use App\Http\Resources\AccountResource;
use App\Http\Resources\MinimalAccountFeatureResource;
use App\Http\Resources\MinimalAccountGroupResource;
use App\Http\Resources\MinimalAccountTypeResource;
use App\Http\Resources\MinimalUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountGroupController extends Controller
{
    public function __construct(private readonly AccountService $accountService) {}

    /**
     * Create a new account group
     */
    public function createGroup(CreateAccountGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $changedByUserId = $request->user()->id;

        $accountGroup = $this->accountService->createAccountGroup($data, $changedByUserId);

        return response()->json([
            'message' => 'Account group created successfully',
            'data' => AccountGroupResource::make($accountGroup)
        ], 201);
    }

    /**
     * Create a new account leaf (individual account)
     */
    public function createLeaf(CreateAccountLeafRequest $request): JsonResponse
    {
        $data = $request->validated();
        $changedByUserId = $request->user()->id;

        $accountLeaf = $this->accountService->createAccountLeaf($data, $changedByUserId);

        return response()->json([
            'message' => 'Account created successfully',
            'data' => AccountResource::make($accountLeaf)
        ], 201);
    }

    /**
     * Get all account groups with nested children
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'account_number' => $request->get('account_number'),
            'per_page' => $request->get('per_page', 15),
            'page' => $request->get('page', 1),
        ];

        $paginatedGroups = $this->accountService->getAllAccountGroups($filters);

        return response()->json([
            'data' => AccountGroupResource::collection($paginatedGroups->getCollection()),
            'pagination' => [
                'current_page' => $paginatedGroups->currentPage(),
                'per_page' => $paginatedGroups->perPage(),
                'total' => $paginatedGroups->total(),
                'last_page' => $paginatedGroups->lastPage(),
                'from' => $paginatedGroups->firstItem(),
                'to' => $paginatedGroups->lastItem(),
            ]
        ], 200);
    }

    /**
     * Get data needed for account creation
     */
    public function getCreationData(): JsonResponse
    {
        $data = $this->accountService->getAccountCreationData();

        return response()->json([
            'users' => MinimalUserResource::collection($data['users']),
            'account_types' => MinimalAccountTypeResource::collection($data['account_types']),
            'features' => MinimalAccountFeatureResource::collection($data['features']),
            'account_groups' => MinimalAccountGroupResource::collection($data['account_groups']),
        ], 200);
    }

    /**
     * Get a specific account group with nested children
     */
    public function show(int $accountGroupId): JsonResponse
    {
        $accountGroup = $this->accountService->getAccountGroup($accountGroupId);

        return response()->json([
            'data' => AccountGroupResource::make($accountGroup)
        ], 200);
    }

    /**
     * Update account state
     */
    public function updateState(UpdateAccountStateRequest $request, int $accountId): JsonResponse
    {
        $newState = $request->validated()['state'];
        $changedByUserId = $request->user()->id;

        $account = $this->accountService->updateAccountState($accountId, $newState, $changedByUserId);

        $resource = $account instanceof \App\Accounts\Composite\AccountGroup
            ? AccountGroupResource::make($account)
            : AccountResource::make($account);

        return response()->json([
            'message' => 'Account state updated successfully',
            'data' => $resource
        ], 200);
    }
}
