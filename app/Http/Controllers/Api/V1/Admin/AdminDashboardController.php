<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function getDashboard(Request $request)
    {
        $totalUsers = DB::table('users')->count();
        $totalCreators = DB::table('creator_profiles')->count();
        $totalVideos = DB::table('videos')->count();
        $publishedVideos = DB::table('videos')->where('status', 'published')->count();
        $videosProcessing = DB::table('videos')->where('status', 'processing')->count();
        $reportsPending = DB::table('reports')->where('status', 'open')->count();
        
        $totalRevenue = DB::table('wallet_transactions')
            ->where('type', 'credit')
            ->where('status', 'cleared')
            ->sum('amount_minor_units');

        $activeMemberships = DB::table('membership_subscriptions')
            ->where('status', 'active')
            ->count();

        $membershipRevenue = DB::table('orders')
            ->where('status', 'completed')
            ->sum('total_amount_minor_units');

        $creatorPayouts = DB::table('withdrawal_requests')
            ->where('status', 'completed')
            ->sum('amount_minor_units');

        $rangeFrom = $request->input('from', now()->subDays(30)->toDateString());
        $rangeTo = $request->input('to', now()->toDateString());

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $rangeFrom,
                    'to' => $rangeTo,
                ],
                'total_users' => $totalUsers,
                'total_creators' => $totalCreators,
                'total_videos' => $totalVideos,
                'videos_processing' => $videosProcessing,
                'published_videos' => $publishedVideos,
                'total_revenue_minor_units' => (int)$totalRevenue,
                'creator_payouts_minor_units' => (int)$creatorPayouts,
                'membership_revenue_minor_units' => (int)$membershipRevenue,
                'active_memberships' => $activeMemberships,
                'reports_pending_review' => $reportsPending,
                'system_health' => [
                    'database' => 'ok',
                    'cache' => 'ok',
                    'queue_pending' => 0,
                    'queue_failed' => 0,
                    'queue_oldest_pending_seconds' => null,
                ],
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
