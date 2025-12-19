<?php

namespace App\Accounts\Services;

use App\Accounts\Composite\AccountGroup;
use App\Accounts\Composite\AccountLeaf;
use App\Accounts\Exceptions\AccountNotFoundException;
use App\Accounts\Exceptions\AccountTransitionException;
use App\Accounts\Factories\AccountFactory;
use App\Accounts\Factories\AccountStateFactory;
use App\Models\Account;
use App\Models\AccountState;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Create a new account group
     * 
     * An account group is a parent account that can contain child accounts.
     * 
     * @param array{
     *   account_type_id: int,
     *   currency: string,
     *   user_ids: array<int>,
     *   owner_user_id: int,
     * } $data
     * @param int $changedByUserId
     * @return AccountGroup
     */
    public function createAccountGroup(array $data, int $changedByUserId): AccountGroup
    {
        return DB::transaction(function () use ($data, $changedByUserId) {
            $accountNumber = AccountFactory::generateAccountNumber(isGroup: true);

            $accountData = [
                'account_type_id' => $data['account_type_id'],
                'parent_account_id' => null,
                'account_number' => $accountNumber,
                'balance' => 0.00,
                'currency' => strtoupper($data['currency']),
            ];

            $account = AccountFactory::create($accountData);

            // Attach users to the account group
            $userIds = $data['user_ids'] ?? [];
            $ownerUserId = $data['owner_user_id'];

            // Ensure owner is in user_ids
            if (!in_array($ownerUserId, $userIds)) {
                $userIds[] = $ownerUserId;
            }

            $attachData = [];
            foreach ($userIds as $userId) {
                $attachData[$userId] = [
                    'is_owner' => $userId === $ownerUserId,
                ];
            }

            $account->users()->attach($attachData);

            // Create initial state
            AccountState::create([
                'account_id' => $account->id,
                'state' => 'active',
                'changed_by' => $changedByUserId,
            ]);

            $account->load(['users', 'accountType', 'childrenAccounts']);

            return new AccountGroup($account);
        });
    }

    /**
     * Create a new account leaf (individual account)
     * 
     * @param array{
     *   account_type_id: int,
     *   parent_account_id?: int|null,
     *   currency?: string,
     *   initial_deposit: float,
     *   user_ids: array<int>,
     *   owner_user_id: int,
     * } $data
     * @param int $changedByUserId
     * @return AccountLeaf
     */
    public function createAccountLeaf(array $data, int $changedByUserId): AccountLeaf
    {
        return DB::transaction(function () use ($data, $changedByUserId) {
            // Validate parent if provided
            $currency = $data['currency'] ?? null;
            if ($data['parent_account_id'] ?? null) {
                $parent = Account::find($data['parent_account_id']);
                if (!$parent) {
                    throw new AccountNotFoundException('Parent account not found');
                }
                if ($parent->parent_account_id !== null) {
                    throw new AccountTransitionException('Parent account must be a group account');
                }
                $currency = $parent->currency;
            }

            if (!$currency) {
                throw new AccountTransitionException('Currency must be specified when no parent account is provided');
            }

            $accountNumber = AccountFactory::generateAccountNumber(isGroup: false);

            $accountData = [
                'account_type_id' => $data['account_type_id'],
                'parent_account_id' => $data['parent_account_id'] ?? null,
                'account_number' => $accountNumber,
                'balance' => $data['initial_deposit'],
                'currency' => strtoupper($currency),
            ];

            $account = AccountFactory::create($accountData);

            // Attach users
            $userIds = $data['user_ids'] ?? [];
            $ownerUserId = $data['owner_user_id'];

            if (!in_array($ownerUserId, $userIds)) {
                $userIds[] = $ownerUserId;
            }

            $attachData = [];
            foreach ($userIds as $userId) {
                $attachData[$userId] = [
                    'is_owner' => $userId === $ownerUserId,
                ];
            }

            $account->users()->attach($attachData);

            // Create initial state
            AccountState::create([
                'account_id' => $account->id,
                'state' => 'active',
                'changed_by' => $changedByUserId,
            ]);

            $account->load(['users', 'accountType', 'parentAccount']);

            return new AccountLeaf($account);
        });
    }

    /**
     * Get account group with nested children
     * 
     * @param int $accountId
     * @return AccountGroup
     * @throws NotFoundHttpException
     */
    public function getAccountGroup(int $accountId): AccountGroup
    {
        $account = Account::with([
            'accountType',
            'users',
            'childrenAccounts.accountType',
            'childrenAccounts.users',
            'childrenAccounts.currentState',
            'currentState',
            'features'
        ])
            ->whereNull('parent_account_id')
            ->find($accountId);

        if (!$account) {
            throw new AccountNotFoundException('Account group not found');
        }

        return new AccountGroup($account);
    }

    /**
     * Get all account groups with pagination
     * 
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getAllAccountGroups(array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $page = $filters['page'] ?? 1;

        $query = Account::with([
            'accountType',
            'users',
            'childrenAccounts.accountType',
            'childrenAccounts.users',
            'childrenAccounts.currentState',
            'currentState',
            'features'
        ])
            ->whereNull('parent_account_id')
            ->orderBy('created_at', 'desc');

        // Search by account number
        if (!empty($filters['account_number'])) {
            $query->where('account_number', 'like', '%' . $filters['account_number'] . '%');
        }

        $paginatedAccounts = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform paginated accounts to AccountGroup composites
        $accountGroups = $paginatedAccounts->getCollection()->map(fn($account) => new AccountGroup($account));

        return new LengthAwarePaginator(
            $accountGroups,
            $paginatedAccounts->total(),
            $paginatedAccounts->perPage(),
            $paginatedAccounts->currentPage(),
            [
                'path' => $paginatedAccounts->path(),
                'pageName' => $paginatedAccounts->getPageName(),
            ]
        );
    }

    /**
     * Get data needed for account creation
     * 
     * @return array
     */
    public function getAccountCreationData(): array
    {
        return [
            'users' => \App\Models\User::select('id', 'first_name', 'last_name', 'email')->get(),
            'account_types' => \App\Models\AccountType::select('id', 'name')->get(),
            'features' => \App\Models\AccountFeature::select('id', 'label')->get(),
            'account_groups' => Account::whereNull('parent_account_id')
                ->select('id', 'account_number', 'currency')
                ->get(),
        ];
    }

    /**
     * Update account state
     * 
     * @param int $accountId
     * @param string $newState
     * @param int $changedByUserId
     * @return AccountGroup|AccountLeaf
     * @throws NotFoundHttpException
     * @throws UnprocessableEntityHttpException
     */
    public function updateAccountState(int $accountId, string $newState, int $changedByUserId): AccountGroup|AccountLeaf
    {
        return DB::transaction(function () use ($accountId, $newState, $changedByUserId) {
            $account = Account::with(['currentState', 'childrenAccounts'])->find($accountId);

            if (!$account) {
                throw new AccountNotFoundException('Account not found');
            }

            $changedByUser = \App\Models\User::with('roles')->find($changedByUserId);
            if (!$changedByUser) {
                throw new AccountNotFoundException('User not found');
            }

            // Create state instance
            $state = AccountStateFactory::create($newState);

            // Create appropriate composite component
            $component = $account->parent_account_id === null
                ? new \App\Accounts\Composite\AccountGroup($account)
                : new \App\Accounts\Composite\AccountLeaf($account);

            // Apply state change (component handles validation via state and cascading)
            $result = $component->applyState($state, $changedByUser);

            // Ensure account is reloaded with all relationships
            $account->load([
                'accountType',
                'users',
                'currentState',
                'childrenAccounts.accountType',
                'childrenAccounts.users',
                'childrenAccounts.currentState',
                'features'
            ]);

            return $result;
        });
    }
}
