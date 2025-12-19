<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'middle_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'phone' => '1234567890',
                'national_id' => '1234567890',
                'date_of_birth' => '1990-01-01',
                'address' => '1234567890',
                'status' => UserStatus::ACTIVE,
                'password_hash' => Hash::make('password'),
                'roles' => ['Admin'],
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'middle_name' => 'Doe',
                'email' => 'jane.doe@example.com',
                'phone' => '1234567890',
                'national_id' => '1234567890',
                'date_of_birth' => '1990-01-01',
                'address' => '1234567890',
                'status' => UserStatus::ACTIVE,
                'password_hash' => Hash::make('password'),
                'roles' => ['Teller'],
            ],
            [
                'first_name' => 'Jim',
                'last_name' => 'Doe',
                'middle_name' => 'Doe',
                'email' => 'jim.doe@example.com',
                'phone' => '1234567890',
                'national_id' => '1234567890',
                'date_of_birth' => '1990-01-01',
                'address' => '1234567890',
                'status' => UserStatus::ACTIVE,
                'password_hash' => Hash::make('password'),
                'roles' => ['Customer'],
            ]
        ];

        foreach ($users as $user) {
            $roleNames = $user['roles'];
            unset($user['roles']);
            $user = User::firstOrCreate([
                'email' => $user['email'],
            ], $user);

            $roleIds = Role::whereIn('name', $roleNames)->pluck('id')->toArray();
            // Attach roles without creating duplicates
            $user->roles()->syncWithoutDetaching($roleIds);
        }
    }
}
