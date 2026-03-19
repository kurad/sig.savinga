<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TotpAuthController extends Controller
{
    /**
     * POST /api/auth/totp/setup
     * Auth required: returns otpauth URL (+ optional QR svg) for enrollment.
     */
    public function setup(Request $request)
    {
        $user = $request->user();

        // If already enabled, don't overwrite secret
        if ($user->two_factor_enabled && $user->two_factor_secret) {
            return response()->json([
                'message' => 'Authenticator is already enabled.',
                'enabled' => true,
            ]);
        }

        $google2fa = new Google2FA();

        // Only generate secret if missing
        if (!$user->two_factor_secret) {
            $secret = $google2fa->generateSecretKey();
            $user->two_factor_secret = encrypt($secret);
            $user->two_factor_enabled = false;
            $user->two_factor_confirmed_at = null;
            $user->save();
        } else {
            $secret = decrypt($user->two_factor_secret);
        }

        $issuer = config('app.name', 'App');
        // If you're using phone as identity, label with phone (or email if you prefer)
        $label  = $user->phone ?: ($user->email ?? ('user-'.$user->id));

        $otpauthUrl = $google2fa->getQRCodeUrl($issuer, $label, $secret);

        return response()->json([
            'message' => 'Scan the QR with an Authenticator app and confirm using a 6-digit code.',
            'enabled' => false,
            'otpauth_url' => $otpauthUrl,
            // Only expose secret in local/dev if you want
            'secret' => app()->environment('local') ? $secret : null,
        ]);
    }

    /**
     * POST /api/auth/totp/confirm
     * body: { code }
     * Confirms the 6-digit code and enables authenticator + returns recovery codes once.
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => 'Authenticator setup not started.'], 422);
        }

        $secret = decrypt($user->two_factor_secret);
        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($secret, $request->code, 1)) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        // Generate recovery codes (one-time view)
        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(10)))
            ->values()
            ->all();

        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $recoveryCodes; // make sure column exists + cast array/json
        $user->otp_attempts = 0;
        $user->save();

        return response()->json([
            'message' => 'Authenticator enabled successfully.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * POST /api/auth/totp/disable
     * body: { code } OR { recovery_code }
     * Requires verification.
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => ['nullable', 'digits:6'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json(['message' => 'Authenticator is not enabled.'], 422);
        }

        $ok = false;

        // Verify by TOTP
        if ($request->filled('code')) {
            $secret = decrypt($user->two_factor_secret);
            $google2fa = new Google2FA();
            $ok = $google2fa->verifyKey($secret, $request->code, 1);
        }

        // Or verify by recovery code (consume it)
        if (!$ok && $request->filled('recovery_code')) {
            $codes = $user->two_factor_recovery_codes ?: [];
            $idx = array_search($request->recovery_code, $codes, true);
            if ($idx !== false) {
                $ok = true;
                unset($codes[$idx]);
                $user->two_factor_recovery_codes = array_values($codes);
            }
        }

        if (!$ok) {
            return response()->json(['message' => 'Verification failed.'], 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->otp_attempts = 0;
        $user->save();

        return response()->json(['message' => 'Authenticator disabled.']);
    }

    /**
     * POST /api/auth/totp/login
     * Input: phone, code (6 digits)
     */
    public function login(Request $request)
    {
        $request->validate([
            'phone'      => ['required', 'string'],
            'code'       => ['required', 'digits:6'],
            'device_name'=> ['nullable', 'string'],
        ]);

        $invalid = response()->json(['message' => 'Invalid credentials.'], 422);

        $phone = $this->normalizePhone($request->phone);
        $code  = $request->code;

        $user = User::where('phone', $phone)->first();
        if (!$user) return $invalid;
        if (($user->is_active ?? true) === false) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            // You can keep this generic too if you prefer
            return response()->json(['message' => 'Authenticator not enabled for this account.'], 422);
        }

        if ((int) $user->otp_attempts >= 5) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429);
        }

        $secret = decrypt($user->two_factor_secret);
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($secret, $code, 1);

        if (!$valid) {
            $user->increment('otp_attempts');
            return $invalid;
        }

        $user->update(['otp_attempts' => 0]);

        $device = $request->input('device_name') ?: 'authenticator';
        $token  = $user->createToken($device)->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role ?? null,
                'is_active' => $user->is_active ?? true,
                'two_factor_enabled' => (bool) $user->two_factor_enabled,
            ],
        ]);
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (preg_match('/^07[89]\d{7}$/', $phone)) {
            return '250' . substr($phone, 1);
        }

        if (preg_match('/^7[89]\d{7}$/', $phone)) {
            return '250' . $phone;
        }

        if (preg_match('/^2507[89]\d{7}$/', $phone)) {
            return $phone;
        }

        throw new \InvalidArgumentException('Invalid Rwanda phone number.');
    }
}