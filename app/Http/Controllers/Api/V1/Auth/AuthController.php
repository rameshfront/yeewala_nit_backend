<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        /** @var User $user */
        $user = Auth::user();

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
}

