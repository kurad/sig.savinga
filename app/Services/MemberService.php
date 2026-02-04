<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberService
{
    public function create(array $data): array
    {
        $password = $data['password'] ?? Str::random(10);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'is_active' => true,
            'password' => Hash::make($password),
        ]);

        return [
            'user' => $user,
            'plain_password' => isset($data['password']) ? null : $password, // return generated only
        ];
    }

    public function update(User $user, array $data): User
    {
        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $user;
    }

    public function setActive(User $user, bool $isActive): User
    {
        $user->update(['is_active' => $isActive]);
        return $user;
    }
}
