<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function hash(string $code): string
    {
        return Hash::make($code);
    }

    public function check(string $code, ?string $hash): bool
    {
        return $hash && Hash::check($code, $hash);
    }

    public function expiresAt(int $minutes = 10): Carbon
    {
        return now('Africa/Kigali')->addMinutes($minutes);
    }
}
