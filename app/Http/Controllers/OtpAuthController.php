<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class OtpAuthController extends Controller
{
    public function __construct(
        protected SmsService $sms
    ) {}

    /**
     * POST /api/auth/otp/request
     * Existing members only
     */
    public function login(RequestOtpRequest $request)
    {
        $phone = $this->normalizePhone($request->input('phone'));

        // Only existing members (your requirement)
        $user = User::where('phone', $phone)->first();
        if (!$user) {
            return response()->json(['message' => 'Phone number not registered'], 404);
        }
        if (($user->is_active ?? true) === false) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        // resend cooldown (60 seconds)
        if ($user->otp_last_sent_at && Carbon::parse($user->otp_last_sent_at)->diffInSeconds(now()) < 60) {
            return response()->json(['message' => 'Please wait before requesting another OTP.'], 429);
        }

        $loginCode = (string) random_int(100000, 999999);

        $user->update([
            'otp_code_hash'   => Hash::make($loginCode),
            'otp_expires_at'  => now()->addMinutes(5),
            'otp_attempts'    => 0,
            'otp_last_sent_at'=> now(),
        ]);

        $smsResult = $this->sms->send(
            $user->phone,
            "Your login code is {$loginCode}. Don't share it with anyone."
        );

        // If SMS provider failed, don't leave user blocked
        if (($smsResult['http_code'] ?? 0) < 200 || ($smsResult['http_code'] ?? 0) >= 300) {
            // Optional: clear OTP if send fails
            $user->update([
                'otp_code_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
            ]);

            return response()->json([
                'message' => 'Failed to send OTP. Try again.',
                'sms' => $smsResult, // you may remove this in production
            ], 502);
        }

        return response()->json([
            'message' => 'Text message notification sent.',
        ]);
    }

    /**
     * POST /api/auth/otp/verify
     */
    public function verify(VerifyOtpRequest $request)
    {
        $phone = $this->normalizePhone($request->input('phone'));
        $code  = $request->input('login_code');

        $user = User::where('phone', $phone)->first();
        if (!$user) {
            return response()->json(['message' => 'Phone number not registered'], 404);
        }

        if (!$user->otp_code_hash || !$user->otp_expires_at) {
            return response()->json(['message' => 'OTP not found. Request a new code.'], 422);
        }

        if (now()->gt(Carbon::parse($user->otp_expires_at))) {
            $user->update(['otp_code_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0]);
            return response()->json(['message' => 'OTP expired. Request a new code.'], 422);
        }

        if ((int)$user->otp_attempts >= 5) {
            $user->update(['otp_code_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0]);
            return response()->json(['message' => 'Too many attempts. Request a new code.'], 422);
        }

        // increment attempts
        $user->increment('otp_attempts');

        if (!Hash::check($code, $user->otp_code_hash)) {
            return response()->json(['message' => 'Invalid verification code.'], 401);
        }

        // success: clear OTP fields
        $user->update([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ]);

        $device = $request->input('device_name') ?: 'phone-otp';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active ?? true,
            ],
        ]);
    }

    protected function normalizePhone(string $phone): string
{
    // Remove all non-digits (spaces, +, dashes, etc.)
    $phone = preg_replace('/\D+/', '', $phone);

    /**
     * Possible cleaned formats:
     * 0788123456
     * 0791123456
     * 788123456
     * 791123456
     * 250788123456
     * 250791123456
     */

    // Case 1: 2507XXXXXXXX → 07XXXXXXXX
    if (preg_match('/^2507[89]\d{7}$/', $phone)) {
        return '0' . substr($phone, 3);
    }

    // Case 2: 7XXXXXXXX → 07XXXXXXXX
    if (preg_match('/^7[89]\d{7}$/', $phone)) {
        return '0' . $phone;
    }

    // Case 3: already correct 07XXXXXXXX
    if (preg_match('/^07[89]\d{7}$/', $phone)) {
        return $phone;
    }

    throw new \InvalidArgumentException('Invalid Rwanda phone number. Use 078XXXXXXX or 079XXXXXXX.');
}

}
