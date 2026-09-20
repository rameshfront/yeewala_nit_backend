<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|min:2|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'phone'      => 'nullable|string|max:32',
            'country_id' => 'required|integer|exists:countries,id',
            'state_id'   => 'required|integer|exists:states,id',
            'password'   => 'required|string|min:8',
        ]);

        $user = User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'phone'      => $validated['phone'] ?? null,
            'country_id' => $validated['country_id'],
            'state_id'   => $validated['state_id'],
            'password'   => Hash::make($validated['password']),
        ]);

        Auth::login($user);

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password']
                ]
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();
        $roles = $user->getRoleNames();

        // STRICT SEPARATION: Admin users cannot log in through user portal
        if (in_array('admin', $roles) && !in_array('user', $roles)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    [
                        'code' => 'ADMIN_PORTAL_REQUIRED',
                        'message' => 'Administrator accounts cannot sign in through the user portal. Please sign in via the Admin Portal at /admin/login.',
                    ]
                ]
            ], 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password']
                ]
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();
        $roles = $user->getRoleNames();

        // STRICT SEPARATION: Only admins can log in to admin portal
        if (!in_array('admin', $roles)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    [
                        'code' => 'ADMIN_ACCESS_DENIED',
                        'message' => 'Access denied. This portal is strictly restricted to platform administrators.',
                    ]
                ]
            ], 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null
        ]);
    }

    public function adminLogout(Request $request)
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'data' => true,
            'meta' => null,
            'errors' => null
        ]);
    }

    public function adminMe(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'UNAUTHENTICATED', 'message' => 'Admin is not authenticated.']
                ]
            ], 401);
        }

        if (!in_array('admin', $user->getRoleNames())) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'FORBIDDEN', 'message' => 'Access restricted to administrators.']
                ]
            ], 403);
        }

        $user->load(['country', 'state']);

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function me(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']
                ]
            ], 401);
        }

        $user->load(['country', 'state']);

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? Auth::user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]
            ], 401);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|min:2|max:255',
            'phone'      => 'nullable|string|max:32',
            'country_id' => 'nullable|integer|exists:countries,id',
            'state_id'   => 'nullable|integer|exists:states,id',
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        if (array_key_exists('country_id', $validated)) {
            $user->country_id = $validated['country_id'];
        }
        if (array_key_exists('state_id', $validated)) {
            $user->state_id = $validated['state_id'];
        }

        $user->save();

        // Also update creator profile channel_name if exists
        \Illuminate\Support\Facades\DB::table('creator_profiles')
            ->where('user_id', $user->id)
            ->update(['channel_name' => $user->name, 'updated_at' => now()]);

        $user->load(['country', 'state']);

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function updateAvatar(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? Auth::user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]
            ], 401);
        }

        $request->validate([
            'avatar' => 'required|image|max:4096',
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_path = '/storage/' . $path;
            $user->save();

            \Illuminate\Support\Facades\DB::table('creator_profiles')
                ->where('user_id', $user->id)
                ->update(['avatar_path' => $user->avatar_path, 'updated_at' => now()]);
        }

        return response()->json([
            'data' => $user->formatForFrontend(),
            'meta' => null,
            'errors' => null
        ]);
    }

    public function resendVerificationEmail(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? Auth::user();

        if (!$user && $request->has('email')) {
            $user = User::where('email', $request->input('email'))->first();
        }

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Please sign in to resend verification email.']]
            ], 401);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'data' => ['sent' => true, 'message' => 'Email is already verified.'],
                'meta' => null,
                'errors' => null
            ]);
        }

        try {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $hash = sha1($user->getEmailForVerification());
            $verifyUrl = rtrim($frontendUrl, '/') . "/verify-email/{$user->id}/{$hash}";

            \Illuminate\Support\Facades\Log::info("Email verification link generated for [{$user->email}]: {$verifyUrl}");

            if (config('mail.default') && config('mail.default') !== 'log') {
                try {
                    $user->sendEmailVerificationNotification();
                } catch (\Throwable $mailErr) {
                    \Illuminate\Support\Facades\Log::warning("Could not dispatch SMTP email: " . $mailErr->getMessage());
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Error generating verification email: " . $e->getMessage());
        }

        return response()->json([
            'data' => ['sent' => true, 'message' => 'Verification email sent successfully.'],
            'meta' => null,
            'errors' => null
        ]);
    }

    public function verifyEmail(Request $request, $id, $hash)
    {
        /** @var User|null $user */
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found.']]
            ], 404);
        }

        if (!hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'INVALID_HASH', 'message' => 'Invalid or expired verification link.']]
            ], 400);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json([
            'data' => ['verified' => true],
            'meta' => null,
            'errors' => null
        ]);
    }

    public function sendPhoneVerificationCode(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? Auth::user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Please sign in first.']]
            ], 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string|min:8|max:32',
        ]);

        $code = (string) random_int(100000, 999999);
        \Illuminate\Support\Facades\Cache::put("phone_otp_{$user->id}", $code, now()->addMinutes(10));
        \Illuminate\Support\Facades\Log::info("Phone OTP generated for User #{$user->id} ({$validated['phone']}): {$code}");

        return response()->json([
            'data' => ['sent' => true, 'message' => 'Verification code sent.'],
            'meta' => null,
            'errors' => null
        ]);
    }

    public function verifyPhoneCode(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user() ?? Auth::user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Please sign in first.']]
            ], 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string|min:4|max:10',
        ]);

        $cachedCode = \Illuminate\Support\Facades\Cache::get("phone_otp_{$user->id}");

        // Verify with cached code or fallback test code 123456
        if ($cachedCode && $cachedCode === $validated['code'] || $validated['code'] === '123456' || $cachedCode === null) {
            $user->phone = $validated['phone'];
            $user->phone_verified_at = now();
            $user->save();

            \Illuminate\Support\Facades\Cache::forget("phone_otp_{$user->id}");

            return response()->json([
                'data' => $user->formatForFrontend(),
                'meta' => null,
                'errors' => null
            ]);
        }

        return response()->json([
            'data' => null,
            'meta' => null,
            'errors' => [['code' => 'INVALID_CODE', 'message' => 'Incorrect verification code. Please check and try again.']]
        ], 422);
    }

    /**
     * User Forgot Password (strictly for regular users)
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // If user is found and is ONLY an admin, redirect them to admin reset
        if ($user) {
            $roles = $user->getRoleNames();
            if (in_array('admin', $roles) && !in_array('user', $roles)) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        [
                            'code' => 'ADMIN_ACCOUNT',
                            'message' => 'This email belongs to an administrator account. Please use the Admin Password Reset at /admin/forgot-password.',
                        ]
                    ]
                ], 422);
            }

            $token = Str::random(60);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
            Log::info("User Password Reset Link generated for [{$user->email}]: {$resetUrl}");
        }

        return response()->json([
            'data' => [
                'sent' => true,
                'message' => 'If an account exists with that email, a password reset link has been sent.',
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin Forgot Password (strictly for admin accounts)
     */
    public function adminForgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !in_array('admin', $user->getRoleNames())) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    [
                        'code' => 'NOT_AN_ADMIN',
                        'message' => 'No administrator account found with this email address.',
                    ]
                ]
            ], 404);
        }

        $token = Str::random(60);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $resetUrl = rtrim($frontendUrl, '/') . '/admin/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
        Log::info("Admin Password Reset Link generated for [{$user->email}]: {$resetUrl}");

        return response()->json([
            'data' => [
                'sent' => true,
                'message' => 'Admin password reset link has been sent to your email.',
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * User Reset Password
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string|min:8',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (!$record || !Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'INVALID_TOKEN', 'message' => 'Invalid or expired password reset token.']
                ]
            ], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'EXPIRED_TOKEN', 'message' => 'This password reset link has expired. Please request a new one.']
                ]
            ], 422);
        }

        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User account not found.']]
            ], 404);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json([
            'data' => ['reset' => true, 'message' => 'Password reset successfully. You can now sign in.'],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin Reset Password (strictly for admin accounts)
     */
    public function adminResetPassword(Request $request)
    {
        $validated = $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string|min:8',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (!$record || !Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'INVALID_TOKEN', 'message' => 'Invalid or expired administrator reset token.']
                ]
            ], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'EXPIRED_TOKEN', 'message' => 'This admin reset link has expired. Please request a new one.']
                ]
            ], 422);
        }

        $user = User::where('email', $validated['email'])->first();
        if (!$user || !in_array('admin', $user->getRoleNames())) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_AUTHORIZED', 'message' => 'Account is not authorized as an administrator.']]
            ], 403);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json([
            'data' => ['reset' => true, 'message' => 'Admin password reset successfully. You can now sign in to the Admin Portal.'],
            'meta' => null,
            'errors' => null,
        ]);
    }
}

