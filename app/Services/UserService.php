<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{

    /**
     * to get all users
     *
     * @param array $filters{
     *   search?: string,
     *   status?: string,
     *   role?: string,
     * }
     * @return LengthAwarePaginator
     */
    public function get(array $filters) : LengthAwarePaginator
    {
        $query = User::query()->with('roles')->orderBy('created_at', 'desc');
        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('middle_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('national_id', 'like', '%' . $search . '%');
            });
        }
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['role']) {
            $role = $filters['role'];
            $query->whereHas('roles', function ($query) use ($role) {
                $query->where('name', $role);
            });
        }
        return $query->paginate(15);
    }

    /**
     * to create a new user
     *
     * The $data array should have the following required keys:
     * - first_name: string
     * - last_name: string
     * - middle_name: string
     * - email: string
     * - phone: string|null
     * - national_id: string|null
     * - date_of_birth: string (Y-m-d) or null
     * - address: string|null
     * - status: \App\Enums\UserStatus|string (optional, default active)
     * - password_hash: string
     *
     * @param array{
     *   first_name: string,
     *   last_name: string,
     *   middle_name: string,
     *   email: string,
     *   phone?: string|null,
     *   national_id?: string|null,
     *   date_of_birth?: string|null,
     *   address?: string|null,
     *   status?: \App\Enums\UserStatus|string,
     *   password_hash: string
     * } $data
     * @param array<string> $roles
     * @return User
     */
    public function create(array $data, array $roles) : User
    {
        $data['password_hash'] = Hash::make($data['password']);
        $data['status'] = UserStatus::ACTIVE;
        $user = User::create($data);
        $user->roles()->attach(Role::whereIn('name', $roles)->pluck('id'));
        return $user;
    }

    /**
     * to update a user
     * 
     * The $data array should have the following required keys:
     * - first_name: string
     * - last_name: string
     * - middle_name: string
     * - email: string
     * - phone: string|null
     * - national_id: string|null
     * - date_of_birth: string (Y-m-d) or null
     * - address: string|null
     * - status: \App\Enums\UserStatus|string (optional, default active)
     * - password_hash: string
     *
     * @param int $user_id
     * @param array{
     *   first_name: string,
     *   last_name: string,
     *   middle_name: string,
     *   email: string,
     *   phone?: string|null,
     *   national_id?: string|null,
     *   date_of_birth?: string|null,
     *   address?: string|null,
     *   status?: \App\Enums\UserStatus|string,
     *   password_hash: string
     * } $data
     * @param array<string> $roles
     * @return User
     * @throws NotFoundHttpException if the user is not found
     */
    public function update(int $user_id, array $data, array $roles) : User
    {
        $user = User::find($user_id);
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }
        $user->update($data);
        $user->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));
        return $user;
    }

    /**
     * to update the status of a user
     *
     * @param int $user_id
     * @param string $status
     * @return User
     * @throws NotFoundHttpException if the user is not found
     */
    public function updateStatus(int $user_id, string $status) : User
    {
        $user = User::find($user_id);
        if (!$user) {   
            throw new NotFoundHttpException('User not found');
        }
        $user->status = $status;
        $user->save();
        return $user;
    }

    /**
     * to get a user by id
     *
     * @param int $user_id
     * @return User|null
     * @throws NotFoundHttpException if the user is not found
     */
    public function getById(int $user_id) : User
    {
        $user = User::find($user_id);
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }
        return $user;
    }
}