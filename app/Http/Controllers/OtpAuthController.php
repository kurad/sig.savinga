<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mail;

class OtpAuthController extends Controller
{
    public function __construct(
        protected SmsService $sms
    ) {}


    public function requestOtp(Request $request, OtpService $otp)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Do not reveal user existence
        if (!$user) {
            return response()->json([
                'message' => 'If the email exists, a verification code has been sent.'
            ]);
        }

        // simple rate limit: 1 per minute
        if ($user->otp_last_sent_at && now('Africa/Kigali')->diffInSeconds($user->otp_last_sent_at) < 60) {
            return response()->json([
                'message' => 'Please wait before requesting another code.'
            ], 429);
        }

        $code = $otp->generate();
        $minutes = 10;

        try {
            DB::transaction(function () use ($user, $otp, $code, $minutes) {
                $user->update([
                    'otp_code_hash' => Hash::make($code),
                    'otp_expires_at' => now('Africa/Kigali')->addMinutes($minutes),
                    'otp_attempts' => 0,
                    'otp_last_sent_at' => now(),
                ]);
                
                Mail::to($user->email)->send(new \App\Mail\EmailOtpMail($code, $minutes));

            });
        } catch (\Throwable $e) {
            \Log::error('OTP: mail send failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Mail failed', 'error' => $e->getMessage()], 500);

            return response()->json([
                'message' => 'If the email exists, a verification code has been sent.'
            ]);
        }

        return response()->json([
            'message' => 'If the email exists, a verification code has been sent.'
        ]);
    }

    public function verifyOtp(Request $request, OtpService $otp)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $genericInvalid = response()->json(['message' => 'Invalid code.'], 422);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $genericInvalid;
        }

        if ((int) $user->otp_attempts >= 5) {
            // optional: clear OTP so user must request a new one
            $user->update([
                'otp_code_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
            ]);
            return response()->json([
                'message' => 'Too many attempts. Request a new code.'
            ], 429);
        }

        // Expired or missing
        if (!$user->otp_expires_at || now()->gt($user->otp_expires_at) || !$user->otp_code_hash) {
            // optional: clear OTP
            $user->update([
                'otp_code_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
            ]);

            // either keep genericInvalid to avoid leakage, or return "Code expired."
            return $genericInvalid;
        }

        // Wrong code
        if (!$otp->check($request->code, $user->otp_code_hash)) {
            $user->increment('otp_attempts');
            return $genericInvalid;
        }

        // success — clear OTP
        $user->update([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Verified successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? null,
            ],
        ]);
    }

    /**
     * POST /api/auth/otp/request
     * Existing members only
     */
    public function login(RequestOtpRequest $request)
    {
        $phone = $this->normalizePhone($request->input('phone'));

        $user = User::where('phone', $phone)->first();
        if (!$user) {
            return response()->json(['message' => 'Phone number not registered'], 404);
        }
        if (($user->is_active ?? true) === false) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        // cooldown
        if ($user->otp_last_sent_at && now()->diffInSeconds($user->otp_last_sent_at) < 60) {
            return response()->json(['message' => 'Please wait before requesting another OTP.'], 429);
        }

        $loginCode = (string) random_int(100000, 999999);

        $user->update([
            'otp_code_hash'    => Hash::make($loginCode),
            'otp_expires_at'   => now()->addMinutes(5),
            'otp_attempts'     => 0,
            'otp_last_sent_at' => now(),
        ]);

        try {
            $smsResult = $this->sms->send(
                $user->phone,
                "Your login code is {$loginCode}. Don't share it with anyone."
            );
        } catch (\Throwable $e) {
            // don't leave user blocked
            $user->update([
                'otp_code_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
                'otp_last_sent_at' => null,
            ]);

            return response()->json(['message' => 'Failed to send OTP. Try again.'], 502);
        }

        $http = (int)($smsResult['http_code'] ?? 0);
        if ($http < 200 || $http >= 300) {
            $user->update([
                'otp_code_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
                'otp_last_sent_at' => null,
            ]);

            $payload = ['message' => 'Failed to send OTP. Try again.'];
            if (app()->environment('local')) $payload['sms'] = $smsResult;

            return response()->json($payload, 502);
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
        if (preg_match('/^07[89]\d{7}$/', $phone)) {
            return '250' . substr($phone, 3);
        }

        // Case 2: 7XXXXXXXX → 07XXXXXXXX
        if (preg_match('/^7[89]\d{7}$/', $phone)) {
            return '250' . $phone;
        }

        // Case 3: already correct 07XXXXXXXX
        if (preg_match('/^2507[89]\d{7}$/', $phone)) {
            return $phone;
        }

        throw new \InvalidArgumentException('Invalid Rwanda phone number. Use 078XXXXXXX or 079XXXXXXX.');
    }
}
