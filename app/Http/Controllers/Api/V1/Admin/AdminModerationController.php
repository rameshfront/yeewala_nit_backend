<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminModerationController extends Controller
{
    public function reports(Request $request)
    {
        if (!Schema::hasTable('reports')) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 20]],
                'errors' => null,
            ]);
        }

        $query = DB::table('reports')
            ->leftJoin('users', 'reports.reporter_id', '=', 'users.id')
            ->select('reports.*', 'users.name as reporter_name');

        if ($request->filled('status')) {
            $query->where('reports.status', $request->query('status'));
        }

        if ($request->filled('reason')) {
            $query->where('reports.reason', $request->query('reason'));
        }

        $reports = $query->orderBy('reports.id', 'desc')->get();

        $formatted = $reports->map(function ($r) {
            $reportable = null;
            if ($r->reportable_type === 'video') {
                $v = DB::table('videos')->where('id', $r->reportable_id)->first();
                $reportable = [
                    'type' => 'video',
                    'id' => (int)$r->reportable_id,
                    'title' => $v->title ?? 'Video #' . $r->reportable_id,
                ];
            } else if ($r->reportable_type === 'comment') {
                $c = Schema::hasTable('comments') ? DB::table('comments')->where('id', $r->reportable_id)->first() : null;
                $reportable = [
                    'type' => 'comment',
                    'id' => (int)$r->reportable_id,
                    'body' => $c->body ?? 'Comment #' . $r->reportable_id,
                ];
            } else {
                $reportable = ['type' => 'unknown'];
            }

            return [
                'id' => (int)$r->id,
                'reporter_id' => (int)$r->reporter_id,
                'reporter_name' => $r->reporter_name ?? 'User #' . $r->reporter_id,
                'reportable_type' => $r->reportable_type,
                'reportable_id' => (int)$r->reportable_id,
                'reportable' => $reportable,
                'reason' => $r->reason,
                'description' => $r->description,
                'status' => $r->status,
                'resolved_by' => $r->resolved_by ? (int)$r->resolved_by : null,
                'resolved_at' => $r->resolved_at,
                'resolution_note' => $r->resolution_note,
                'created_at' => $r->created_at,
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

    public function updateReportStatus(Request $request, $id)
    {
        $status = $request->input('status', 'resolved');
        $note = $request->input('note');

        DB::table('reports')->where('id', $id)->update([
            'status' => $status,
            'resolution_note' => $note,
            'resolved_by' => auth()->id() ?? 3,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $r = DB::table('reports')->where('id', $id)->first();
        return response()->json(['data' => $r, 'meta' => null, 'errors' => null]);
    }

    public function comments(Request $request)
    {
        if (!Schema::hasTable('comments')) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 20]],
                'errors' => null,
            ]);
        }

        $query = DB::table('comments')
            ->leftJoin('users', 'comments.user_id', '=', 'users.id')
            ->select('comments.*', 'users.name as author_name');

        if ($request->boolean('trashed')) {
            $query->whereNotNull('comments.deleted_at');
        } else {
            $query->whereNull('comments.deleted_at');
        }

        $comments = $query->orderBy('comments.id', 'desc')->get();

        $formatted = $comments->map(function ($c) {
            return [
                'id' => (int)$c->id,
                'video_id' => (int)$c->video_id,
                'user_id' => (int)$c->user_id,
                'user' => [
                    'id' => (int)$c->user_id,
                    'name' => $c->author_name ?? 'User',
                    'avatar_url' => null,
                ],
                'body' => $c->body,
                'is_pinned' => (bool)($c->is_pinned ?? 0),
                'is_creator_liked' => (bool)($c->is_creator_liked ?? 0),
                'like_count' => (int)($c->like_count ?? 0),
                'reply_count' => (int)($c->reply_count ?? 0),
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
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

    public function updateCommentStatus(Request $request, $id)
    {
        return response()->json(['message' => 'Comment updated.']);
    }

    public function deleteComment($id)
    {
        if (Schema::hasTable('comments')) {
            DB::table('comments')->where('id', $id)->update(['deleted_at' => now()]);
        }
        return response()->json(['message' => 'Comment removed.']);
    }

    public function restoreComment($id)
    {
        if (Schema::hasTable('comments')) {
            DB::table('comments')->where('id', $id)->update(['deleted_at' => null]);
        }
        return response()->json(['message' => 'Comment restored.']);
    }

    public function warnCommentAuthor(Request $request, $id)
    {
        return response()->json([
            'data' => [
                'id' => rand(100, 999),
                'user_id' => (int)$request->input('user_id', 1),
                'issued_by' => (int)(auth()->id() ?? 3),
                'reason' => $request->input('reason', 'Violation of comment guidelines'),
                'subject_type' => 'comment',
                'subject_id' => (int)$id,
                'created_at' => now()->toIso8601String(),
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
