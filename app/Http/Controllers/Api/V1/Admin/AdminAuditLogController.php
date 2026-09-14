<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAuditLogController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('audit_logs')) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 20]],
                'errors' => null,
            ]);
        }

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.actor_user_id', '=', 'users.id')
            ->select(
                'audit_logs.id',
                'audit_logs.actor_user_id',
                'users.name as actor_name',
                'audit_logs.action',
                'audit_logs.subject_type',
                'audit_logs.subject_id',
                'audit_logs.before_state',
                'audit_logs.after_state',
                'audit_logs.ip_address',
                'audit_logs.created_at'
            );

        if ($request->filled('action')) {
            $query->where('audit_logs.action', 'like', '%' . $request->query('action') . '%');
        }

        if ($request->filled('subject_type')) {
            $query->where('audit_logs.subject_type', $request->query('subject_type'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('audit_logs.actor_user_id', $request->query('actor_user_id'));
        }

        if ($request->filled('from')) {
            $query->where('audit_logs.created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->where('audit_logs.created_at', '<=', $request->query('to'));
        }

        $logs = $query->orderBy('audit_logs.created_at', 'desc')
            ->limit(50)
            ->get();

        $formatted = $logs->map(function ($item) {
            $before = is_string($item->before_state) ? json_decode($item->before_state, true) : $item->before_state;
            $after = is_string($item->after_state) ? json_decode($item->after_state, true) : $item->after_state;

            return [
                'id' => (int)$item->id,
                'actor_user_id' => $item->actor_user_id ? (int)$item->actor_user_id : null,
                'actor_name' => $item->actor_name ?? ($item->actor_user_id ? 'User #' . $item->actor_user_id : 'System'),
                'action' => $item->action ?? 'system.event',
                'subject_type' => $item->subject_type ?? 'system',
                'subject_id' => (int)($item->subject_id ?? 0),
                'before_state' => is_array($before) ? $before : null,
                'after_state' => is_array($after) ? $after : null,
                'ip_address' => $item->ip_address,
                'created_at' => $item->created_at,
            ];
        });

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
}
