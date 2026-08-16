<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\VideoPlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    /**
     * Play analytics for the admin dashboard: total lifetime views, per-day
     * counts for the last 30 days, and per-week counts for the last 12 weeks.
     */
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        $daily = $this->seriesCounts(
            $this->playsGroupedBy(
                $this->playsSince($today->copy()->subDays(29)),
                fn (Carbon $playedAt) => $playedAt->toDateString(),
            ),
            fn (Carbon $day) => $day->toDateString(),
            $today->copy()->subDays(29),
            30,
            fn (Carbon $day) => $day->addDay(),
        );

        $weekly = $this->seriesCounts(
            $this->playsGroupedBy(
                $this->playsSince($today->copy()->subWeeks(11)->startOfWeek()),
                fn (Carbon $playedAt) => $playedAt->isoWeekYear().$this->padIsoWeek((int) $playedAt->isoWeek()),
            ),
            fn (Carbon $day) => (string) $day->isoWeekYear().$this->padIsoWeek((int) $day->isoWeek()),
            $today->copy()->subWeeks(11)->startOfWeek(),
            12,
            fn (Carbon $day) => $day->addWeek(),
        );

        $topVideos = Video::query()
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'title' => $video->title,
                'views' => (int) $video->views,
                'plays' => (int) $video->plays_count,
            ]);

        return response()->json([
            'data' => [
                'totals' => [
                    'views' => (int) Video::query()->sum('views'),
                    'plays' => (int) VideoPlay::query()->count(),
                    'videos' => (int) Video::query()->count(),
                ],
                'daily' => $daily,
                'weekly' => $weekly,
                'top_videos' => $topVideos,
            ],
        ]);
    }

    private function playsSince(Carbon $since): Collection
    {
        return VideoPlay::query()
            ->where('played_at', '>=', $since)
            ->pluck('played_at')
            ->map(fn ($playedAt) => Carbon::parse($playedAt));
    }

    /**
     * @return Collection<string, int> bucket label => play count
     */
    private function playsGroupedBy(Collection $plays, callable $bucketOf): Collection
    {
        return $plays->countBy(fn (Carbon $playedAt) => $bucketOf($playedAt));
    }

    /**
     * Zero-fill a series across consecutive buckets so charts render without gaps.
     *
     * @param  Collection<string, int>  $counts
     * @param  callable(Carbon): string  $bucketOf  bucket label for a given date
     * @param  callable(Carbon): Carbon  $next  advance to the following bucket
     * @return array<int, array{label: string, plays: int}>
     */
    private function seriesCounts(Collection $counts, callable $bucketOf, Carbon $start, int $steps, callable $next): array
    {
        $out = [];
        $cursor = $start->copy();

        for ($i = 0; $i < $steps; $i++) {
            $label = $bucketOf($cursor);
            $out[] = ['label' => $label, 'plays' => $counts->get($label, 0)];
            $cursor = $next($cursor);
        }

        return $out;
    }

    private function padIsoWeek(int $week): string
    {
        return str_pad((string) $week, 2, '0', STR_PAD_LEFT);
    }
}
