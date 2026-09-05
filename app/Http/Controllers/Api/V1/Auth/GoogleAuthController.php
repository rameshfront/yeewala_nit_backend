<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Authenticate or register using Google credentials (ID token or OAuth authorization code).
     */
    public function authWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential'   => 'nullable|string',
            'code'         => 'nullable|string',
            'redirect_uri' => 'nullable|string',
        ]);

        if (empty($validated['credential']) && empty($validated['code'])) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'INVALID_PAYLOAD', 'message' => 'Google credential or authorization code is required.']
                ],
            ], 422);
        }

        $googleId = null;
        $email = null;
        $name = null;
        $picture = null;

        // Mode 1: Verified Google Identity Services ID Token (JWT)
        if (!empty($validated['credential'])) {
            $tokenInfoResponse = Http::get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $validated['credential'],
            ]);

            if (!$tokenInfoResponse->successful()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        ['code' => 'INVALID_GOOGLE_TOKEN', 'message' => 'Unable to verify Google token.']
                    ],
                ], 401);
            }

            $payload = $tokenInfoResponse->json();
            $expectedClientId = config('services.google.client_id');

            if ($expectedClientId && isset($payload['aud']) && $payload['aud'] !== $expectedClientId) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        ['code' => 'CLIENT_MISMATCH', 'message' => 'Google client ID mismatch.']
                    ],
                ], 401);
            }

            $googleId = $payload['sub'] ?? null;
            $email    = $payload['email'] ?? null;
            $name     = $payload['name'] ?? null;
            $picture  = $payload['picture'] ?? null;
        }

        // Mode 2: Authorization Code exchange
        if (empty($email) && !empty($validated['code'])) {
            $redirectUri = $validated['redirect_uri'] ?: config('services.google.redirect');

            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code'          => $validated['code'],
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]);

            if (!$tokenResponse->successful() || empty($tokenResponse->json('access_token'))) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        ['code' => 'GOOGLE_AUTH_FAILED', 'message' => 'Failed to exchange authorization code with Google.']
                    ],
                ], 401);
            }

            $userInfoResponse = Http::withToken($tokenResponse->json('access_token'))
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if (!$userInfoResponse->successful()) {
                return response()->json([
                    'data' => null,
                    'meta' => null,
                    'errors' => [
                        ['code' => 'PROFILE_FETCH_FAILED', 'message' => 'Could not fetch user profile from Google.']
                    ],
                ], 401);
            }

            $userInfo = $userInfoResponse->json();
            $googleId = $userInfo['sub'] ?? null;
            $email    = $userInfo['email'] ?? null;
            $name     = $userInfo['name'] ?? null;
            $picture  = $userInfo['picture'] ?? null;
        }

        if (empty($email)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [
                    ['code' => 'NO_EMAIL', 'message' => 'No email address returned by Google.']
                ],
            ], 422);
        }

        // Find or create user
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if ($user) {
            $changed = false;

            if (empty($user->google_id) && $googleId) {
                $user->google_id = $googleId;
                $changed = true;
            }

            if (empty($user->avatar_path) && $picture) {
                $user->avatar_path = $picture;
                $changed = true;
            }

            if (empty($user->email_verified_at)) {
                $user->email_verified_at = now();
                $changed = true;
            }

            if ($changed) {
                $user->save();
            }
        } else {
            // First-time user: automatically register
            $defaultCountry = Country::where('code', 'IN')->first() ?? Country::first();
            $defaultState = $defaultCountry ? State::where('country_id', $defaultCountry->id)->first() : null;

            $user = User::create([
                'name'              => $name ?: explode('@', $email)[0],
                'email'             => $email,
                'google_id'         => $googleId,
                'avatar_path'       => $picture,
                'email_verified_at' => now(),
                'country_id'        => $defaultCountry?->id,
                'state_id'          => $defaultState?->id,
                'password'          => Hash::make(Str::random(32)),
            ]);
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->load(['country', 'state']);

        return response()->json([
            'data'   => $user->formatForFrontend(),
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Redirect directly to Google's OAuth 2.0 consent page.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => 'openid profile email',
            'access_type'   => 'offline',
            'prompt'        => 'select_account',
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?{$params}");
    }

    /**
     * Handle Google redirect callback.
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        if ($request->has('error') || !$request->has('code')) {
            return redirect("{$frontendUrl}/login?error=google_cancelled");
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $request->query('code'),
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if (!$tokenResponse->successful() || empty($tokenResponse->json('access_token'))) {
            return redirect("{$frontendUrl}/login?error=google_token_failed");
        }

        $userInfoResponse = Http::withToken($tokenResponse->json('access_token'))
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (!$userInfoResponse->successful() || empty($userInfoResponse->json('email'))) {
            return redirect("{$frontendUrl}/login?error=google_profile_failed");
        }

        $userInfo = $userInfoResponse->json();
        $email    = $userInfo['email'];
        $googleId = $userInfo['sub'] ?? null;
        $name     = $userInfo['name'] ?? null;
        $picture  = $userInfo['picture'] ?? null;

        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if ($user) {
            if (empty($user->google_id) && $googleId) {
                $user->google_id = $googleId;
            }
            if (empty($user->avatar_path) && $picture) {
                $user->avatar_path = $picture;
            }
            if (empty($user->email_verified_at)) {
                $user->email_verified_at = now();
            }
            $user->save();
        } else {
            $defaultCountry = Country::where('code', 'IN')->first() ?? Country::first();
            $defaultState = $defaultCountry ? State::where('country_id', $defaultCountry->id)->first() : null;

            $user = User::create([
                'name'              => $name ?: explode('@', $email)[0],
                'email'             => $email,
                'google_id'         => $googleId,
                'avatar_path'       => $picture,
                'email_verified_at' => now(),
                'country_id'        => $defaultCountry?->id,
                'state_id'          => $defaultState?->id,
                'password'          => Hash::make(Str::random(32)),
            ]);
        }

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect("{$frontendUrl}/?login_success=1");
    }
}
