<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoMembersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(4, 10) as $i) {
            User::firstOrCreate(
                ['phone' => '07880000' . $i],
                [
                    'name' => "Member {$i}",
                    'role' => 'member',
                    'is_active' => true,
                    'password' => Hash::make('password'),
                ]
            );
        }
    }
}
