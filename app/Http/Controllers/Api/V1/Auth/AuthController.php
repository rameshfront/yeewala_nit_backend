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
            'name' => 'required|string|min:2|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:32',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
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

        $request->session()->regenerate();
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
        $request->session()->invalidate();
        $request->session()->regenerateToken();

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
            'name' => 'sometimes|required|string|min:2|max:255',
            'phone' => 'nullable|string|max:32',
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }

        $user->save();

        // Also update creator profile channel_name if exists
        \Illuminate\Support\Facades\DB::table('creator_profiles')
            ->where('user_id', $user->id)
            ->update(['channel_name' => $user->name, 'updated_at' => now()]);

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
}
