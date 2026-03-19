<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

class LoginController extends Controller
{
    /**
     * OPTION 1: Login with Google Authenticator (TOTP)
     * POST /api/auth/login/totp
     * Body: identifier (email or phone), code (6 digits), device_name?
     */
    public function loginWithTotp(Request $request)
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string'],
        ]);

        $identifier = trim($request->identifier);
        $code = $request->code;

        $user = $this->findUserByIdentifier($identifier);

        // generic error to avoid leakage
        $invalid = response()->json(['message' => 'Invalid credentials.'], 422);

        if (!$user) return $invalid;
        if (($user->is_active ?? true) === false) return response()->json(['message' => 'Account is inactive'], 403);

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            // You can keep this generic too if you prefer
            return response()->json(['message' => 'Authenticator is not enabled for this account.'], 422);
        }

        // attempt limit (reuse otp_attempts)
        if ((int) $user->otp_attempts >= 5) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }

        $user->increment('otp_attempts');

        $secret = decrypt($user->two_factor_secret);
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($secret, $code, 1); // window=1 for clock drift
        if (!$valid) return $invalid;

        $user->update(['otp_attempts' => 0]);

        return $this->issueToken($user, $request->input('device_name') ?: 'totp');
    }

    /**
     * OPTION 2: Email login - request code
     * POST /api/auth/login/email/request
     */
    public function requestEmailCode(Request $request, OtpService $otp)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Do not reveal existence
        if (!$user) {
            return response()->json(['message' => 'If the email exists, a verification code has been sent.']);
        }

        if (($user->is_active ?? true) === false) {
            // still don’t leak too much — up to you; here we keep it generic
            return response()->json(['message' => 'If the email exists, a verification code has been sent.']);
        }

        // cooldown: 1/min
        if ($user->otp_last_sent_at && now()->diffInSeconds($user->otp_last_sent_at) < 60) {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }

        $code = $otp->generate();
        $minutes = 10;

        $user->update([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes($minutes),
            'otp_attempts' => 0,
            'otp_last_sent_at' => now(),
        ]);

        // Send once (your previous code had duplicates)
        Mail::to($user->email)->send(new \App\Mail\EmailOtpMail($code, $minutes));

        return response()->json(['message' => 'If the email exists, a verification code has been sent.']);
    }

    /**
     * OPTION 2: Email login - verify code
     * POST /api/auth/login/email/verify
     */
    public function verifyEmailCode(Request $request, OtpService $otp)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string'],
        ]);

        $invalid = response()->json(['message' => 'Invalid code.'], 422);

        $user = User::where('email', $request->email)->first();
        if (!$user) return $invalid;

        if ((int) $user->otp_attempts >= 5) {
            $this->clearEmailOtp($user);
            return response()->json(['message' => 'Too many attempts. Request a new code.'], 429);
        }

        if (
            !$user->otp_code_hash ||
            !$user->otp_expires_at ||
            now()->gt($user->otp_expires_at)
        ) {
            $this->clearEmailOtp($user);
            return $invalid;
        }

        if (!$otp->check($request->code, $user->otp_code_hash)) {
            $user->increment('otp_attempts');
            return $invalid;
        }

        $this->clearEmailOtp($user);

        return $this->issueToken($user, $request->input('device_name') ?: 'email-otp');
    }

    private function clearEmailOtp(User $user): void
    {
        $user->update([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ]);
    }

    private function issueToken(User $user, string $deviceName)
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role ?? null,
                'is_active' => $user->is_active ?? true,
            ],
        ]);
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $identifier)->first();
        }

        // phone - optional: normalize like you did before
        $phone = preg_replace('/\D+/', '', $identifier);

        // normalize to 2507XXXXXXXX (quick version)
        if (preg_match('/^07[89]\d{7}$/', $phone)) $phone = '250' . substr($phone, 1);
        if (preg_match('/^7[89]\d{7}$/', $phone))  $phone = '250' . $phone;

        if (!preg_match('/^2507[89]\d{7}$/', $phone)) return null;

        return User::where('phone', $phone)->first();
    }
}