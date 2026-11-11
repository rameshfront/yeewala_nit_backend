<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
                'last_login_ip' => $u->last_login_ip ?? null,
                'created_at' => $u->created_at,
                'amount' => number_format((float)($u->amount ?? 0), 2, '.', ''),
                'earnings' => number_format((float)($u->earnings ?? 0), 2, '.', ''),
                'actualearning' => number_format((float)($u->actualearning ?? 0), 2, '.', ''),
                'available_balance_minor_units' => (int)round((float)($u->amount ?? 0) * 100),
                'pending_balance_minor_units' => (int)round((float)($u->earnings ?? 0) * 100),
                'withdrawable_balance_minor_units' => (int)round((float)($u->actualearning ?? 0) * 100),
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                    'total' => count($formatted),
                ],
                'summary' => [
                    'total_available_minor_units' => (int)round((float)$users->sum('amount') * 100),
                    'total_pending_minor_units' => (int)round((float)$users->sum('earnings') * 100),
                    'total_withdrawable_minor_units' => (int)round((float)$users->sum('actualearning') * 100),
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
                'last_login_ip' => $u->last_login_ip ?? null,
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
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found']],
            ], 404);
        }

        $reason = $request->input('reason') ?: 'Suspended by administrator';

        DB::table('users')->where('id', $id)->update([
            'status' => 'suspended',
            'status_reason' => $reason,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ]);

        // Revoke active sessions and tokens
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $id)->delete();
        }
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        }

        // Audit log
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()?->id,
                'action' => 'user_suspended',
                'subject_type' => 'user',
                'subject_id' => $id,
                'before_state' => json_encode(['status' => $user->status ?? 'active']),
                'after_state' => json_encode(['status' => 'suspended', 'reason' => $reason, 'user_email' => $user->email]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->show($id);
    }

    public function ban(Request $request, $id)
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found']],
            ], 404);
        }

        $reason = $request->input('reason') ?: 'Banned by administrator';

        DB::table('users')->where('id', $id)->update([
            'status' => 'banned',
            'status_reason' => $reason,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ]);

        // Revoke active sessions and tokens
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $id)->delete();
        }
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        }

        // Audit log
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()?->id,
                'action' => 'user_banned',
                'subject_type' => 'user',
                'subject_id' => $id,
                'before_state' => json_encode(['status' => $user->status ?? 'active']),
                'after_state' => json_encode(['status' => 'banned', 'reason' => $reason, 'user_email' => $user->email]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->show($id);
    }

    public function reactivate(Request $request, $id)
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found']],
            ], 404);
        }

        DB::table('users')->where('id', $id)->update([
            'status' => 'active',
            'status_reason' => null,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ]);

        // Audit log
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()?->id,
                'action' => 'user_reactivated',
                'subject_type' => 'user',
                'subject_id' => $id,
                'before_state' => json_encode(['status' => $user->status ?? 'unknown']),
                'after_state' => json_encode(['status' => 'active', 'user_email' => $user->email]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->show($id);
    }

    public function resetPassword(Request $request, $id)
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found']],
            ], 404);
        }

        $rawToken = Str::random(60);

        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($rawToken),
                    'created_at' => now(),
                ]
            );
        }

        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $rawToken . '&email=' . urlencode($user->email);

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->user()?->id,
                'action' => 'password_reset_generated',
                'subject_type' => 'user',
                'subject_id' => $id,
                'before_state' => null,
                'after_state' => json_encode(['user_email' => $user->email, 'reset_url' => $resetUrl]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'sent' => true,
                'reset_url' => $resetUrl,
                'token' => $rawToken,
                'email' => $user->email,
                'user_name' => $user->name,
                'message' => 'Password reset link generated successfully.',
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $currentAdmin = $request->user();
        if ($currentAdmin && (int)$currentAdmin->id === (int)$id) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'You cannot delete your own admin account.']],
            ], 400);
        }

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'User not found']],
            ], 404);
        }

        $reason = $request->input('reason') ?: 'Deleted by administrator';

        // Audit log BEFORE deleting so actor and subject details are recorded
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $currentAdmin?->id,
                'action' => 'user_deleted',
                'subject_type' => 'user',
                'subject_id' => $id,
                'before_state' => json_encode([
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? null,
                    'status' => $user->status ?? 'active',
                ]),
                'after_state' => json_encode([
                    'deleted' => true,
                    'reason' => $reason,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clean up user tokens & sessions
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $id)->delete();
        }
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->where('tokenable_id', $id)
                ->delete();
        }
        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }
        if (Schema::hasTable('model_has_roles')) {
            DB::table('model_has_roles')
                ->where('model_type', \App\Models\User::class)
                ->where('model_id', $id)
                ->delete();
        }

        // Delete user (cascades or set null on foreign keys)
        DB::table('users')->where('id', $id)->delete();

        return response()->json([
            'data' => [
                'id' => (int)$id,
                'deleted' => true,
                'message' => "User '{$user->name}' was deleted successfully.",
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function sessions($id)
    {
        $sessions = [];
        if (Schema::hasTable('sessions')) {
            $dbSessions = DB::table('sessions')
                ->where('user_id', $id)
                ->orderBy('last_activity', 'desc')
                ->get();

            foreach ($dbSessions as $s) {
                $sessions[] = [
                    'id' => (string)$s->id,
                    'ip_address' => $s->ip_address ?: '127.0.0.1',
                    'user_agent' => $s->user_agent ?: 'Web Browser',
                    'last_active_at' => date('c', $s->last_activity),
                    'is_current_device' => false,
                ];
            }
        }

        return response()->json([
            'data' => $sessions,
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function forceLogout($id)
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $id)->delete();
        }
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        }

        return response()->json([
            'data' => true,
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
