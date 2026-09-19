<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('users')->select('users.*');

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (Schema::hasColumn('users', 'status')) {
                $query->where('status', $status);
            }
        }

        $users = $query->orderBy('id', 'desc')->get();

        $rolesMap = [];
        if (Schema::hasTable('model_has_roles')) {
            $roles = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->select('model_has_roles.model_id', 'roles.name')
                ->get();
            foreach ($roles as $r) {
                $rolesMap[$r->model_id][] = $r->name;
            }
        }

        if ($request->filled('role')) {
            $targetRole = $request->query('role');
            $users = $users->filter(function ($u) use ($rolesMap, $targetRole) {
                $userRoles = $rolesMap[$u->id] ?? ['user'];
                return in_array($targetRole, $userRoles);
            })->values();
        }

        $formatted = $users->map(function ($u) use ($rolesMap) {
            $userRoles = $rolesMap[$u->id] ?? ['user'];
            if (empty($userRoles)) $userRoles = ['user'];

            return [
                'id' => (int)$u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone ?? null,
                'avatar_url' => $this->formatAvatarUrl($u->avatar_path ?? null),
                'status' => $u->status ?? 'active',
                'status_reason' => $u->status_reason ?? null,
                'status_changed_at' => $u->status_changed_at ?? null,
                'email_verified_at' => $u->email_verified_at ?? null,
                'two_factor_enabled' => false,
                'roles' => $userRoles,
                'has_creator_profile' => (bool)DB::table('creator_profiles')->where('user_id', $u->id)->exists(),
                'warning_count' => 0,
                'last_login_at' => $u->last_login_at ?? $u->updated_at ?? $u->created_at,
                'created_at' => $u->created_at,
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ],
            ],
            'errors' => null,
        ]);
    }

    public function show($id)
    {
        $u = DB::table('users')->where('id', $id)->first();
        if (!$u) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $roles = [];
        if (Schema::hasTable('model_has_roles')) {
            $roles = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_id', $u->id)
                ->pluck('roles.name')
                ->toArray();
        }
        if (empty($roles)) $roles = ['user'];

        return response()->json([
            'data' => [
                'id' => (int)$u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone ?? null,
                'avatar_url' => $this->formatAvatarUrl($u->avatar_path ?? null),
                'status' => $u->status ?? 'active',
                'status_reason' => $u->status_reason ?? null,
                'status_changed_at' => $u->status_changed_at ?? null,
                'email_verified_at' => $u->email_verified_at ?? null,
                'two_factor_enabled' => false,
                'roles' => $roles,
                'has_creator_profile' => (bool)DB::table('creator_profiles')->where('user_id', $u->id)->exists(),
                'warning_count' => 0,
                'last_login_at' => $u->last_login_at ?? $u->updated_at ?? $u->created_at,
                'created_at' => $u->created_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    private function formatAvatarUrl(?string $avatarPath): ?string
    {
        if (!$avatarPath) return null;
        if (str_starts_with($avatarPath, 'http://') || str_starts_with($avatarPath, 'https://')) {
            return $avatarPath;
        }
        $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
        if (!str_starts_with($avatarPath, '/storage/') && !str_starts_with($avatarPath, 'storage/')) {
            $avatarPath = '/storage/' . ltrim($avatarPath, '/');
        }
        return $baseUrl . '/' . ltrim($avatarPath, '/');
    }

    public function suspend(Request $request, $id)
    {
        $reason = $request->input('reason', 'Suspended by administrator');
        if (Schema::hasColumn('users', 'status')) {
            DB::table('users')->where('id', $id)->update([
                'status' => 'suspended',
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $this->show($id);
    }

    public function ban(Request $request, $id)
    {
        $reason = $request->input('reason', 'Banned by administrator');
        if (Schema::hasColumn('users', 'status')) {
            DB::table('users')->where('id', $id)->update([
                'status' => 'banned',
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $this->show($id);
    }

    public function reactivate($id)
    {
        if (Schema::hasColumn('users', 'status')) {
            DB::table('users')->where('id', $id)->update([
                'status' => 'active',
                'status_reason' => null,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $this->show($id);
    }

    public function resetPassword($id)
    {
        return response()->json([
            'data' => ['sent' => true],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function sessions($id)
    {
        return response()->json([
            'data' => [
                [
                    'id' => 'sess_' . $id,
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent() ?? 'Mozilla/5.0 Browser',
                    'last_active_at' => now()->toIso8601String(),
                    'is_current_device' => true,
                ]
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function wallet($userId)
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $amountPaise = (int)(round((float)($user->amount ?? 0) * 100));
        $earningsPaise = (int)(round((float)($user->earnings ?? 0) * 100));
        $actualEarningPaise = (int)(round((float)($user->actualearning ?? 0) * 100));

        return response()->json([
            'data' => [
                'id' => (int)$user->id,
                'type' => 'user',
                'currency' => 'INR',
                'available_balance_minor_units' => $amountPaise,
                'pending_balance_minor_units' => $earningsPaise,
                'withdrawable_balance_minor_units' => $actualEarningPaise,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function walletTransactions(Request $request, $userId)
    {
        $txs = [];
        if (Schema::hasTable('wallet_transactions')) {
            $txs = DB::table('wallet_transactions')
                ->where('created_by', $userId)
                ->orWhere('wallet_id', $userId)
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get();
        }

        return response()->json([
            'data' => $txs,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($txs),
                ]
            ],
            'errors' => null,
        ]);
    }

    public function adjustWallet(Request $request, $userId)
    {
        $direction = $request->input('direction', 'credit');
        $amountMinorUnits = (int)$request->input('amount_minor_units', 0);
        $amountRupees = $amountMinorUnits / 100;

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $currentAmount = (float)($user->amount ?? 0);
        $newAmount = $direction === 'credit'
            ? $currentAmount + $amountRupees
            : max(0, $currentAmount - $amountRupees);

        DB::table('users')->where('id', $userId)->update([
            'amount' => $newAmount,
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => rand(1000, 9999),
                'wallet_id' => (int)$userId,
                'type' => $direction,
                'category' => 'adjustment',
                'amount_minor_units' => $amountMinorUnits,
                'status' => 'cleared',
                'description' => $request->input('reason', 'Admin wallet adjustment'),
                'created_at' => now()->toIso8601String(),
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
