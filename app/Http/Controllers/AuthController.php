<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $login = $request->input('login');
        $password = $request->input('password');

        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if (property_exists($user, 'is_active') && !$user->is_active) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        $deviceName = $request->input('device_name') ?: 'api-token';

        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'is_active' => $user->is_active ?? true,
            ],
        ]);
    }

    public function me()
    {
        $user = request()->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'is_active' => $user->is_active ?? true,
            ],
        ]);
    }

    public function logout()
    {
        $user = request()->user();

        // revoke current token only
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
