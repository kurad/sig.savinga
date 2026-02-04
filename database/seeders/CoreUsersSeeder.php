<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CoreUsersSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['phone' => '0788407941'],
            [
                'name' => 'Chair Person',
                'email' => 'chair@example.com',
                'role' => 'admin',
                'is_active' => true,
                'password' => Hash::make('password'),
            ]
        );

        User::firstOrCreate(
            ['phone' => '0788000002'],
            [
                'name' => 'Treasurer',
                'email' => 'treasurer@example.com',
                'role' => 'treasurer',
                'is_active' => true,
                'password' => Hash::make('password'),
            ]
        );

        User::firstOrCreate(
            ['phone' => '0786076013'],
            [
                'name' => 'Member One',
                'email' => 'member@example.com',
                'role' => 'member',
                'is_active' => true,
                'password' => Hash::make('password'),
            ]
        );
    }
}
