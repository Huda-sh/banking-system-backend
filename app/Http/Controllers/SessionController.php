<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SessionController extends Controller
{
    public function create(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Invalid email'], 422);
        }
        
        if (!Hash::check($request->password, $user->password_hash)) {
            return response()->json(['message' => 'Invalid password'], 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['message' => 'Login successful', 'user' => UserResource::make($user), 'token' => $token], 200);
    }

    public function destroy()
    {
        $user = request()->user();
        $user->tokens()->delete();
        return response()->json(['message' => 'Logout successful'], 200);
    }

    public function me()
    {
        $user = request()->user();
        return response()->json(['user' => UserResource::make($user)], 200);
    }
}
