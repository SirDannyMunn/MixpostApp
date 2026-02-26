<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SocialAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $accountIds = SocialAccount::where('organization_id', $organization->id)->pluck('id');
        $from = $request->input('from');
        $to = $request->input('to');
        $q = SocialAnalytics::whereIn('social_account_id', $accountIds);
        if ($from) $q->where('date', '>=', $from);
        if ($to) $q->where('date', '<=', $to);

        $totals = $q->selectRaw(
            'SUM(followers_count) as followers, SUM(likes_count) as likes, SUM(comments_count) as comments, SUM(shares_count) as shares, SUM(views_count) as views, SUM(impressions_count) as impressions'
        )->first();

        $posts = ScheduledPost::where('organization_id', $organization->id)
            ->when($from, fn($qq) => $qq->where('created_at', '>=', $from))
            ->when($to, fn($qq) => $qq->where('created_at', '<=', $to))
            ->count();

        return response()->json([
            'totals' => $totals,
            'accounts' => $accountIds->count(),
            'posts' => $posts,
        ]);
    }

    public function account(Request $request, $id)
    {
        $account = SocialAccount::findOrFail($id);
        $organization = $request->attributes->get('organization');
        if ($account->organization_id !== $organization->id) {
            return response()->json(['message' => 'Account not in organization'], 403);
        }
        $from = $request->input('from');
        $to = $request->input('to');
        $q = SocialAnalytics::where('social_account_id', $account->id);
        if ($from) $q->where('date', '>=', $from);
        if ($to) $q->where('date', '<=', $to);
        $series = $q->orderBy('date')->get(['date','followers_count','likes_count','comments_count','shares_count','views_count','impressions_count','engagement_rate']);
        return response()->json(['account' => $account, 'series' => $series]);
    }

    public function topContent(Request $request)
    {
        $organization = $request->attributes->get('organization');
        $metric = $request->input('metric', 'likes_count');
        $from = $request->input('from');
        $to = $request->input('to');

        $accountIds = SocialAccount::where('organization_id', $organization->id)->pluck('id');
        $q = SocialAnalytics::whereIn('social_account_id', $accountIds)
            ->select('social_account_id', DB::raw('SUM('.DB::getPdo()->quote($metric).' ) as total_metric'));
        // The above quote approach is not ideal; instead validate metric
        $allowed = ['followers_count','likes_count','comments_count','shares_count','views_count','impressions_count'];
        if (!in_array($metric, $allowed)) $metric = 'likes_count';
        $q = SocialAnalytics::whereIn('social_account_id', $accountIds)
            ->select('social_account_id', DB::raw('SUM('.$metric.') as total_metric'));
        if ($from) $q->where('date', '>=', $from);
        if ($to) $q->where('date', '<=', $to);
        $rows = $q->groupBy('social_account_id')->orderByDesc('total_metric')->limit(10)->get();
        return response()->json($rows);
    }
}
